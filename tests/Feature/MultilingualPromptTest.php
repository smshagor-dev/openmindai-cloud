<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ChatContext;
use App\Services\MultilingualPrompt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MultilingualPromptTest extends TestCase
{
    use RefreshDatabase;

    public function test_chat_context_keeps_mixed_language_prompt_verbatim(): void
    {
        $user = User::create(['name' => 'Language test', 'email' => 'languages@example.com', 'password' => 'StrongPassword123']);
        $request = 'আগের code ta ঠিক koro, but amar API name `OpenMindAI` change korba na.';
        $messages = app(ChatContext::class)->build($user, null, $request);

        $this->assertSame($request, $messages[count($messages) - 1]['content']);
        $this->assertStringContainsString(MultilingualPrompt::MARKER, $messages[0]['content']);
        $this->assertStringContainsString('Banglish', $messages[0]['content']);
        $this->assertSame('system', $messages[0]['role']);
    }

    public function test_guidance_is_added_once_without_changing_message_roles_or_content(): void
    {
        foreach (['¿Puedes revisar este código?', 'هل يمكنك شرح هذا؟', '请帮我检查这个函数', 'ami ekta report chai'] as $prompt) {
            $input = [
                ['role' => 'system', 'content' => 'Keep answers accurate.'],
                ['role' => 'user', 'content' => $prompt],
            ];
            $once = MultilingualPrompt::apply($input);
            $twice = MultilingualPrompt::apply($once);

            $this->assertSame($prompt, $once[1]['content']);
            $this->assertSame('Keep answers accurate.', $input[0]['content']);
            $this->assertSame($once, $twice);
            $this->assertSame(1, substr_count($once[0]['content'], MultilingualPrompt::MARKER));
        }
    }

    public function test_prompts_without_system_context_receive_safe_system_guidance(): void
    {
        $messages = MultilingualPrompt::apply([['role' => 'user', 'content' => 'नमस्ते, कैसे हो?']]);

        $this->assertCount(2, $messages);
        $this->assertSame('system', $messages[0]['role']);
        $this->assertSame('नमस्ते, कैसे हो?', $messages[1]['content']);
    }

    public function test_guest_chat_sends_multilingual_instructions_and_preserves_history(): void
    {
        config(['models.nvidia_key' => 'provider-test-secret']);
        Http::fake(['integrate.api.nvidia.com/*' => Http::response(['choices' => [['message' => ['content' => 'ঠিক আছে']]]])]);
        $prompt = 'aajker weather na, শুধু React code explain koro';
        $this->postJson('/api/v1/guest/chat', [
            'message' => $prompt,
            'history' => [['role' => 'user', 'content' => 'hola'], ['role' => 'assistant', 'content' => 'Hola']],
        ])->assertOk()->assertJsonPath('content', 'ঠিক আছে');

        Http::assertSent(fn ($request) => $request['messages'][0]['role'] === 'system'
            && str_contains($request['messages'][0]['content'], MultilingualPrompt::MARKER)
            && $request['messages'][1]['content'] === 'hola'
            && $request['messages'][3]['content'] === $prompt);
    }
}
