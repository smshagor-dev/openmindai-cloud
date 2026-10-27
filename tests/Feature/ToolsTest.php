<?php

namespace Tests\Feature;

use App\Jobs\RunWorkspaceTask;
use App\Models\AiModel;
use App\Models\Conversation;
use App\Models\ScheduledTask;
use App\Models\TaskRun;
use App\Models\User;
use App\Services\ChatContext;
use App\Services\WebReader;
use App\Services\WebSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ToolsTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::create(['name' => 'Tools', 'email' => uniqid().'@example.com', 'password' => 'StrongPassword123']);
    }

    private function model(): AiModel
    {
        return AiModel::create(['name' => 'Qwen', 'family' => 'Qwen3', 'repository' => 'test/tools', 'revision' => str_repeat('a', 40), 'filename' => 'test.gguf', 'sha256' => str_repeat('b', 64), 'bytes' => 4, 'license' => 'test', 'enabled' => true, 'status' => 'ready', 'runtime_model' => 'fixture']);
    }

    private function task(User $u): ScheduledTask
    {
        return ScheduledTask::create(['user_id' => $u->id, 'title' => 'Task', 'prompt' => 'Explain queues', 'model_id' => $this->model()->id, 'kind' => 'chat', 'frequency' => 'once', 'enabled' => true]);
    }

    public function test_assistant_crud_and_chat_context_are_owned(): void
    {
        $u = $this->user();
        Sanctum::actingAs($u);
        $a = $this->postJson('/api/v1/assistants', ['name' => 'Tutor', 'instructions' => 'Teach with examples', 'attachment_ids' => []])->assertCreated()->json();
        $system = app(ChatContext::class)->build($u, new Conversation(['assistant_id' => $a['id']]), 'Hello')[0]['content'];
        $this->assertStringContainsString('Teach with examples', $system);
        $this->putJson('/api/v1/assistants/'.$a['id'], ['name' => 'Tutor updated', 'instructions' => 'Explain simply'])->assertOk();
        Sanctum::actingAs($this->user());
        $this->getJson('/api/v1/assistants')->assertJsonCount(0);
        $this->deleteJson('/api/v1/assistants/'.$a['id'])->assertNotFound();
        $this->postJson('/api/v1/chat/stream', ['assistant_id' => $a['id'], 'model_id' => 1, 'message' => 'Hi'])->assertUnprocessable();
    }

    public function test_task_queue_complete_and_duplicate_run_guard(): void
    {
        Queue::fake();
        $u = $this->user();
        $t = $this->task($u);
        Sanctum::actingAs($u);
        $r = $this->postJson('/api/v1/tasks/'.$t->id.'/runs')->assertStatus(202);
        $id = $r->json('id');
        $this->postJson('/api/v1/tasks/'.$t->id.'/runs')->assertStatus(409);
        Queue::assertPushed(RunWorkspaceTask::class);
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'A useful result']]]])]);
        (new RunWorkspaceTask($id))->handle();
        $this->getJson('/api/v1/tasks/'.$t->id.'/runs')->assertOk()->assertJsonPath('data.0.status', 'complete')->assertJsonPath('data.0.result', 'A useful result');
        Sanctum::actingAs($this->user());
        $this->getJson('/api/v1/tasks/'.$t->id.'/runs')->assertNotFound();
        $this->deleteJson('/api/v1/tasks/'.$t->id)->assertNotFound();
    }

    public function test_cancelled_task_does_not_call_inference(): void
    {
        $u = $this->user();
        $t = $this->task($u);
        $r = TaskRun::create(['scheduled_task_id' => $t->id]);
        Sanctum::actingAs($u);
        $this->deleteJson('/api/v1/tasks/'.$t->id.'/runs/'.$r->id)->assertNoContent();
        Http::fake();
        (new RunWorkspaceTask($r->id))->handle();
        Http::assertNothingSent();
        $this->assertSame('cancelled', $r->fresh()->status);
    }

    public function test_research_sources_and_steps_are_persisted(): void
    {
        $u = $this->user();
        $t = $this->task($u);
        $t->update(['kind' => 'research']);
        $r = TaskRun::create(['scheduled_task_id' => $t->id]);
        $this->mock(WebSearch::class)->shouldReceive('search')->times(3)->andReturn([['title' => 'Source', 'url' => 'https://example.com/article', 'snippet' => 'Summary']]);
        $this->mock(WebReader::class)->shouldReceive('read')->once()->andReturn(str_repeat('Verified page fixture text. ', 5));
        Http::fake(fn ($request) => Http::response(['choices' => [['message' => ['content' => stripos($request['messages'][0]['content'], 'research coordinator') !== false ? json_encode(['action' => 'finish', 'reason' => 'Fixture coverage complete']) : 'Sourced report https://example.com/article']]]]));
        (new RunWorkspaceTask($r->id))->handle();
        $this->assertSame('complete', $r->fresh()->status);
        $this->assertSame('read', $r->fresh()->sources[0]['read_status']);
        Http::assertSent(fn ($r) => str_contains($r['messages'][0]['content'], 'Verified page fixture text'));
    }

    public function test_web_reader_blocks_private_and_unsupported_urls(): void
    {
        foreach (['http://127.0.0.1/x', 'http://169.254.169.254/latest/meta-data', 'http://100.100.100.200/', 'http://198.18.0.1/', 'file:///etc/passwd', 'http://user:password@example.com', 'https://example.com:8080'] as $url) {
            try {
                app(WebReader::class)->read($url);
                $this->fail('Unsafe URL accepted');
            } catch (\RuntimeException $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }
    }

    public function test_canvas_history_retains_versions_and_restores_as_new_version(): void
    {
        Sanctum::actingAs($this->user());
        $a = $this->postJson('/api/v1/canvases', ['title' => 'Draft', 'format' => 'code', 'content' => 'print(1)'])->assertCreated()->json();
        $this->putJson('/api/v1/canvases/'.$a['id'], ['title' => 'Draft', 'format' => 'code', 'content' => 'print(2)', 'version' => 1])->assertOk();
        $this->getJson('/api/v1/canvases/'.$a['id'].'/versions')->assertOk()->assertJsonCount(2);
        $this->getJson('/api/v1/canvases/'.$a['id'].'/versions/1')->assertJsonPath('content', 'print(1)');
        Sanctum::actingAs($this->user());
        $this->getJson('/api/v1/canvases/'.$a['id'].'/versions/1')->assertNotFound();
    }

    public function test_branch_navigator_excludes_other_users(): void
    {
        $u = $this->user();
        $a = Conversation::create(['user_id' => $u->id, 'title' => 'Root']);
        $b = Conversation::create(['user_id' => $u->id, 'title' => 'Branch', 'parent_id' => $a->id]);
        Conversation::create(['user_id' => $this->user()->id, 'title' => 'Foreign', 'parent_id' => $a->id]);
        Sanctum::actingAs($u);
        $this->getJson('/api/v1/conversations/'.$b->id.'/branches')->assertOk()->assertJsonCount(2);
    }

    public function test_code_execution_requires_explicit_approval(): void
    {
        Sanctum::actingAs($this->user());
        config(['media.url' => 'http://private-runtime', 'media.key' => str_repeat('k', 32), 'media.kinds' => ['code']]);
        Queue::fake();
        $this->postJson('/api/v1/media', ['kind' => 'code', 'prompt' => 'print(1)'])->assertUnprocessable();
        $this->postJson('/api/v1/media', ['kind' => 'code', 'prompt' => 'print(1)', 'approved' => false])->assertUnprocessable();
        $this->postJson('/api/v1/media', ['kind' => 'code', 'prompt' => 'print(1)', 'approved' => true])->assertStatus(202);
    }
}
