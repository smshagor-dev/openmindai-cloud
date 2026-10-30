<?php

namespace Tests\Feature;

use App\Jobs\RunCloudImage;
use App\Models\MediaTask;
use App\Models\User;
use App\Services\CloudImageEngine;
use App\Services\CloudImagePrompt;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CloudImagePipelineTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(): User
    {
        $user = User::create(['name' => 'Image Test', 'email' => uniqid().'@example.test', 'password' => 'StrongPassword123']);
        Sanctum::actingAs($user);
        config(['cloud_images.enabled' => true, 'cloud_images.key' => 'server-provider-test-token']);

        return $user;
    }

    private function png(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aX1kAAAAASUVORK5CYII=');
    }

    public function test_image_submission_returns_actionable_error_before_queueing_on_outdated_database(): void
    {
        $this->signIn();
        Queue::fake();
        Schema::table('media_tasks', function (Blueprint $table) {
            $table->dropColumn(['progress_percent', 'progress_stage']);
        });

        $this->postJson('/api/v1/media/cloud-image', ['prompt' => 'Sunset over water'])
            ->assertStatus(503)
            ->assertJsonPath('message', 'The image service database needs its latest migrations. Ask an administrator to run php artisan migrate --force.');
        Queue::assertNothingPushed();
    }

    public function test_images_are_queued_with_ratios_variations_and_no_frontend_provider_call(): void
    {
        $this->signIn();
        Queue::fake();
        Http::fake();
        $response = $this->postJson('/api/v1/media/cloud-image', [
            'prompt' => 'A futuristic city', 'count' => 3, 'aspect_ratio' => '16:9', 'seed' => 100,
        ])->assertStatus(202)->assertJsonPath('status', 'queued')->assertJsonCount(3, 'ids');
        $this->assertDatabaseCount('media_tasks', 3);
        foreach ($response->json('ids') as $index => $id) {
            $task = MediaTask::findOrFail($id);
            $this->assertSame('queued', $task->status);
            $this->assertSame('16:9', $task->cloud_options['aspect_ratio']);
            $this->assertSame(100 + $index, $task->cloud_options['seed']);
        }
        Queue::assertPushed(RunCloudImage::class, 3);
        Http::assertNothingSent();
    }

    public function test_quota_and_input_guards_reject_batch_before_dispatch(): void
    {
        $this->signIn();
        Queue::fake();
        config(['media.image_daily_limit' => 2]);
        $this->postJson('/api/v1/media/cloud-image', [
            'prompt' => 'Forest', 'count' => 3,
        ])->assertStatus(429);
        $this->postJson('/api/v1/media/cloud-image', [
            'prompt' => 'Forest', 'mode' => 'edit',
        ])->assertStatus(503);
        $this->postJson('/api/v1/media/cloud-image', [
            'prompt' => 'Forest', 'aspect_ratio' => '1:3',
        ])->assertUnprocessable();
        Queue::assertNothingPushed();
        $this->assertDatabaseCount('media_tasks', 0);
    }

    public function test_worker_validates_output_and_saves_private_image_and_credit(): void
    {
        $user = $this->signIn();
        Storage::fake('local');
        Queue::fake();
        $result = $this->postJson('/api/v1/media/cloud-image', ['prompt' => 'Sunrise over forest'])
            ->assertStatus(202)->json();
        Http::fake(['ai.api.nvidia.com/*' => Http::response([
            'artifacts' => [['base64' => base64_encode($this->png()), 'finishReason' => 'SUCCESS']],
        ])]);
        (new RunCloudImage($result['id']))->handle(app(CloudImageEngine::class));
        $task = MediaTask::findOrFail($result['id']);
        $this->assertSame('complete', $task->status);
        $this->assertSame('image/png', $task->output_mime);
        Storage::disk('local')->assertExists($task->output_path);
        $this->assertDatabaseHas('api_credit_usage', ['user_id' => $user->id, 'credits' => 1]);
        $this->get('/api/v1/media/'.$task->id.'/output')->assertOk()->assertHeader('Content-Type', 'image/png');
        Http::assertSent(fn ($request) => $request->url() === config('cloud_images.fast_endpoint')
            && $request['width'] === 1024 && $request['samples'] === 1
            && $request['cfg_scale'] === 1 && ! isset($request['mode']));
    }

    public function test_slow_provider_budget_fits_worker_and_queue_without_duplicate_generation(): void
    {
        $this->signIn();
        Storage::fake('local');
        Queue::fake();
        $id = $this->postJson('/api/v1/media/cloud-image', ['prompt' => 'Dog'])->json('id');
        // Even an excessive operator setting must not outlive the job reservation.
        config(['cloud_images.timeout' => 3600]);
        $calls = 0;
        Http::fake(function ($request, $options) use (&$calls) {
            $calls++;
            $this->assertSame(720, $options['timeout']);
            $this->assertSame(30, $options['connect_timeout']);
            $this->assertSame('running', MediaTask::latest('id')->first()->status);

            return Http::response(['artifacts' => [['base64' => base64_encode($this->png())]]]);
        });
        $job = new RunCloudImage($id);
        $this->assertGreaterThan(750, $job->timeout);
        $this->assertGreaterThan($job->timeout, config('queue.connections.media.retry_after'));
        $job->handle(app(CloudImageEngine::class));
        $job->handle(app(CloudImageEngine::class));
        $this->assertSame(1, $calls);
        $this->assertDatabaseHas('media_tasks', ['id' => $id, 'status' => 'complete']);
        $this->assertDatabaseCount('api_credit_usage', 1);
    }

    public function test_failed_provider_request_is_not_counted_as_success(): void
    {
        $this->signIn();
        Storage::fake('local');
        Queue::fake();
        $id = $this->postJson('/api/v1/media/cloud-image', ['prompt' => 'Mountain'])->json('id');
        Http::fake(['ai.api.nvidia.com/*' => Http::response(['message' => 'rate limited'], 429)]);
        (new RunCloudImage($id))->handle(app(CloudImageEngine::class));
        $this->assertDatabaseHas('media_tasks', ['id' => $id, 'status' => 'failed', 'error' => 'The image provider is rate limited or has no available quota (HTTP 429). Try later or check the provider account.']);
        $this->assertDatabaseCount('api_credit_usage', 0);
    }

    public function test_connection_failure_is_actionable_without_exposing_exception_details(): void
    {
        $this->signIn();
        Storage::fake('local');
        $task = MediaTask::create(['user_id' => auth()->id(), 'kind' => 'image', 'prompt' => 'Dog', 'status' => 'running']);
        (new RunCloudImage($task->id))->failed(new ConnectionException('private-token and provider request details'));
        $task->refresh();
        $this->assertSame('failed', $task->status);
        $this->assertStringContainsString('timed out or could not be reached', $task->error);
        $this->assertStringNotContainsString('private-token', $task->error);
        $this->assertDatabaseCount('api_credit_usage', 0);
    }

    public function test_nonsquare_generation_uses_live_validated_klein_dimensions_and_payload(): void
    {
        $this->signIn();
        Storage::fake('local');
        Queue::fake();
        $id = $this->postJson('/api/v1/media/cloud-image', [
            'prompt' => 'Sea landscape', 'aspect_ratio' => '16:9',
        ])->json('id');
        Http::fake(['ai.api.nvidia.com/*' => Http::response([
            'artifacts' => [['base64' => base64_encode($this->png())]],
        ])]);
        (new RunCloudImage($id))->handle(app(CloudImageEngine::class));
        Http::assertSent(fn ($request) => $request->url() === config('cloud_images.ratio_endpoint')
            && $request['width'] === 1280 && $request['height'] === 720
            && $request['cfg_scale'] === 1 && $request['steps'] === 4
            && ! isset($request['mode']) && ! isset($request['aspect_ratio']));
    }

    public function test_language_bridge_preserves_source_and_falls_back_when_unavailable(): void
    {
        $this->signIn();
        $unavailable = false;
        Http::fake(['integrate.api.nvidia.com/*' => function () use (&$unavailable) {
            return $unavailable
                ? Http::response([], 503)
                : Http::response(['choices' => [['message' => ['content' => 'A blue futuristic robot in Dhaka']]]]);
        }]);
        $source = 'dhaka te ekta nil robot er chobi banao';
        $normalized = app(CloudImagePrompt::class)->normalize($source);
        $this->assertSame('A blue futuristic robot in Dhaka', $normalized);
        $unavailable = true;
        $this->assertSame($source, app(CloudImagePrompt::class)->normalize($source));
    }

    public function test_private_image_edit_uses_actual_upload_and_removes_source_after_success(): void
    {
        $this->signIn();
        Storage::fake('local');
        Queue::fake();
        config([
            'cloud_images.edit_url' => 'https://image-worker.example.org/v1/images/edits',
            'cloud_images.edit_key' => 'private-compatible-edit-key',
            'cloud_images.edit_model' => 'qwen/qwen-image-edit-2511',
        ]);
        $source = base64_encode($this->png());
        $id = $this->postJson('/api/v1/media/cloud-image', [
            'prompt' => 'Change the sky color', 'mode' => 'edit', 'image' => $source,
        ])->assertStatus(202)->json('id');
        $task = MediaTask::findOrFail($id);
        Storage::disk('local')->assertExists($task->input_path);
        Http::fake(['image-worker.example.org/*' => Http::response([
            'data' => [['b64_json' => $source]],
        ])]);
        (new RunCloudImage($id))->handle(app(CloudImageEngine::class));
        $task = MediaTask::findOrFail($id);
        $this->assertSame('complete', $task->status);
        $this->assertNull($task->input_path);
        $this->assertSame('image/png', $task->output_mime);
        Http::assertSent(fn ($request) => $request->url() === config('cloud_images.edit_url')
            && $request['model'] === 'qwen/qwen-image-edit-2511'
            && $request['image'] === 'data:image/png;base64,'.$source
            && ! isset($request['mask']));
    }

    public function test_masks_require_a_matching_png_and_private_runtime(): void
    {
        $this->signIn();
        Storage::fake('local');
        Queue::fake();
        config([
            'cloud_images.mask_url' => 'https://mask-worker.example.org/v1/images/edits',
            'cloud_images.mask_key' => 'private-mask-key-for-testing',
            'cloud_images.mask_model' => 'custom/inpainting-runtime',
        ]);
        $base64 = base64_encode($this->png());
        $id = $this->postJson('/api/v1/media/cloud-image', [
            'prompt' => 'Replace only the masked region', 'mode' => 'inpaint',
            'image' => $base64, 'mask' => $base64,
        ])->assertStatus(202)->json('id');
        Http::fake(['mask-worker.example.org/*' => Http::response([
            'data' => [['b64_json' => $base64]],
        ])]);
        (new RunCloudImage($id))->handle(app(CloudImageEngine::class));
        Http::assertSent(fn ($request) => $request->url() === config('cloud_images.mask_url')
            && $request['mode'] === 'inpaint'
            && str_starts_with($request['mask'], 'data:image/png;base64,'));
        $this->assertSame('complete', MediaTask::findOrFail($id)->status);
    }

    public function test_user_monthly_credit_limit_blocks_image_batch(): void
    {
        $user = $this->signIn();
        Queue::fake();
        $user->forceFill(['monthly_credit_limit' => 1])->save();
        $this->postJson('/api/v1/media/cloud-image', [
            'prompt' => 'Three robots', 'count' => 3,
        ])->assertStatus(429);
        Queue::assertNothingPushed();
    }

    public function test_unconfigured_editing_is_not_advertised_and_urls_are_operator_selected(): void
    {
        $this->signIn();
        $this->getJson('/api/v1/media/image-capabilities')->assertOk()
            ->assertJsonPath('enabled', true)->assertJsonPath('image_editing', false)
            ->assertJsonPath('image_masking', false);
        $this->assertFalse(CloudImageEngine::safeNimUrl('http://127.0.0.1/v1/images/edits'));
        $this->assertFalse(CloudImageEngine::safeNimUrl('https://example.org/other/path'));
        $this->assertTrue(CloudImageEngine::safeNimUrl('https://image-worker.example.org/v1/images/edits'));
    }
}
