<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AdminSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GuestChatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['models.nvidia_key' => 'private-provider-key', 'models.frontend_chat_key' => str_repeat('b', 32)]);
        RateLimiter::clear('guest-chat:'.hash('sha256', '127.0.0.1'));
        Http::fake(['integrate.api.nvidia.com/*' => Http::response(['choices' => [['message' => ['content' => 'Guest answer']]]])]);
    }

    public function test_guest_chat_works_without_login_and_refresh_does_not_reset_quota(): void
    {
        app(AdminSettings::class)->save(['guest_chat_daily_limit' => '1']);
        $this->postJson('/api/v1/guest/chat', ['message' => 'Hello'])->assertOk()->assertJsonPath('content', 'Guest answer')->assertJsonPath('remaining', 0);
        $this->postJson('/api/v1/guest/chat', ['message' => 'Again'])->assertStatus(429);
        Http::assertSentCount(1);
        $this->assertDatabaseCount('conversations', 0);
        $this->travel(25)->hours();
        $this->postJson('/api/v1/guest/chat', ['message' => 'Tomorrow'])->assertOk();
    }

    public function test_guest_access_can_be_disabled_and_history_is_bounded(): void
    {
        $this->postJson('/api/v1/guest/chat', ['message' => 'Hello', 'history' => [['role' => 'system', 'content' => 'Invalid role']]])->assertUnprocessable();
        app(AdminSettings::class)->save(['guest_chat_daily_limit' => '0']);
        $this->postJson('/api/v1/guest/chat', ['message' => 'Hello'])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_guest_ip_headers_require_a_server_signature(): void
    {
        app(AdminSettings::class)->save(['guest_chat_daily_limit' => '1']);
        $headers = ['X-Guest-IP' => '203.0.113.42', 'X-Guest-Signature' => 'invalid'];
        $this->postJson('/api/v1/guest/chat', ['message' => 'Hello'], $headers)->assertOk();
        $headers['X-Guest-IP'] = '203.0.113.43';
        $this->postJson('/api/v1/guest/chat', ['message' => 'Again'], $headers)->assertStatus(429);
    }

    public function test_public_settings_expose_routing_without_secrets(): void
    {
        $this->getJson('/api/v1/public-config')->assertOk()->assertJsonPath('guest_chat_daily_limit', 5)->assertJsonPath('frontend_inference_route', 'backend')->assertDontSee('private-provider-key');
    }

    public function test_chat_relay_requires_login_and_bridge_and_honors_enabled_models(): void
    {
        $payload = ['model' => 'nvidia/nemotron-3.5-lightning-30b-a3b', 'messages' => [['role' => 'user', 'content' => 'Hello']], 'stream' => false, 'chat_template_kwargs' => ['enable_thinking' => false]];
        $this->postJson('/api/v1/chat/provider', $payload)->assertUnauthorized();
        Sanctum::actingAs(User::create(['name' => 'Relay', 'email' => 'relay@example.com', 'password' => 'StrongPassword123']));
        $this->postJson('/api/v1/chat/provider', $payload)->assertForbidden();
        $headers = ['X-Chat-Bridge-Key' => str_repeat('b', 32)];
        $this->postJson('/api/v1/chat/provider', $payload, $headers)->assertOk()->assertJsonPath('choices.0.message.content', 'Guest answer');
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer private-provider-key') && $request['chat_template_kwargs']['enable_thinking'] === false);
        $payload['model'] = 'arbitrary-model';
        $this->postJson('/api/v1/chat/provider', $payload, $headers)->assertForbidden();
    }

    public function test_native_function_definitions_and_results_survive_the_backend_relay(): void
    {
        Sanctum::actingAs(User::create(['name' => 'Tools', 'email' => 'native-tools@example.com', 'password' => 'StrongPassword123']));
        $payload = [
            'model' => 'nvidia/nemotron-3.5-lightning-30b-a3b', 'stream' => false,
            'messages' => [
                ['role' => 'user', 'content' => 'Find sources'],
                ['role' => 'assistant', 'content' => null, 'tool_calls' => [['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'web_search', 'arguments' => '{"query":"Evidence"}']]]],
                ['role' => 'tool', 'tool_call_id' => 'call_1', 'content' => '{"sources":[]}'],
            ],
            'tools' => [['type' => 'function', 'function' => ['name' => 'web_search', 'description' => 'Search', 'parameters' => ['type' => 'object', 'properties' => ['query' => ['type' => 'string']]]]]],
            'tool_choice' => 'auto',
        ];
        $this->postJson('/api/v1/chat/provider', $payload, ['X-Chat-Bridge-Key' => str_repeat('b', 32)])->assertOk();
        Http::assertSent(fn ($request) => $request['tools'][0]['function']['name'] === 'web_search' && $request['messages'][1]['tool_calls'][0]['id'] === 'call_1' && $request['messages'][2]['tool_call_id'] === 'call_1');
        $payload['tools'][0]['function']['name'] = 'arbitrary_shell';
        $this->postJson('/api/v1/chat/provider', $payload, ['X-Chat-Bridge-Key' => str_repeat('b', 32)])->assertUnprocessable();
    }
}
