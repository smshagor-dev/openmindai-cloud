<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\AdminSettings;
use App\Services\HostedChatActivity;
use App\Services\WebResearch;
use App\Services\WebSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FrontendChatTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::create(['name' => 'Bridge', 'email' => uniqid().'@example.com', 'password' => 'StrongPassword123']);
    }

    private function headers(): array
    {
        return ['X-Chat-Bridge-Key' => str_repeat('b', 32)];
    }

    private function authenticate(): User
    {
        config(['models.frontend_chat_key' => str_repeat('b', 32)]);
        $u = $this->user();
        Sanctum::actingAs($u);

        return $u;
    }

    public function test_frontend_prepares_context_and_saves_completed_answer_without_backend_inference(): void
    {
        $this->authenticate();
        Http::fake();
        $r = $this->postJson('/api/v1/chat/frontend/begin', ['message' => 'Hello'], $this->headers())->assertOk();
        $ticket = $r->json('ticket');
        $id = $r->json('message_id');
        $this->assertDatabaseHas('messages', ['id' => $id, 'status' => 'generating']);
        $this->postJson('/api/v1/chat/frontend/finish', ['ticket' => $ticket, 'content' => 'NVIDIA answer', 'status' => 'complete'], $this->headers())->assertOk()->assertJsonPath('stored', true);
        $this->assertDatabaseHas('messages', ['id' => $id, 'content' => 'NVIDIA answer', 'status' => 'complete']);
        Http::assertNothingSent();
        $this->postJson('/api/v1/chat/frontend/finish', ['ticket' => $ticket, 'content' => 'Duplicate replacement', 'status' => 'complete'], $this->headers())->assertOk();
        $this->assertSame('NVIDIA answer', Message::find($id)->content);
    }

    public function test_bridge_key_and_user_ownership_are_required(): void
    {
        $this->authenticate();
        $this->postJson('/api/v1/chat/frontend/begin', ['message' => 'Hello'])->assertForbidden();
        $r = $this->postJson('/api/v1/chat/frontend/begin', ['message' => 'Secret'], $this->headers())->assertOk();
        Sanctum::actingAs($this->user());
        $this->postJson('/api/v1/chat/frontend/finish', ['ticket' => $r->json('ticket'), 'content' => 'Wrong owner', 'status' => 'complete'], $this->headers())->assertStatus(410);
    }

    public function test_temporary_chat_saves_no_messages_and_ignores_private_preferences(): void
    {
        $u = $this->authenticate();
        $u->forceFill(['custom_instructions' => 'PRIVATE RULE'])->save();
        $r = $this->postJson('/api/v1/chat/frontend/begin', ['message' => 'Temporary', 'temporary' => true, 'history' => [['role' => 'user', 'content' => 'Old'], ['role' => 'assistant', 'content' => 'Answer']]], $this->headers())->assertOk();
        $this->assertStringNotContainsString('PRIVATE RULE', json_encode($r->json('messages')));
        $this->postJson('/api/v1/chat/frontend/finish', ['ticket' => $r->json('ticket'), 'content' => 'Temp answer', 'status' => 'complete'], $this->headers())->assertOk();
        $this->assertDatabaseCount('messages', 0);
        $this->assertDatabaseCount('conversations', 0);
    }

    public function test_stop_and_failed_partial_response_release_the_user_lock(): void
    {
        $this->authenticate();
        $r = $this->postJson('/api/v1/chat/frontend/begin', ['message' => 'Hello'], $this->headers())->assertOk();
        $this->postJson('/api/v1/chat/frontend/begin', ['message' => 'Overlap'], $this->headers())->assertStatus(409);
        Cache::put('stop-message:'.$r->json('message_id'), true, 180);
        $this->postJson('/api/v1/chat/frontend/status', ['ticket' => $r->json('ticket')], $this->headers())->assertJsonPath('cancelled', true);
        $this->postJson('/api/v1/chat/frontend/finish', ['ticket' => $r->json('ticket'), 'content' => 'Partial', 'status' => 'cancelled'], $this->headers())->assertOk();
        $this->assertDatabaseHas('messages', ['content' => 'Partial', 'status' => 'cancelled']);
        $this->postJson('/api/v1/chat/frontend/begin', ['message' => 'Next'], $this->headers())->assertOk();
    }

    public function test_empty_failed_response_is_finalized_and_releases_lock(): void
    {
        $this->authenticate();
        $r = $this->postJson('/api/v1/chat/frontend/begin', ['message' => 'Hello'], $this->headers())->assertOk();
        $this->postJson('/api/v1/chat/frontend/finish', ['ticket' => $r->json('ticket'), 'content' => '', 'status' => 'complete'], $this->headers())->assertStatus(422);
        $this->postJson('/api/v1/chat/frontend/finish', ['ticket' => $r->json('ticket'), 'content' => '', 'status' => 'failed'], $this->headers())->assertOk();
        $this->assertDatabaseHas('messages', ['id' => $r->json('message_id'), 'content' => '', 'status' => 'failed']);
        $this->postJson('/api/v1/chat/frontend/begin', ['message' => 'Next'], $this->headers())->assertOk();
    }

    public function test_daily_chat_quota_is_optional_and_can_be_enabled_explicitly(): void
    {
        $user = $this->authenticate();
        $request = Request::create('/api/v1/chat');
        $request->setUserResolver(fn () => $user);
        app(AdminSettings::class)->save(['chat_daily_limit' => '0']);
        $this->assertCount(1, (RateLimiter::limiter('chat'))($request));
        app(AdminSettings::class)->save(['chat_daily_limit' => '2']);
        $this->assertCount(2, (RateLimiter::limiter('chat'))($request));
    }

    public function test_daily_limit_blocks_new_requests_but_allows_an_active_turn_to_finish(): void
    {
        $this->authenticate();
        app(AdminSettings::class)->save(['chat_daily_limit' => '1']);
        $r = $this->postJson('/api/v1/chat/frontend/begin', ['message' => 'First request'], $this->headers())->assertOk();
        $this->postJson('/api/v1/chat/frontend/status', ['ticket' => $r->json('ticket')], $this->headers())->assertOk();
        $this->postJson('/api/v1/chat/frontend/finish', ['ticket' => $r->json('ticket'), 'content' => 'Complete answer', 'status' => 'complete'], $this->headers())->assertOk();
        $this->postJson('/api/v1/chat/frontend/begin', ['message' => 'Over daily quota'], $this->headers())->assertStatus(429);
        $this->assertDatabaseCount('messages', 2);
    }

    public function test_large_answer_and_new_source_summaries_are_saved_without_the_old_text_cap(): void
    {
        $this->authenticate();
        $r = $this->postJson('/api/v1/chat/frontend/begin', ['message' => 'Long report'], $this->headers())->assertOk();
        $answer = str_repeat('Evidence. ', 30000);
        $this->postJson('/api/v1/chat/frontend/finish', ['ticket' => $r->json('ticket'), 'content' => $answer, 'status' => 'complete', 'sources' => [['url' => 'https://example.org/paper', 'title' => '', 'snippet' => '', 'citation' => 1, 'read_status' => 'read', 'page_text' => 'Not retained']]], $this->headers())->assertOk();
        $saved = Message::find($r->json('message_id'));
        $this->assertSame(hash('sha256', $answer), hash('sha256', $saved->content));
        $this->assertArrayNotHasKey('page_text', $saved->sources[0]);
    }

    public function test_finished_source_passages_and_pdf_provenance_survive_the_real_history_endpoint(): void
    {
        $this->authenticate();
        Http::preventStrayRequests();
        $begin = $this->postJson('/api/v1/chat/frontend/begin', ['message' => 'Evidence persistence', 'deep_research' => true, 'conversation_tools' => true], $this->headers())->assertOk();
        $source = ['url' => 'https://example.org/paper.pdf', 'title' => 'Paper', 'citation' => 1, 'read_status' => 'read', 'provider' => 'fixture', 'retrieved_at' => now()->toIso8601String(), 'mime' => 'application/pdf', 'final_url' => 'https://example.org/paper.pdf', 'duplicate_of' => null, 'passages' => [['id' => 'p1', 'text' => 'Selected বাংলা evidence', 'page' => 24]], 'pdf' => ['total_pages' => 24, 'sampled_pages' => [1, 24], 'partial' => true, 'ocr' => false], 'page_text' => 'Raw page must not persist', 'private_field' => 'Not approved metadata'];
        $this->postJson('/api/v1/chat/frontend/finish', ['ticket' => $begin->json('ticket'), 'content' => 'Report [1]', 'status' => 'complete', 'sources' => [$source]], $this->headers())->assertOk();
        $saved = Message::findOrFail($begin->json('message_id'))->sources[0];
        $this->assertSame('Selected বাংলা evidence', $saved['passages'][0]['text']);
        $this->assertSame(24, $saved['passages'][0]['page']);
        $this->assertSame([1, 24], $saved['pdf']['sampled_pages']);
        $this->assertSame('fixture', $saved['provider']);
        $this->assertArrayNotHasKey('page_text', $saved);
        $this->assertArrayNotHasKey('private_field', $saved);
        $this->getJson('/api/v1/conversations/'.$begin->json('conversation_id'))->assertOk()
            ->assertJsonPath('messages.data.1.sources.0.passages.0.page', 24)
            ->assertJsonPath('messages.data.1.sources.0.passages.0.text', 'Selected বাংলা evidence')
            ->assertJsonPath('messages.data.1.sources.0.pdf.total_pages', 24);
    }

    public function test_finish_rejects_oversized_or_malformed_provenance_before_overwriting_saved_evidence(): void
    {
        $this->authenticate();
        Http::preventStrayRequests();
        $begin = $this->postJson('/api/v1/chat/frontend/begin', ['message' => 'Bounded evidence', 'deep_research' => true, 'conversation_tools' => true], $this->headers())->assertOk();
        foreach ([['passages' => array_fill(0, 9, ['text' => 'Evidence'])], ['passages' => [['text' => 'Evidence', 'page' => 5001]]], ['pdf' => ['sampled_pages' => 'malformed']]] as $invalid) {
            $this->postJson('/api/v1/chat/frontend/finish', ['ticket' => $begin->json('ticket'), 'content' => 'Invalid', 'status' => 'complete', 'sources' => [['url' => 'https://example.org/paper', 'title' => 'Paper', ...$invalid]]], $this->headers())->assertUnprocessable();
            $this->assertSame('generating', Message::findOrFail($begin->json('message_id'))->status);
        }
    }

    public function test_research_tool_requires_a_live_owned_research_lease(): void
    {
        $this->authenticate();
        $this->postJson('/api/v1/chat/frontend/tool', ['ticket' => str_repeat('a', 64), 'action' => 'search', 'query' => 'Question'], $this->headers())->assertStatus(410);
        $r = $this->postJson('/api/v1/chat/frontend/begin', ['message' => 'Plain chat'], $this->headers())->assertOk();
        $this->postJson('/api/v1/chat/frontend/tool', ['ticket' => $r->json('ticket'), 'action' => 'search', 'query' => 'Question'], $this->headers())->assertStatus(409);
    }

    public function test_abandoned_hosted_lock_is_recovered_without_releasing_another_owner(): void
    {
        $user = $this->authenticate();
        $lock = Cache::lock('chat-user:'.$user->id, 86400);
        $this->assertTrue($lock->get());
        app(HostedChatActivity::class)->heartbeat($user->id, $lock->owner());
        $this->travel(11)->minutes();
        $this->postJson('/api/v1/chat/frontend/begin', ['message' => 'Recovered'], $this->headers())->assertOk();
        app(HostedChatActivity::class)->recover($user->id);
        $this->postJson('/api/v1/chat/frontend/begin', ['message' => 'Overlap'], $this->headers())->assertStatus(409);
    }

    public function test_search_tools_are_available_only_during_active_owned_research(): void
    {
        $this->authenticate();
        $this->partialMock(WebResearch::class)->shouldReceive('collect')->once()->andReturn([]);
        $this->mock(WebSearch::class)->shouldReceive('search')->times(65)->andReturn([['url' => 'https://example.org/new', 'title' => 'New evidence', 'snippet' => 'Summary']]);
        $r = $this->postJson('/api/v1/chat/frontend/begin', ['message' => 'Research', 'deep_research' => true], $this->headers())->assertOk();
        $payload = ['ticket' => $r->json('ticket'), 'action' => 'search', 'query' => 'Missing evidence'];
        for ($i = 0; $i < 65; $i++) {
            $this->postJson('/api/v1/chat/frontend/tool', [...$payload, 'query' => 'Evidence '.$i], $this->headers())->assertOk()->assertJsonPath('sources.0.title', 'New evidence');
        }
        $this->postJson('/api/v1/chat/frontend/finish', ['ticket' => $r->json('ticket'), 'content' => 'Done', 'status' => 'complete'], $this->headers())->assertOk();
        $this->postJson('/api/v1/chat/frontend/tool', $payload, $this->headers())->assertStatus(409);
    }

    public function test_native_conversation_tools_use_an_owned_active_lease_without_research_mode(): void
    {
        $this->authenticate();
        $this->mock(WebSearch::class)->shouldReceive('search')->once()->with('Evidence', ['limit' => 10, 'time_range' => null])->andReturn([]);
        $r = $this->postJson('/api/v1/chat/frontend/begin', ['message' => 'Find evidence', 'conversation_tools' => true], $this->headers())->assertOk();
        $payload = ['ticket' => $r->json('ticket'), 'action' => 'search', 'query' => 'Evidence'];
        $this->postJson('/api/v1/chat/frontend/tool', $payload, $this->headers())->assertOk();
        $this->assertFalse(Message::findOrFail($r->json('message_id'))->resumable);
        $this->postJson('/api/v1/chat/frontend/finish', ['ticket' => $r->json('ticket'), 'content' => 'Partial', 'status' => 'failed'], $this->headers())->assertOk();
        $this->postJson('/api/v1/chat/frontend/tool', $payload, $this->headers())->assertStatus(409);
        $this->postJson('/api/v1/chat/frontend/resume', ['message_id' => $r->json('message_id')], $this->headers())->assertStatus(409);
    }

    public function test_deleted_conversation_is_not_recreated_by_late_finish(): void
    {
        $this->authenticate();
        $r = $this->postJson('/api/v1/chat/frontend/begin', ['message' => 'Hello'], $this->headers())->assertOk();
        Conversation::find($r->json('conversation_id'))->delete();
        $this->postJson('/api/v1/chat/frontend/finish', ['ticket' => $r->json('ticket'), 'content' => 'Late answer', 'status' => 'complete'], $this->headers())->assertStatus(410);
        $this->assertDatabaseCount('messages', 0);
        $this->assertDatabaseCount('conversations', 0);
    }

    public function test_interrupted_chat_resumes_same_message_with_encrypted_private_context(): void
    {
        $this->authenticate();
        $first = $this->postJson('/api/v1/chat/frontend/begin', ['message' => 'Private project'], $this->headers())->assertOk();
        $id = $first->json('message_id');
        $state = ['stage' => 'writing', 'content' => 'Partial', 'messages' => [['role' => 'system', 'content' => 'SECRET RESEARCH']], 'sources' => []];
        $this->postJson('/api/v1/chat/frontend/checkpoint', ['ticket' => $first->json('ticket'), 'state' => $state], $this->headers())->assertOk();
        $m = Message::findOrFail($id);
        $this->assertStringNotContainsString('SECRET RESEARCH', $m->getRawOriginal('inference_checkpoint'));
        $this->assertArrayNotHasKey('inference_checkpoint', $m->toArray());
        $this->postJson('/api/v1/chat/frontend/finish', ['ticket' => $first->json('ticket'), 'content' => 'Partial', 'status' => 'failed'], $this->headers())->assertOk();
        $this->assertTrue($m->fresh()->resumable);
        $resumed = $this->postJson('/api/v1/chat/frontend/resume', ['message_id' => $id], $this->headers())->assertOk()->assertJsonPath('message_id', $id)->assertJsonPath('checkpoint.content', 'Partial');
        $this->assertDatabaseCount('messages', 2);
        $this->assertNotSame($first->json('ticket'), $resumed->json('ticket'));
        $this->postJson('/api/v1/chat/frontend/finish', ['ticket' => $first->json('ticket'), 'content' => 'OLD WORKER', 'status' => 'complete'], $this->headers())->assertStatus(410);
        $this->postJson('/api/v1/chat/frontend/checkpoint', ['ticket' => $first->json('ticket'), 'state' => $state], $this->headers())->assertStatus(410);
        $this->postJson('/api/v1/chat/frontend/finish', ['ticket' => $resumed->json('ticket'), 'content' => 'Partial finished', 'status' => 'complete'], $this->headers())->assertOk();
        $this->assertNull($m->fresh()->inference_checkpoint);
        $this->assertFalse($m->fresh()->resumable);
    }

    public function test_live_resume_is_rejected_but_abandoned_stream_can_be_recovered(): void
    {
        $this->authenticate();
        $first = $this->postJson('/api/v1/chat/frontend/begin', ['message' => 'Hello'], $this->headers())->assertOk();
        $this->postJson('/api/v1/chat/frontend/resume', ['message_id' => $first->json('message_id')], $this->headers())->assertStatus(409);
        $this->travel(11)->minutes();
        $this->postJson('/api/v1/chat/frontend/resume', ['message_id' => $first->json('message_id')], $this->headers())->assertOk();
        $this->assertDatabaseCount('messages', 2);
    }

    public function test_resume_requires_owner_latest_turn_and_saved_chat(): void
    {
        $owner = $this->authenticate();
        $first = $this->postJson('/api/v1/chat/frontend/begin', ['message' => 'Hello'], $this->headers())->assertOk();
        $this->postJson('/api/v1/chat/frontend/finish', ['ticket' => $first->json('ticket'), 'content' => '', 'status' => 'failed'], $this->headers())->assertOk();
        Sanctum::actingAs($this->user());
        $this->postJson('/api/v1/chat/frontend/resume', ['message_id' => $first->json('message_id')], $this->headers())->assertNotFound();
        Sanctum::actingAs($owner);
        Conversation::find($first->json('conversation_id'))->messages()->create(['role' => 'user', 'content' => 'New turn']);
        $this->postJson('/api/v1/chat/frontend/resume', ['message_id' => $first->json('message_id')], $this->headers())->assertStatus(409);
        $temporary = $this->postJson('/api/v1/chat/frontend/begin', ['message' => 'Private', 'temporary' => true], $this->headers())->assertOk();
        $this->postJson('/api/v1/chat/frontend/checkpoint', ['ticket' => $temporary->json('ticket'), 'state' => ['content' => 'PRIVATE']], $this->headers())->assertOk()->assertJsonPath('stored', false);
        $this->assertDatabaseCount('messages', 3);
    }

    public function test_resume_does_not_charge_another_daily_chat_request(): void
    {
        $this->authenticate();
        app(AdminSettings::class)->save(['chat_daily_limit' => '1']);
        $first = $this->postJson('/api/v1/chat/frontend/begin', ['message' => 'One request'], $this->headers())->assertOk();
        $this->postJson('/api/v1/chat/frontend/finish', ['ticket' => $first->json('ticket'), 'content' => '', 'status' => 'failed'], $this->headers())->assertOk();
        $this->postJson('/api/v1/chat/frontend/begin', ['message' => 'New request'], $this->headers())->assertStatus(429);
        $this->postJson('/api/v1/chat/frontend/resume', ['message_id' => $first->json('message_id')], $this->headers())->assertOk();
        $this->assertDatabaseCount('messages', 2);
    }
}
