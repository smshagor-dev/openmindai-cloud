<?php

namespace Tests\Feature;

use App\Models\StudioJob;
use App\Models\User;
use App\Services\Studio\GlbMesh;
use App\Services\Studio\HostedMesh;
use App\Services\Studio\HostedMeshDownload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudioHostedMeshTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'https://queue.fal.run/fal-ai/trellis-2';

    private const REMOTE = '11111111-2222-4333-8444-555555555555';

    private function actor(): User
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        config(['studio.hosted_mesh.key' => 'server-only-fal-key', 'studio.queue_enabled' => false, 'studio.image_model.custom_images' => false]);
        $user = User::create(['name' => 'Hosted mesh', 'email' => uniqid().'@example.com', 'password' => 'SyntheticPassword123']);
        $user->forceFill(['email_verified_at' => now()])->save();
        Sanctum::actingAs($user, ['user']);

        return $user;
    }

    private function upload(User $user): int
    {
        return DB::table('studio_uploads')->insertGetId(['user_id' => $user->id, 'name' => 'object.png', 'mime' => 'image/png', 'private_path' => 'studio/fixture.bin', 'bytes' => 10, 'sha256' => str_repeat('a', 64), 'image_preview' => 'data:image/jpeg;base64,/9j/4AAQSkZJRg==', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function glb(): string
    {
        $binary = pack('g*', 0, 0, 0, 1, 0, 0, 0, 1, 0, 0, 0, 1).pack('v*', 0, 1, 2, 0, 3, 1, 0, 2, 3, 1, 3, 2);
        $json = json_encode(['asset' => ['version' => '2.0'], 'scene' => 0, 'scenes' => [['nodes' => [0]]], 'nodes' => [['mesh' => 0]], 'meshes' => [['primitives' => [['attributes' => ['POSITION' => 0], 'indices' => 1]]]], 'buffers' => [['byteLength' => strlen($binary)]], 'bufferViews' => [['buffer' => 0, 'byteOffset' => 0, 'byteLength' => 48], ['buffer' => 0, 'byteOffset' => 48, 'byteLength' => 24]], 'accessors' => [['bufferView' => 0, 'componentType' => 5126, 'count' => 4, 'type' => 'VEC3', 'min' => [0, 0, 0], 'max' => [1, 1, 1]], ['bufferView' => 1, 'componentType' => 5123, 'count' => 12, 'type' => 'SCALAR']]]);
        $json = str_pad($json, (int) (ceil(strlen($json) / 4) * 4), ' ');

        return 'glTF'.pack('VV', 2, 28 + strlen($json) + strlen($binary)).pack('V', strlen($json)).'JSON'.$json.pack('V', strlen($binary))."BIN\0".$binary;
    }

    private function submit(int $upload, string $key = 'mesh-one'): array
    {
        return $this->postJson('/api/v1/3d-studio/jobs/image-model', ['engine' => HostedMesh::ENGINE, 'upload_id' => $upload, 'request_key' => $key])->assertAccepted()->json();
    }

    public function test_async_mesh_runs_without_a_local_worker_and_cron_saves_one_private_asset(): void
    {
        $user = $this->actor();
        $upload = $this->upload($user);
        $bytes = $this->glb();
        $this->mock(HostedMeshDownload::class)->shouldReceive('get')->once()->with('https://v3b.fal.media/files/model.glb')->andReturn($bytes);
        Http::fake([self::API => Http::response(['request_id' => self::REMOTE], 202), self::API.'/requests/'.self::REMOTE.'/status' => Http::sequence()->push(['status' => 'IN_PROGRESS'])->push(['status' => 'COMPLETED']), self::API.'/requests/'.self::REMOTE => Http::response(['model_glb' => ['url' => 'https://v3b.fal.media/files/model.glb']])]);
        $first = $this->submit($upload);
        $this->assertSame('queued', $first['status']);
        $this->assertStringNotContainsString('server-only-fal-key', json_encode($first));
        $this->assertStringNotContainsString('provider_request_id', json_encode($first));
        $again = $this->submit($upload);
        $this->assertSame($first['id'], $again['id']);
        $this->assertSame('running', $again['status']);
        $this->artisan('studio:hosted-poll')->assertExitCode(0);
        $detail = $this->getJson('/api/v1/3d-studio/jobs/'.$first['id'])->assertOk()->assertJsonPath('status', 'completed')->json();
        $asset = $detail['result']['asset_id'];
        $this->assertSame($bytes, base64_decode($this->getJson('/api/v1/3d-studio/assets/'.$asset.'/data')->assertOk()->json('data')));
        $this->assertSame(1, DB::table('studio_assets')->count());
        $this->assertSame(1, DB::table('api_credit_usage')->count());
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(1, Http::recorded(fn ($request) => $request->method() === 'POST')->count());
        Http::assertSent(fn ($request) => $request->method() === 'POST' && $request['image_url'] === 'data:image/jpeg;base64,/9j/4AAQSkZJRg==' && $request->hasHeader('Authorization', 'Key server-only-fal-key'));
    }

    public function test_missing_configuration_does_not_silently_call_a_relief_a_full_mesh(): void
    {
        $user = $this->actor();
        $upload = $this->upload($user);
        $this->postJson('/api/v1/3d-studio/route', ['mode' => 'auto', 'prompt' => 'Make a full 3D model', 'upload_ids' => [$upload]])->assertOk()->assertJsonPath('engine', HostedMesh::ENGINE);
        config(['studio.hosted_mesh.key' => '', 'studio.image_model.key' => 'generic-nvidia-key']);
        $this->postJson('/api/v1/3d-studio/route', ['mode' => 'auto', 'prompt' => 'Make a full 3D model', 'upload_ids' => [$upload]])->assertStatus(503);
        $this->postJson('/api/v1/3d-studio/route', ['mode' => 'object', 'engine' => 'browser-relief', 'prompt' => 'Make a depth relief', 'upload_ids' => [$upload]])->assertOk()->assertJsonPath('output', 'browser_relief');
        $this->postJson('/api/v1/3d-studio/jobs/image-model', ['engine' => HostedMesh::ENGINE, 'upload_id' => $upload, 'request_key' => 'missing'])->assertStatus(503);
        Http::assertNothingSent();
    }

    public function test_uncertain_submit_is_never_automatically_repeated(): void
    {
        $user = $this->actor();
        $upload = $this->upload($user);
        Http::fake(fn () => throw new ConnectionException('private provider detail'));
        $first = $this->submit($upload);
        $this->assertSame('failed', $first['status']);
        $this->assertSame('hosted_submission_unknown', $first['error_code']);
        $this->assertSame($first['id'], $this->submit($upload)['id']);
        $this->assertSame(1, StudioJob::count());
        $this->assertSame(0, DB::table('studio_assets')->count());
        $this->assertStringNotContainsString('private provider detail', json_encode($first));
    }

    public function test_ownership_request_key_conflicts_and_confirmed_queue_cancellation(): void
    {
        $user = $this->actor();
        $upload = $this->upload($user);
        Http::fake([self::API => Http::response(['request_id' => self::REMOTE], 202), self::API.'/requests/'.self::REMOTE.'/cancel' => Http::response(['status' => 'OK'])]);
        $job = $this->submit($upload);
        $this->postJson('/api/v1/3d-studio/jobs/image-model', ['engine' => HostedMesh::ENGINE, 'upload_id' => $this->upload($user), 'request_key' => 'mesh-one'])->assertConflict();
        $other = User::create(['name' => 'Other', 'email' => uniqid().'@example.com', 'password' => 'SyntheticPassword123']);
        Sanctum::actingAs($other, ['user']);
        $this->getJson('/api/v1/3d-studio/jobs/'.$job['id'])->assertNotFound();
        $this->postJson('/api/v1/3d-studio/jobs/image-model', ['engine' => HostedMesh::ENGINE, 'upload_id' => $upload, 'request_key' => 'other'])->assertNotFound();
        Sanctum::actingAs($user, ['user']);
        $this->postJson('/api/v1/3d-studio/jobs/'.$job['id'].'/cancel')->assertOk()->assertJsonPath('status', 'cancelled');
        $this->assertSame(0, DB::table('studio_assets')->count());
    }

    public function test_transient_poll_failure_keeps_the_saved_provider_request(): void
    {
        $user = $this->actor();
        $upload = $this->upload($user);
        Http::fake([self::API => Http::response(['request_id' => self::REMOTE], 202), self::API.'/requests/'.self::REMOTE.'/status' => Http::sequence()->push([], 503)->push(['status' => 'IN_PROGRESS'])]);
        $job = $this->submit($upload);
        $this->getJson('/api/v1/3d-studio/jobs/'.$job['id'])->assertOk()->assertJsonPath('status', 'queued')->assertJsonPath('error_code', 'hosted_status_unavailable');
        $this->getJson('/api/v1/3d-studio/jobs/'.$job['id'])->assertOk()->assertJsonPath('status', 'running');
        $this->assertSame(1, Http::recorded(fn ($request) => $request->method() === 'POST')->count());
    }

    public function test_a_non_mesh_response_cannot_be_saved_or_counted_as_success(): void
    {
        $user = $this->actor();
        $upload = $this->upload($user);
        $this->mock(HostedMeshDownload::class)->shouldReceive('get')->andReturn('not a GLB');
        Http::fake([self::API => Http::response(['request_id' => self::REMOTE], 202), self::API.'/requests/'.self::REMOTE.'/status' => Http::response(['status' => 'COMPLETED']), self::API.'/requests/'.self::REMOTE => Http::response(['model_glb' => ['url' => 'https://v3b.fal.media/files/model.glb']])]);
        $job = $this->submit($upload);
        $this->getJson('/api/v1/3d-studio/jobs/'.$job['id'])->assertOk()->assertJsonPath('status', 'failed')->assertJsonPath('error_code', 'hosted_invalid_mesh');
        $this->assertSame(0, DB::table('studio_assets')->count());
        $this->assertSame(0, DB::table('api_credit_usage')->count());
        app(GlbMesh::class)->validate($this->glb());
    }

    public function test_untrusted_artifact_hosts_are_rejected_before_network_access(): void
    {
        $this->actor();
        foreach (['http://v3b.fal.media/files/x.glb', 'https://127.0.0.1/x', 'https://v3b.fal.media.evil.test/x', 'https://user@v3b.fal.media/x', 'https://v3b.fal.media:444/x'] as $url) {
            try {
                app(HostedMeshDownload::class)->get($url);
                $this->fail('Unsafe URL accepted');
            } catch (\UnexpectedValueException $expected) {
                $this->assertTrue(true);
            }
        }
        Http::assertNothingSent();
    }
}
