<?php

namespace Tests\Feature;

use App\Http\Controllers\OpenMindAiGatewayController;
use App\Models\StudioJob;
use App\Models\User;
use App\Services\Studio\Catalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudioCpanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('local');
        config(['studio.scene_execution' => 'direct', 'studio.queue_enabled' => false, 'studio.worker_url' => '', 'studio.worker_key' => '']);
        $user = User::create(['name' => 'cPanel fixture', 'email' => 'cpanel@example.test', 'password' => 'FixturePassword123']);
        $user->forceFill(['email_verified_at' => now()])->save();
        Sanctum::actingAs($user, ['user']);
    }

    private function input(): array
    {
        return ['request_key' => 'direct-fixture', 'prompt' => 'Build a delivery robot', 'mode' => 'object', 'upload_ids' => []];
    }

    private function provider(): void
    {
        $scene = ['title' => 'Robot', 'description' => 'Procedural fixture', 'nodes' => [['id' => 'body', 'label' => 'Body', 'shape' => 'box', 'position' => [0, 0, 0], 'rotation' => [0, 0, 0], 'scale' => [1, 1, 1], 'color' => '#4488ff']], 'edges' => []];
        $this->mock(OpenMindAiGatewayController::class)->shouldReceive('mobileChat')->once()->andReturn(response()->json(['choices' => [['message' => ['content' => json_encode($scene)]]]]));
    }

    public function test_direct_generation_is_private_idempotent_and_needs_no_consumer(): void
    {
        $this->provider();
        $id = $this->postJson('/api/v1/3d-studio/jobs/scene', $this->input())->assertCreated()->json('id');
        $this->postJson('/api/v1/3d-studio/jobs/scene', $this->input())->assertOk()->assertJsonPath('id', $id);
        $this->assertDatabaseCount('studio_scenes', 1);
        $this->assertDatabaseCount('studio_jobs', 1);
        $this->assertSame('completed', StudioJob::first()->status);
        $this->postJson('/api/v1/3d-studio/jobs/scene', [...$this->input(), 'prompt' => 'A different robot'])->assertConflict();
        Queue::assertNothingPushed();
    }

    public function test_saved_scene_recovers_a_request_interrupted_after_persistence(): void
    {
        $this->provider();
        $id = $this->postJson('/api/v1/3d-studio/jobs/scene', $this->input())->assertCreated()->json('id');
        StudioJob::first()->update(['status' => 'running', 'result' => null]);
        $this->postJson('/api/v1/3d-studio/jobs/scene', $this->input())->assertOk()->assertJsonPath('id', $id);
        $this->assertSame('completed', StudioJob::first()->status);
        Queue::assertNothingPushed();
    }

    public function test_failed_provider_request_is_not_automatically_rebilled(): void
    {
        $this->mock(OpenMindAiGatewayController::class)->shouldReceive('mobileChat')->once()->andReturn(response()->json(['message' => 'Unavailable'], 503));
        $this->postJson('/api/v1/3d-studio/jobs/scene', $this->input())->assertStatus(503);
        $this->postJson('/api/v1/3d-studio/jobs/scene', $this->input())->assertConflict();
        $this->assertDatabaseCount('studio_scenes', 0);
        $this->assertSame('failed', StudioJob::first()->status);
        Queue::assertNothingPushed();
    }

    public function test_cpanel_preflight_does_not_require_an_external_worker(): void
    {
        $this->mock(Catalog::class)->shouldReceive('all')->andReturn(['data' => [['id' => 'procedural', 'available' => true], ['id' => 'architecture', 'available' => true]]]);
        $this->artisan('studio:preflight --cpanel')->assertExitCode(0);
        $this->artisan('studio:preflight --require-gpu')->assertExitCode(1);
        $this->artisan('studio:preflight --cpanel --require-gpu')->assertExitCode(2);
    }

    public function test_worker_only_requests_are_rejected_without_orphan_jobs(): void
    {
        $this->postJson('/api/v1/3d-studio/jobs', [])->assertStatus(503);
        $this->postJson('/api/v1/3d-studio/workflows/run', [])->assertStatus(503);
        $this->assertDatabaseCount('studio_jobs', 0);
        Queue::assertNothingPushed();
    }

    public function test_recovery_does_not_retry_uncertain_direct_provider_calls(): void
    {
        $this->provider();
        $this->postJson('/api/v1/3d-studio/jobs/scene', $this->input())->assertCreated();
        $job = StudioJob::first();
        DB::table('studio_scenes')->where('origin_job_id', $job->id)->delete();
        $job->update(['status' => 'running', 'result' => null, 'lease_until' => now()->subMinute()]);
        $this->artisan('studio:recover')->assertExitCode(0);
        $this->assertSame('failed', $job->fresh()->status);
        $this->assertSame('provider_outcome_uncertain', $job->fresh()->error_code);
        Queue::assertNothingPushed();
    }
}
