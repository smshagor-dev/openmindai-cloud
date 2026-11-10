<?php

namespace Tests\Feature;

use App\Models\StudioJob;
use App\Models\User;
use App\Services\Studio\FreeMesh;
use App\Services\Studio\FreeMeshClient;
use App\Services\Studio\FreeMeshDownload;
use App\Services\Studio\FreeMeshException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudioFreeMeshTest extends TestCase
{
    use RefreshDatabase;

    private function actor(): array
    {
        config(['studio.free_mesh.enabled' => true, 'studio.free_mesh.token' => 'private-hf-token', 'studio.hosted_mesh.key' => 'paid-key-must-not-be-used']);
        Storage::fake('local');
        Http::preventStrayRequests();
        $user = User::create(['name' => 'Free mesh', 'email' => uniqid().'@example.com', 'password' => 'SyntheticPassword123', 'is_active' => true]);
        $user->forceFill(['email_verified_at' => now()])->save();
        Sanctum::actingAs($user, ['user']);
        $upload = DB::table('studio_uploads')->insertGetId(['user_id' => $user->id, 'name' => 'reference.png', 'mime' => 'image/png', 'private_path' => 'studio/test', 'bytes' => 8, 'sha256' => str_repeat('a', 64), 'image_preview' => 'data:image/png;base64,aW1hZ2U=', 'created_at' => now(), 'updated_at' => now()]);

        return [$user, $upload];
    }

    private function submit(int $upload, string $key = 'free-one')
    {
        return $this->postJson('/api/v1/3d-studio/jobs/image-model', ['engine' => FreeMesh::ENGINE, 'upload_id' => $upload, 'request_key' => $key]);
    }

    private function glb(): string
    {
        $binary = pack('g*', 0, 0, 0, 1, 0, 0, 0, 1, 0, 0, 0, 1).pack('v*', 0, 1, 2, 0, 3, 1, 0, 2, 3, 1, 3, 2);
        $json = json_encode(['asset' => ['version' => '2.0'], 'scene' => 0, 'scenes' => [['nodes' => [0]]], 'nodes' => [['mesh' => 0]], 'meshes' => [['primitives' => [['attributes' => ['POSITION' => 0], 'indices' => 1]]]], 'buffers' => [['byteLength' => strlen($binary)]], 'bufferViews' => [['buffer' => 0, 'byteOffset' => 0, 'byteLength' => 48], ['buffer' => 0, 'byteOffset' => 48, 'byteLength' => 24]], 'accessors' => [['bufferView' => 0, 'componentType' => 5126, 'count' => 4, 'type' => 'VEC3', 'min' => [0, 0, 0], 'max' => [1, 1, 1]], ['bufferView' => 1, 'componentType' => 5123, 'count' => 12, 'type' => 'SCALAR']]]);
        $json = str_pad($json, (int) (ceil(strlen($json) / 4) * 4), ' ');

        return 'glTF'.pack('VV', 2, 28 + strlen($json) + strlen($binary)).pack('V', strlen($json)).'JSON'.$json.pack('V', strlen($binary))."BIN\0".$binary;
    }

    public function test_free_is_preferred_and_web_requests_only_enqueue_idempotently(): void
    {
        [$user, $upload] = $this->actor();
        $this->postJson('/api/v1/3d-studio/route', ['mode' => 'auto', 'prompt' => 'Make full 3D', 'upload_ids' => [$upload]])->assertOk()->assertJsonPath('engine', FreeMesh::ENGINE);
        $first = $this->submit($upload)->assertAccepted()->json('id');
        $this->submit($upload)->assertAccepted()->assertJsonPath('id', $first);
        $this->getJson('/api/v1/3d-studio/jobs/'.$first)->assertOk()->assertJsonPath('status', 'queued');
        $this->assertSame(1, StudioJob::count());
        $this->assertSame(0, DB::table('jobs')->count());
        Http::assertNothingSent();
        $this->submit($upload, 'second')->assertStatus(429)->assertHeader('Retry-After');
        Http::assertNothingSent();
        $other = User::create(['name' => 'Other', 'email' => uniqid().'@example.com', 'password' => 'SyntheticPassword123']);
        Sanctum::actingAs($other, ['user']);
        $this->getJson('/api/v1/3d-studio/jobs/'.$first)->assertNotFound();
        $this->submit($upload, 'stolen')->assertNotFound();
        Sanctum::actingAs($user, ['user']);
        $this->postJson('/api/v1/3d-studio/jobs/'.$first.'/cancel')->assertOk()->assertJsonPath('status', 'cancelled');
        $this->submit($upload, 'second')->assertStatus(429)->assertHeader('Retry-After', 3600);
    }

    public function test_rate_limits_survive_cache_clear_and_replays_do_not_consume_slots(): void
    {
        [, $upload] = $this->actor();
        config(['studio.free_mesh.server_daily' => 10]);
        $first = $this->submit($upload)->assertAccepted()->json('id');
        $this->postJson('/api/v1/3d-studio/jobs/'.$first.'/cancel')->assertOk();
        Cache::flush();
        $this->submit($upload, 'too-soon')->assertStatus(429);
        $this->travel(61)->minutes();
        $second = $this->submit($upload, 'second')->assertAccepted()->json('id');
        $this->postJson('/api/v1/3d-studio/jobs/'.$second.'/cancel')->assertOk();
        $this->travel(61)->minutes();
        $this->submit($upload, 'third')->assertStatus(429);
        $this->submit($upload)->assertAccepted()->assertJsonPath('id', $first);
        $this->assertSame(2, StudioJob::count());
        Http::assertNothingSent();
    }

    public function test_global_admission_lock_rejects_racing_submissions_before_network(): void
    {
        [, $upload] = $this->actor();
        $lock = Cache::lock('studio:free-admission', 15);
        $this->assertTrue($lock->get());
        $this->submit($upload)->assertStatus(429);
        $lock->release();
        $this->assertSame(0, StudioJob::count());
        Http::assertNothingSent();
    }

    public function test_cron_saves_a_valid_private_glb_without_billing_or_replaying(): void
    {
        [$user, $upload] = $this->actor();
        $client = $this->mock(FreeMeshClient::class);
        $client->shouldReceive('generate')->once()->andReturn('https://microsoft-trellis-2.hf.space/gradio_api/file=/tmp/gradio/result.glb');
        $bytes = $this->glb();
        $this->mock(FreeMeshDownload::class)->shouldReceive('get')->once()->andReturn($bytes);
        $id = $this->submit($upload)->assertAccepted()->json('id');
        $this->artisan('studio:free-run')->assertExitCode(0);
        $job = $this->getJson('/api/v1/3d-studio/jobs/'.$id)->assertOk()->assertJsonPath('status', 'completed')->json();
        $this->assertStringNotContainsString('private-hf-token', json_encode($job));
        $asset = $job['result']['asset_id'];
        $this->assertSame($bytes, base64_decode($this->getJson('/api/v1/3d-studio/assets/'.$asset.'/data')->assertOk()->json('data')));
        $this->artisan('studio:free-run')->assertExitCode(0);
        $this->assertSame(1, DB::table('studio_assets')->count());
        $this->assertSame(0, DB::table('api_credit_usage')->count());
        Http::assertNothingSent();
    }

    public function test_upstream_quota_failure_cools_down_and_never_calls_a_paid_provider(): void
    {
        [, $upload] = $this->actor();
        $client = $this->mock(FreeMeshClient::class);
        $client->shouldReceive('generate')->once()->andThrow(new FreeMeshException('free_provider_quota'));
        $client->shouldReceive('cancel')->once()->andReturn(false);
        $id = $this->submit($upload)->assertAccepted()->json('id');
        $this->artisan('studio:free-run')->assertExitCode(0);
        $this->getJson('/api/v1/3d-studio/jobs/'.$id)->assertOk()->assertJsonPath('status', 'failed')->assertJsonPath('error_code', 'free_provider_quota');
        $this->assertTrue(Cache::has('studio:free-provider-cooldown'));
        $this->artisan('studio:free-run')->assertExitCode(0);
        $this->assertSame(0, DB::table('studio_assets')->count());
        Http::assertNothingSent();
    }

    public function test_crashed_sessions_are_failed_instead_of_repeating_inference(): void
    {
        [, $upload] = $this->actor();
        $id = $this->submit($upload)->assertAccepted()->json('id');
        StudioJob::findOrFail($id)->update(['status' => 'running', 'lease_until' => now()->subSecond()]);
        $this->artisan('studio:free-run')->assertExitCode(0);
        $this->getJson('/api/v1/3d-studio/jobs/'.$id)->assertOk()->assertJsonPath('status', 'failed')->assertJsonPath('error_code', 'free_submission_unknown');
        Http::assertNothingSent();
    }

    public function test_gradio_keeps_one_session_for_preprocessing_generation_and_latent_export(): void
    {
        $this->actor();
        $origin = FreeMeshClient::ORIGIN;
        $remoteImage = ['path' => '/tmp/gradio/prepared/image.png', 'meta' => ['_type' => 'gradio.FileData']];
        $sse = fn ($id, $data) => 'data: '.json_encode(['msg' => 'process_completed', 'event_id' => $id, 'success' => true, 'output' => ['data' => $data]])."\n\n";
        $url = $origin.'/gradio_api/file=/tmp/gradio/result/model.glb';
        Http::fake([
            $origin.'/config' => Http::response(['dependencies' => [['id' => 4, 'api_name' => 'preprocess_image', 'inputs' => [4]], ['id' => 7, 'api_name' => 'image_to_3d', 'inputs' => range(1, 16)], ['id' => 9, 'api_name' => 'extract_glb', 'inputs' => [45, 8, 9]]]]),
            $origin.'/gradio_api/upload' => Http::response(['/tmp/gradio/upload/image.png']),
            $origin.'/gradio_api/queue/join' => Http::sequence()->push(['event_id' => 'pre'])->push(['event_id' => 'gen'])->push(['event_id' => 'export']),
            $origin.'/gradio_api/queue/data*' => Http::sequence()->push($sse('pre', [$remoteImage]))->push($sse('gen', [null, '<div>Provider preview must not be rendered</div>']))->push($sse('export', [['url' => $url], ['url' => $url]])),
        ]);
        $phases = [];
        $result = app(FreeMeshClient::class)->generate('image', function ($phase, $remote) use (&$phases) {
            $phases[] = [$phase, $remote];
        }, fn () => null);
        $this->assertSame($url, $result);
        $this->assertSame(1, count(array_unique(array_column(array_column($phases, 1), 'session'))));
        $this->assertSame(['preprocessing', 'generating', 'exporting'], array_values(array_unique(array_column($phases, 0))));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/queue/join') && $r['fn_index'] === 9 && $r['data'] === [null, 100000, 1024]);
        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer private-hf-token'));
        $this->assertCount(8, Http::recorded());
    }

    public function test_changed_provider_schema_is_rejected_before_upload_or_gpu_submission(): void
    {
        $this->actor();
        Http::fake([FreeMeshClient::ORIGIN.'/config' => Http::response(['dependencies' => []])]);
        try {
            app(FreeMeshClient::class)->generate('image', fn () => null, fn () => null);
            $this->fail('Changed provider schema accepted');
        } catch (FreeMeshException $e) {
            $this->assertSame('free_provider_contract_changed', $e->reason);
        }
        Http::assertSentCount(1);
    }

    public function test_artifacts_cannot_redirect_downloads_to_arbitrary_hosts(): void
    {
        $this->actor();
        foreach (['https://evil.test/model.glb', 'https://microsoft-trellis-2.hf.space.evil.test/gradio_api/file=x.glb', 'https://microsoft-trellis-2.hf.space/gradio_api/file=x.txt', 'http://microsoft-trellis-2.hf.space/gradio_api/file=x.glb', 'https://microsoft-trellis-2.hf.space/gradio_api/file=x.glb?redirect=1'] as $url) {
            try {
                app(FreeMeshDownload::class)->get($url);
                $this->fail('Unsafe artifact URL accepted');
            } catch (\UnexpectedValueException) {
                $this->assertTrue(true);
            }
        }
        Http::assertNothingSent();
    }

    public function test_daily_server_cap_applies_across_different_accounts(): void
    {
        [, $upload] = $this->actor();
        config(['studio.free_mesh.server_daily' => 1]);
        $id = $this->submit($upload)->assertAccepted()->json('id');
        $this->postJson('/api/v1/3d-studio/jobs/'.$id.'/cancel')->assertOk();
        [, $otherUpload] = $this->actor();
        $this->submit($otherUpload, 'other')->assertStatus(429)->assertJsonPath('message', 'The server daily free 3D limit has been reached. Try tomorrow.');
        $this->assertSame(1, StudioJob::count());
        Http::assertNothingSent();
    }

    public function test_corrupt_provider_mesh_is_never_published(): void
    {
        [, $upload] = $this->actor();
        $client = $this->mock(FreeMeshClient::class);
        $client->shouldReceive('generate')->once()->andReturn('https://microsoft-trellis-2.hf.space/gradio_api/file=/tmp/gradio/model.glb');
        $client->shouldReceive('cancel')->once()->andReturn(false);
        $this->mock(FreeMeshDownload::class)->shouldReceive('get')->once()->andReturn('not a GLB');
        $id = $this->submit($upload)->assertAccepted()->json('id');
        $this->artisan('studio:free-run')->assertExitCode(0);
        $this->getJson('/api/v1/3d-studio/jobs/'.$id)->assertOk()->assertJsonPath('status', 'failed')->assertJsonPath('error_code', 'free_invalid_mesh');
        $this->assertSame(0, DB::table('studio_assets')->count());
    }

    public function test_cancellation_fences_a_late_provider_result(): void
    {
        [, $upload] = $this->actor();
        $id = $this->submit($upload)->assertAccepted()->json('id');
        $client = $this->mock(FreeMeshClient::class);
        $client->shouldReceive('generate')->once()->andReturnUsing(function () use ($id) {
            StudioJob::findOrFail($id)->update(['status' => 'cancel_requested']);

            return 'https://microsoft-trellis-2.hf.space/gradio_api/file=/tmp/gradio/model.glb';
        });
        $client->shouldReceive('cancel')->once()->andReturn(false);
        $this->mock(FreeMeshDownload::class)->shouldNotReceive('get');
        $this->artisan('studio:free-run')->assertExitCode(0);
        $this->getJson('/api/v1/3d-studio/jobs/'.$id)->assertOk()->assertJsonPath('status', 'cancelled')->assertJsonPath('result.provider_stop_confirmed', false);
        $this->assertSame(0, DB::table('studio_assets')->count());
    }
}
