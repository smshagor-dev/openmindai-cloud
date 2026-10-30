<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MobileChatThinkingTest extends TestCase
{
    use RefreshDatabase;

    private function token(): string
    {
        $user = User::create(['name' => 'Thinking', 'email' => uniqid().'@example.com', 'password' => 'TestPassword123']);
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user->createToken('Mobile', ['user'])->plainTextToken;
    }

    private function send(array $extra): void
    {
        config(['models.nvidia_key' => 'test-provider-secret-123']);
        DB::table('hosted_code_agents')->where('slug', 'openmindai-lite')
            ->update(['enabled' => true, 'api_ready' => true]);
        Http::fake(['integrate.api.nvidia.com/*' => Http::response([
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'Hi.']]],
        ])]);
        $this->withToken($this->token())->postJson('/api/v1/mobile/chat/completions', [
            'model' => 'openmindai-lite',
            'messages' => [['role' => 'user', 'content' => 'Say hi']],
            'stream' => false,
        ] + $extra)->assertOk();
    }

    public function test_mobile_chat_can_disable_hidden_reasoning(): void
    {
        $this->send(['chat_template_kwargs' => ['enable_thinking' => false]]);
        Http::assertSent(fn ($request) => $request['chat_template_kwargs'] === ['enable_thinking' => false]);
    }

    public function test_reasoning_setting_is_not_sent_unless_requested(): void
    {
        $this->send([]);
        Http::assertSent(fn ($request) => ! isset($request['chat_template_kwargs']));
    }

    public function test_only_the_thinking_switch_is_accepted(): void
    {
        Http::fake();
        $this->withToken($this->token())->postJson('/api/v1/mobile/chat/completions', [
            'model' => 'openmindai-lite',
            'messages' => [['role' => 'user', 'content' => 'Say hi']],
            'chat_template_kwargs' => ['enable_thinking' => false, 'other' => 'x'],
        ])->assertStatus(422);
        Http::assertNothingSent();
    }
}
