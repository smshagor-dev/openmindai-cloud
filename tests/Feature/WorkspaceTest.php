<?php

namespace Tests\Feature;

use App\Models\AiModel;
use App\Models\Conversation;
use App\Models\Memory;
use App\Models\Message;
use App\Models\User;
use App\Services\ResearchCompletion;
use App\Services\WebResearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        $u = User::create(['name' => 'Workspace', 'email' => uniqid().'@example.com', 'password' => 'StrongPassword123']);
        $u->forceFill(['email_verified_at' => now()])->save();

        return $u;
    }

    private function model(): AiModel
    {
        return AiModel::create(['name' => 'Qwen', 'family' => 'Qwen3', 'repository' => 'test/fixture', 'revision' => str_repeat('a', 40), 'filename' => 'test.gguf', 'sha256' => str_repeat('b', 64), 'bytes' => 4, 'license' => 'test', 'enabled' => true, 'status' => 'ready', 'runtime_model' => 'fixture']);
    }

    private function fakeStream(): void
    {
        Http::fake(['*' => Http::response('data: '.json_encode(['choices' => [['delta' => ['content' => 'Hello ']]]])."\n\ndata: ".json_encode(['choices' => [['delta' => ['content' => 'world']]]])."\n\ndata: [DONE]\n\n", 200, ['Content-Type' => 'text/event-stream'])]);
    }

    public function test_stream_persists_real_deltas_and_message_ids(): void
    {
        Sanctum::actingAs($this->user());
        $this->fakeStream();
        $r = $this->postJson('/api/v1/chat/stream', ['model_id' => $this->model()->id, 'message' => 'Hi']);
        $r->assertOk();
        $stream = $r->streamedContent();
        $this->assertStringContainsString('event: meta', $stream);
        $this->assertStringContainsString('Hello ', $stream);
        $this->assertStringContainsString('event: done', $stream);
        $this->assertDatabaseHas('messages', ['role' => 'assistant', 'content' => 'Hello world', 'status' => 'complete']);
        $this->assertDatabaseCount('messages', 2);
        Http::assertSent(fn ($request) => $request['stream'] === true);
    }

    public function test_failed_stream_keeps_question_with_failed_response(): void
    {
        Sanctum::actingAs($this->user());
        Http::fake(['*' => Http::response([], 503)]);
        $stream = $this->postJson('/api/v1/chat/stream', ['model_id' => $this->model()->id, 'message' => 'Question'])->streamedContent();
        $this->assertStringContainsString('event: error', $stream);
        $this->assertDatabaseHas('messages', ['role' => 'user', 'content' => 'Question']);
        $this->assertDatabaseHas('messages', ['role' => 'assistant', 'status' => 'failed']);
    }

    public function test_temporary_chat_has_history_but_no_database_records_or_memory(): void
    {
        $u = $this->user();
        $u->forceFill(['custom_instructions' => 'SECRET PREFERENCE', 'memory_enabled' => true])->save();
        Memory::create(['user_id' => $u->id, 'content' => 'SECRET MEMORY']);
        Sanctum::actingAs($u);
        $this->fakeStream();
        $this->postJson('/api/v1/chat/stream', ['model_id' => $this->model()->id, 'message' => 'Next', 'temporary' => true, 'history' => [['role' => 'user', 'content' => 'Earlier'], ['role' => 'assistant', 'content' => 'Previous']]])->streamedContent();
        $this->assertDatabaseCount('conversations', 0);
        $this->assertDatabaseCount('messages', 0);
        Http::assertSent(function ($r) {
            $text = json_encode($r['messages']);

            return str_contains($text, 'Earlier') && ! str_contains($text, 'SECRET');
        });
    }

    public function test_stop_request_preserves_cancelled_status(): void
    {
        Sanctum::actingAs($this->user());
        Cache::put('stop-message:2', true, 180);
        $this->fakeStream();
        $this->postJson('/api/v1/chat/stream', ['model_id' => $this->model()->id, 'message' => 'Hi'])->streamedContent();
        $this->assertDatabaseHas('messages', ['role' => 'assistant', 'status' => 'cancelled']);
    }

    public function test_local_deep_research_reports_progress_and_continues_truncated_generation(): void
    {
        Sanctum::actingAs($this->user());
        $this->partialMock(WebResearch::class)->shouldNotReceive('collect');
        $source = ['url' => 'https://example.org/report', 'title' => 'Evidence', 'read_status' => 'read', 'citation' => 1];
        $this->mock(ResearchCompletion::class)->shouldReceive('run')->once()->andReturnUsing(function ($question, $sources, $model, $progress) use ($source) {
            $progress('Read more evidence', [$source]);

            return ['sources' => [$source], 'conclusion' => 'Request covered'];
        });
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;
            $payload = ['choices' => [['delta' => ['content' => $calls === 1 ? 'First ' : 'last'], 'finish_reason' => $calls === 1 ? 'length' : 'stop']]];

            return Http::response('data: '.json_encode($payload)."\n\ndata: [DONE]\n\n", 200, ['Content-Type' => 'text/event-stream']);
        });
        $r = $this->postJson('/api/v1/chat/stream', ['model_id' => $this->model()->id, 'message' => 'Complete report', 'deep_research' => true])->assertOk();
        $this->assertStringContainsString('event: research', $r->streamedContent());
        $this->assertSame(2, $calls);
        $answer = Message::where('role', 'assistant')->first();
        $this->assertStringStartsWith('First last', $answer->content);
        $this->assertStringContainsString('[Evidence](https://example.org/report)', $answer->content);
        $this->assertStringContainsString('do not establish', $answer->content);
        $this->assertSame('complete', $answer->status);
        $this->assertCount(1, $answer->sources);
    }

    public function test_search_finds_message_text_and_archiving_hides_chat(): void
    {
        $u = $this->user();
        Sanctum::actingAs($u);
        $c = Conversation::create(['user_id' => $u->id, 'title' => 'Research']);
        $c->messages()->create(['role' => 'user', 'content' => 'A unique zebra fact']);
        $this->getJson('/api/v1/conversations?q=zebra')->assertJsonPath('data.0.id', $c->id);
        $this->patchJson('/api/v1/conversations/'.$c->id, ['pinned' => true, 'archived' => true, 'title' => 'Renamed'])->assertOk();
        $this->getJson('/api/v1/conversations')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/conversations?archived=1')->assertJsonPath('data.0.title', 'Renamed')->assertJsonPath('data.0.pinned', true);
    }

    public function test_branches_preserve_original_history(): void
    {
        $u = $this->user();
        Sanctum::actingAs($u);
        $c = Conversation::create(['user_id' => $u->id, 'title' => 'Original']);
        $c->messages()->create(['role' => 'user', 'content' => 'First']);
        $c->messages()->create(['role' => 'assistant', 'content' => 'Answer']);
        $target = $c->messages()->create(['role' => 'user', 'content' => 'Edit this']);
        $r = $this->postJson('/api/v1/conversations/'.$c->id.'/branch', ['message_id' => $target->id])->assertOk();
        $this->assertDatabaseCount('conversations', 2);
        $this->assertSame(3, $c->messages()->count());
        $this->assertSame(2, Conversation::find($r->json('conversation_id'))->messages()->count());
    }

    public function test_projects_and_memories_are_owned_and_injected(): void
    {
        $u = $this->user();
        Sanctum::actingAs($u);
        $p = $this->postJson('/api/v1/projects', ['name' => 'Writing', 'instructions' => 'Use simple words'])->assertCreated();
        $this->postJson('/api/v1/memories', ['content' => 'I prefer examples'])->assertCreated();
        $this->patchJson('/api/v1/preferences', ['custom_instructions' => 'Be concise', 'memory_enabled' => true])->assertOk();
        $u->refresh();
        Sanctum::actingAs($u);
        $this->fakeStream();
        $this->postJson('/api/v1/chat/stream', ['model_id' => $this->model()->id, 'message' => 'Hi', 'project_id' => $p->json('id')])->streamedContent();
        Http::assertSent(fn ($r) => str_contains(json_encode($r['messages']), 'Use simple words') && str_contains(json_encode($r['messages']), 'I prefer examples'));
        $other = $this->user();
        Sanctum::actingAs($other);
        $this->putJson('/api/v1/projects/'.$p->json('id'), ['name' => 'Stolen'])->assertNotFound();
    }

    public function test_attachment_context_is_owned_and_survives_next_turn(): void
    {
        $u = $this->user();
        Sanctum::actingAs($u);
        $f = $this->postJson('/api/v1/attachments', ['name' => 'example.py', 'content' => 'print("private attachment")'])->assertCreated();
        $this->fakeStream();
        $r = $this->postJson('/api/v1/chat/stream', ['model_id' => $this->model()->id, 'message' => 'Explain', 'attachment_ids' => [$f->json('id')]]);
        $r->streamedContent();
        $c = Conversation::first();
        $this->assertSame([$f->json('id')], $c->messages()->where('role', 'user')->first()->attachment_ids);
        $this->postJson('/api/v1/chat/stream', ['model_id' => AiModel::first()->id, 'message' => 'Again', 'conversation_id' => $c->id])->streamedContent();
        Http::assertSentCount(2);
        $this->assertStringContainsString('private attachment', json_encode(Http::recorded()->last()[0]['messages']));
        Sanctum::actingAs($this->user());
        $this->postJson('/api/v1/chat/stream', ['model_id' => AiModel::first()->id, 'message' => 'Read', 'attachment_ids' => [$f->json('id')]])->assertNotFound();
    }

    public function test_share_is_snapshot_and_can_be_revoked(): void
    {
        $u = $this->user();
        Sanctum::actingAs($u);
        $c = Conversation::create(['user_id' => $u->id, 'title' => 'Share']);
        $c->messages()->create(['role' => 'user', 'content' => 'Original']);
        $r = $this->postJson('/api/v1/conversations/'.$c->id.'/share')->assertOk();
        $c->messages()->create(['role' => 'assistant', 'content' => 'Later private answer']);
        $this->getJson('/api/v1/shared/'.$r->json('token'))->assertJsonCount(1, 'messages');
        $this->deleteJson('/api/v1/shares/'.$r->json('id'))->assertNoContent();
        $this->getJson('/api/v1/shared/'.$r->json('token'))->assertNotFound();
    }

    public function test_feedback_and_export_do_not_expose_authentication_secrets(): void
    {
        $u = $this->user();
        Sanctum::actingAs($u);
        $c = Conversation::create(['user_id' => $u->id, 'title' => 'Chat']);
        $m = $c->messages()->create(['role' => 'assistant', 'content' => 'Answer']);
        $this->putJson('/api/v1/conversations/'.$c->id.'/messages/'.$m->id.'/feedback', ['feedback' => 'up'])->assertOk()->assertJsonPath('feedback', 'up');
        $this->getJson('/api/v1/export')->assertOk()->assertJsonMissingPath('user.password')->assertJsonMissingPath('user.two_factor_secret');
    }

    public function test_canvas_updates_reject_stale_versions_and_other_users(): void
    {
        Sanctum::actingAs($this->user());
        $c = $this->postJson('/api/v1/canvases', ['title' => 'Draft', 'format' => 'markdown', 'content' => 'First'])->assertCreated();
        $id = $c->json('id');
        $body = ['title' => 'Draft', 'format' => 'markdown', 'content' => 'Second', 'version' => 1];
        $this->putJson('/api/v1/canvases/'.$id, $body)->assertOk()->assertJsonPath('version', 2);
        $this->putJson('/api/v1/canvases/'.$id, $body)->assertStatus(409);
        Sanctum::actingAs($this->user());
        $this->getJson('/api/v1/canvases/'.$id)->assertNotFound();
    }

    public function test_search_is_unavailable_when_all_providers_are_disabled(): void
    {
        Sanctum::actingAs($this->user());
        config(['models.search_url' => null, 'models.public_search_fallback' => false]);
        Http::preventStrayRequests();
        $this->getJson('/api/v1/capabilities')->assertOk()->assertJsonPath('web_search', false);
        $this->postJson('/api/v1/web-search', ['query' => 'test'])->assertStatus(503);
        Http::assertNothingSent();
    }

    public function test_search_snippets_are_sanitized_and_sources_persist(): void
    {
        Sanctum::actingAs($this->user());
        config(['models.search_url' => 'http://search.local/search']);
        $stream = 'data: '.json_encode(['choices' => [['delta' => ['content' => 'Answer']]]])."\n\ndata: [DONE]\n\n";
        Http::fake(['search.local/*' => Http::response(['results' => [['title' => '<b>Reference</b>', 'url' => 'https://example.com/reference', 'content' => 'A source snippet'], ['title' => 'Bad', 'url' => 'javascript:alert(1)', 'content' => 'Bad']]]), '*' => Http::response($stream, 200, ['Content-Type' => 'text/event-stream'])]);
        $r = $this->postJson('/api/v1/chat/stream', ['model_id' => $this->model()->id, 'message' => 'Question', 'web_search' => true]);
        $this->assertStringContainsString('https:', $r->streamedContent());
        $answer = Message::where('role', 'assistant')->first();
        $this->assertCount(1, $answer->sources);
        $this->assertSame('Reference', $answer->sources[0]['title']);
    }

    public function test_failed_turn_does_not_break_future_model_role_alternation(): void
    {
        Sanctum::actingAs($this->user());
        $model = $this->model();
        $sse = 'data: '.json_encode(['choices' => [['delta' => ['content' => 'Recovered answer']]]])."\n\ndata: [DONE]\n\n";
        Http::fake(['*' => Http::sequence()->push([], 500)->push($sse, 200, ['Content-Type' => 'text/event-stream'])]);
        $this->postJson('/api/v1/chat/stream', ['model_id' => $model->id, 'message' => 'Failed question'])->streamedContent();
        $conversation = Conversation::first();
        $this->postJson('/api/v1/chat/stream', ['model_id' => $model->id, 'message' => 'New question', 'conversation_id' => $conversation->id])->streamedContent();
        $messages = Http::recorded()->last()[0]['messages'];
        $this->assertCount(2, $messages);
        $this->assertSame('New question', $messages[1]['content']);
        $this->assertSame('system', $messages[0]['role']);
        $this->assertDatabaseHas('messages', ['role' => 'assistant', 'content' => 'Recovered answer', 'status' => 'complete']);
    }

    public function test_truncated_sse_keeps_partial_text_with_failed_status(): void
    {
        Sanctum::actingAs($this->user());
        $data = 'data: '.json_encode(['choices' => [['delta' => ['content' => 'Partial answer']]]])."\n\n";
        Http::fake(['*' => Http::response($data, 200, ['Content-Type' => 'text/event-stream'])]);
        $r = $this->postJson('/api/v1/chat/stream', ['model_id' => $this->model()->id, 'message' => 'Question']);
        $this->assertStringContainsString('event: error', $r->streamedContent());
        $this->assertDatabaseHas('messages', ['role' => 'assistant', 'content' => 'Partial answer', 'status' => 'failed']);
    }
}
