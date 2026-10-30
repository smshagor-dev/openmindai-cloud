<?php

namespace Tests\Feature;

use App\Jobs\RunCloudImage;
use App\Models\MediaTask;
use App\Models\User;
use App\Services\CloudImageEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ImageGenerationProgressTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(): void
    {
        $user = User::create([
            'name' => 'Progress Tester',
            'email' => 'progress@example.test',
            'password' => 'StrongPassword123',
        ]);
        Sanctum::actingAs($user);
        config(['cloud_images.enabled' => true, 'cloud_images.key' => 'server-provider-test-token']);
        Storage::fake('local');
        Queue::fake();
    }

    public function test_image_progress_matches_persisted_workflow_milestones(): void
    {
        $this->signIn();
        $id = $this->postJson('/api/v1/media/cloud-image', ['prompt' => 'A dog on a beach'])
            ->assertStatus(202)->json('id');

        $this->getJson('/api/v1/media/'.$id)
            ->assertOk()->assertJsonPath('status', 'queued')
            ->assertJsonPath('progress_percent', 0)->assertJsonPath('progress_stage', 'queued')
            ->assertHeader('Cache-Control', 'no-store, private');

        $image = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aX1kAAAAASUVORK5CYII=');
        Http::fake(function () use ($id, $image) {
            $this->assertDatabaseHas('media_tasks', [
                'id' => $id, 'status' => 'running',
                'progress_percent' => 28, 'progress_stage' => 'generating',
            ]);

            return Http::response(['artifacts' => [['base64' => base64_encode($image), 'finishReason' => 'SUCCESS']]]);
        });

        (new RunCloudImage($id))->handle(app(CloudImageEngine::class));

        $this->getJson('/api/v1/media/'.$id)
            ->assertOk()->assertJsonPath('status', 'complete')
            ->assertJsonPath('progress_percent', 100)->assertJsonPath('progress_stage', 'complete');

        $this->getJson('/api/v1/media?kind=image')
            ->assertOk()->assertJsonPath('data.0.progress_percent', 100)
            ->assertJsonPath('data.0.progress_stage', 'complete');
    }

    public function test_failed_image_never_reports_full_completion(): void
    {
        $this->signIn();
        $id = $this->postJson('/api/v1/media/cloud-image', ['prompt' => 'Sunset'])
            ->assertStatus(202)->json('id');

        Http::fake(['ai.api.nvidia.com/*' => Http::response(['detail' => 'unavailable'], 429)]);
        (new RunCloudImage($id))->handle(app(CloudImageEngine::class));

        $task = MediaTask::findOrFail($id);
        $this->assertSame('failed', $task->status);
        $this->assertSame('failed', $task->progress_stage);
        $this->assertLessThan(100, $task->progress_percent);
        $this->getJson('/api/v1/media/'.$id)->assertJsonPath('progress_stage', 'failed');
    }
}
