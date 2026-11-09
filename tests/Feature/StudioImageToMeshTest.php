<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudioImageToMeshTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://ai.api.nvidia.com/v1/genai/microsoft/trellis';

    private function actor(bool $key = true): User
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        $user = User::create(['name' => 'Mesh', 'email' => uniqid().'@example.com', 'password' => 'SyntheticPassword123']);
        $user->forceFill(['email_verified_at' => now()])->save();
        Sanctum::actingAs($user, ['user']);
        config(['studio.image_model.custom_images' => true, 'studio.hosted_mesh.key' => '', 'studio.image_model.key' => $key ? 'test-nvidia-key' : '', 'studio.image_model.url' => self::URL]);

        return $user;
    }

    private function upload(User $user, bool $image = true): int
    {
        return DB::table('studio_uploads')->insertGetId(['user_id' => $user->id, 'name' => 'bust.png', 'mime' => $image ? 'image/png' : 'text/markdown', 'private_path' => 'studio/x.bin', 'bytes' => 10, 'sha256' => str_repeat('a', 64), 'text' => $image ? null : '# notes', 'image_preview' => $image ? 'data:image/jpeg;base64,/9j/4AAQSkZJRg==' : null, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** Minimal valid glTF 2.0 binary: header + one JSON chunk. */
    private function glb(): string
    {
        $json = str_pad('{"asset":{"version":"2.0"}}', 28, ' ');

        return 'glTF'.pack('VV', 2, 12 + 8 + strlen($json)).pack('V', strlen($json)).'JSON'.$json;
    }

    public function test_image_reference_becomes_a_private_glb_asset_and_retries_do_not_bill_twice(): void
    {
        $user = $this->actor();
        $upload = $this->upload($user);
        Http::fake([self::URL => Http::response(['artifacts' => [['base64' => base64_encode($this->glb()), 'finishReason' => 'SUCCESS', 'seed' => 0]]])]);

        $first = $this->postJson('/api/v1/3d-studio/jobs/image-model', ['upload_id' => $upload, 'request_key' => 'mesh-1'])->assertCreated()->json('asset');
        $this->assertSame('glb', $first['format']);
        $this->assertSame('mesh', $first['kind']);
        $this->assertSame('bust.glb', $first['name']);
        $data = $this->getJson('/api/v1/3d-studio/assets/'.$first['id'].'/data')->assertOk()->json();
        $this->assertSame($this->glb(), base64_decode($data['data']));
        Http::assertSent(fn (HttpRequest $request) => $request->url() === self::URL && $request['image'] === 'data:image/jpeg;base64,/9j/4AAQSkZJRg=='
            && $request->hasHeader('Authorization', 'Bearer test-nvidia-key'));

        $again = $this->postJson('/api/v1/3d-studio/jobs/image-model', ['upload_id' => $upload, 'request_key' => 'mesh-1'])->assertCreated()->json('asset');
        $this->assertSame($first['id'], $again['id']);
        Http::assertSentCount(1);
        $this->assertSame(1, DB::table('api_model_connections')->where('model_key', 'microsoft/trellis')->where('kind', 'model3d')->count());
    }

    public function test_binary_glb_and_queued_nvcf_responses_are_accepted(): void
    {
        $user = $this->actor();
        $upload = $this->upload($user);
        $request = '11111111-2222-3333-4444-555555555555';
        Http::fake([
            self::URL => Http::response('', 202, ['NVCF-REQID' => $request]),
            'https://api.nvcf.nvidia.com/v2/nvcf/pexec/status/'.$request => Http::sequence()
                ->push('', 202, ['NVCF-REQID' => $request])
                ->push($this->glb(), 200, ['Content-Type' => 'model/gltf-binary']),
        ]);

        $asset = $this->postJson('/api/v1/3d-studio/jobs/image-model', ['upload_id' => $upload, 'request_key' => 'mesh-2'])->assertCreated()->json('asset');
        $this->assertSame(strlen($this->glb()), $asset['bytes']);
        Http::assertSentCount(3);
    }

    public function test_invalid_provider_output_and_errors_save_nothing(): void
    {
        $user = $this->actor();
        $upload = $this->upload($user);
        Http::fake([self::URL => Http::sequence()
            ->push(['artifacts' => [['base64' => base64_encode('not a model'), 'finishReason' => 'SUCCESS']]])
            ->push(['artifacts' => [['base64' => '', 'finishReason' => 'CONTENT_FILTERED']]])
            ->push(['message' => 'forbidden'], 403)]);

        $this->postJson('/api/v1/3d-studio/jobs/image-model', ['upload_id' => $upload, 'request_key' => 'bad-1'])->assertStatus(502)->assertJsonFragment(['message' => 'NVIDIA returned an invalid GLB model.']);
        $this->postJson('/api/v1/3d-studio/jobs/image-model', ['upload_id' => $upload, 'request_key' => 'bad-2'])->assertStatus(502)->assertJsonFragment(['message' => 'NVIDIA did not return a 3D model (CONTENT_FILTERED).']);
        $this->postJson('/api/v1/3d-studio/jobs/image-model', ['upload_id' => $upload, 'request_key' => 'bad-3'])->assertStatus(502)->assertJsonFragment(['message' => 'The NVIDIA key cannot use image to 3D (HTTP 403).']);
        $this->assertSame(0, DB::table('studio_assets')->count());
    }

    public function test_requires_an_owned_image_reference_and_a_configured_key(): void
    {
        $user = $this->actor(key: false);
        $this->postJson('/api/v1/3d-studio/jobs/image-model', ['upload_id' => $this->upload($user), 'request_key' => 'k1'])->assertStatus(503);
        config(['studio.image_model.key' => 'test-nvidia-key']);
        $this->postJson('/api/v1/3d-studio/jobs/image-model', ['upload_id' => $this->upload($user, image: false), 'request_key' => 'k2'])->assertStatus(422);
        $other = User::create(['name' => 'Other', 'email' => uniqid().'@example.com', 'password' => 'SyntheticPassword123']);
        $this->postJson('/api/v1/3d-studio/jobs/image-model', ['upload_id' => $this->upload($other), 'request_key' => 'k3'])->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_catalog_routes_image_prompts_to_the_hosted_engine_only_with_a_key(): void
    {
        $user = $this->actor();
        $upload = $this->upload($user);
        $route = $this->postJson('/api/v1/3d-studio/route', ['mode' => 'auto', 'prompt' => 'Make this a 3D model', 'upload_ids' => [$upload]])->assertOk()->json();
        $this->assertSame(['nvidia-trellis', 'hosted_mesh'], [$route['engine'], $route['output']]);

        config(['studio.image_model.key' => '']);
        $engine = collect($this->getJson('/api/v1/3d-studio/engines')->json('data'))->firstWhere('id', 'nvidia-trellis');
        $this->assertFalse($engine['available']);
        $this->assertSame('nvidia_key_required', $engine['reason']);
    }
}
