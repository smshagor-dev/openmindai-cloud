<?php

namespace Tests\Feature;

use App\Jobs\RunWorkspaceTask;
use App\Models\AiModel;
use App\Models\ScheduledTask;
use App\Models\TaskRun;
use App\Models\User;
use App\Services\AdminSettings;
use App\Services\TaskCheckpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TaskResumeTest extends TestCase
{
    use RefreshDatabase;

    private function task(): ScheduledTask
    {
        $u = User::create(['name' => 'Resume', 'email' => uniqid().'@example.com', 'password' => 'StrongPassword123']);
        Sanctum::actingAs($u);
        Queue::fake();
        $m = AiModel::create(['name' => 'Q', 'family' => 'Qwen3', 'repository' => 'test/resume', 'revision' => str_repeat('a', 40), 'filename' => 'test.gguf', 'sha256' => str_repeat('b', 64), 'bytes' => 4, 'license' => 'test', 'enabled' => true, 'status' => 'ready', 'runtime_model' => 'fixture']);

        return ScheduledTask::create(['user_id' => $u->id, 'title' => 'Resume', 'prompt' => 'PRIVATE REQUEST', 'model_id' => $m->id, 'kind' => 'chat', 'frequency' => 'once', 'enabled' => true]);
    }

    public function test_interrupted_answer_resumes_from_encrypted_checkpoint_without_new_daily_request(): void
    {
        $task = $this->task();
        app(AdminSettings::class)->save(['task_daily_limit' => '1']);
        $run = TaskRun::create(['scheduled_task_id' => $task->id]);
        $calls = 0;
        Http::fake(function ($request) use (&$calls) {
            $calls++;
            if ($calls === 2) {
                return Http::response([], 503);
            }
            if ($calls === 3) {
                $this->assertSame('First ', $request['messages'][count($request['messages']) - 2]['content']);
            }

            return Http::response(['choices' => [['message' => ['content' => $calls === 1 ? 'First ' : 'final'], 'finish_reason' => $calls === 1 ? 'length' : 'stop']]]);
        });
        (new RunWorkspaceTask($run->id))->handle();
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertTrue($run->fresh()->resumable);
        $raw = DB::table('task_runs')->where('id', $run->id)->value('checkpoint');
        $this->assertStringNotContainsString('PRIVATE REQUEST', $raw);
        $this->assertStringNotContainsString('First ', $raw);
        $this->getJson('/api/v1/tasks/'.$task->id.'/runs')->assertOk()->assertJsonMissingPath('data.0.checkpoint')->assertJsonMissingPath('data.0.execution_token')->assertJsonPath('data.0.resumable', true);
        $this->postJson('/api/v1/tasks/'.$task->id.'/runs/'.$run->id.'/resume')->assertStatus(202);
        $this->postJson('/api/v1/tasks/'.$task->id.'/runs/'.$run->id.'/resume')->assertStatus(409);
        (new RunWorkspaceTask($run->id))->handle();
        $this->assertSame('First final', $run->fresh()->result);
        $this->assertSame('complete', $run->fresh()->status);
        $this->assertNull($run->fresh()->checkpoint);
        $this->assertDatabaseCount('task_runs', 1);
        $this->postJson('/api/v1/tasks/'.$task->id.'/runs')->assertStatus(429);
    }

    public function test_unlimited_daily_tasks_allow_multiple_manual_runs(): void
    {
        $task = $this->task();
        app(AdminSettings::class)->save(['task_daily_limit' => '0']);

        $first = $this->postJson('/api/v1/tasks/'.$task->id.'/runs')
            ->assertStatus(202)->json('id');
        TaskRun::findOrFail($first)->update(['status' => 'complete', 'result' => 'Finished']);

        $second = $this->postJson('/api/v1/tasks/'.$task->id.'/runs')
            ->assertStatus(202)->json('id');

        $this->assertNotSame($first, $second);
        $this->assertDatabaseCount('task_runs', 2);
    }

    public function test_modified_task_definition_cannot_silently_resume_old_context(): void
    {
        $task = $this->task();
        $run = TaskRun::create(['scheduled_task_id' => $task->id, 'status' => 'failed', 'checkpoint' => ['version' => 1, 'stage' => 'writing', 'task_signature' => TaskCheckpoint::signature($task)]]);
        $task->update(['prompt' => 'A different request']);
        $this->postJson('/api/v1/tasks/'.$task->id.'/runs/'.$run->id.'/resume')->assertStatus(409);
        $this->assertSame('failed', $run->fresh()->status);
    }

    public function test_other_users_cannot_resume_a_run(): void
    {
        $task = $this->task();
        $run = TaskRun::create(['scheduled_task_id' => $task->id, 'status' => 'failed', 'checkpoint' => ['version' => 1, 'stage' => 'writing']]);
        Sanctum::actingAs(User::create(['name' => 'Other', 'email' => 'other@example.com', 'password' => 'StrongPassword123']));
        $this->postJson('/api/v1/tasks/'.$task->id.'/runs/'.$run->id.'/resume')->assertNotFound();
        $this->assertSame('failed', $run->fresh()->status);
    }

    public function test_stale_worker_cannot_overwrite_a_new_execution(): void
    {
        $task = $this->task();
        $run = TaskRun::create(['scheduled_task_id' => $task->id, 'status' => 'running', 'execution_token' => 'new-owner']);
        $old = new RunWorkspaceTask($run->id);
        $this->assertNotNull($old->executionToken);
        $restored = unserialize(serialize($old));
        $this->assertSame($old->executionToken, $restored->executionToken);
        $old->executionToken = 'old-owner';
        $old->failed(new \RuntimeException('Old process'));
        $this->assertFalse(app(TaskCheckpoint::class)->save($run->id, 'old-owner', ['result' => 'Stale text']));
        $this->assertSame('running', $run->fresh()->status);
        $this->assertSame('new-owner', $run->fresh()->execution_token);
        $run->update(['status' => 'queued']);
        Http::fake();
        $old->handle();
        $this->assertSame('queued', $run->fresh()->status);
        $this->assertSame('new-owner', $run->fresh()->execution_token);
        Http::assertNothingSent();
    }
}
