<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CloudToolCallingTest extends TestCase
{
    use RefreshDatabase;

    private function developerKey(): string
    {
        $user = User::create([
            'name' => 'Studio Tester',
            'email' => 'studio-tools@example.com',
            'password' => 'SecurePassword123',
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user->createToken('developer:Studio', ['openmindai:invoke'])->plainTextToken;
    }

    private function tools(int $count): array
    {
        $tools = [];
        for ($i = 0; $i < $count; $i++) {
            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => 'studio_tool_'.$i,
                    'description' => 'Execute Studio tool '.$i,
                    'parameters' => [
                        'type' => 'object',
                        'properties' => ['path' => ['type' => 'string']],
                    ],
                ],
            ];
        }

        return $tools;
    }

    private function providerResponse(): array
    {
        return [
            'id' => 'test-completion',
            'choices' => [[
                'index' => 0,
                'message' => [
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => [[
                        'id' => 'call_18',
                        'type' => 'function',
                        'function' => ['name' => 'studio_tool_18', 'arguments' => '{"path":"test.txt"}'],
                    ]],
                ],
                'finish_reason' => 'tool_calls',
            ]],
        ];
    }

    public function test_all_nineteen_studio_tools_are_forwarded_without_truncation(): void
    {
        config(['models.nvidia_key' => 'server-nvidia-token-123']);
        Http::fake(['integrate.api.nvidia.com/*' => Http::response($this->providerResponse())]);
        $this->withToken($this->developerKey())
            ->postJson('/api/v1/openmindai/chat/completions', [
                'model' => 'openmindai-lite',
                'messages' => [['role' => 'user', 'content' => 'Read a file']],
                'tools' => $this->tools(19),
                'stream' => false,
            ])
            ->assertOk()
            ->assertJsonPath('choices.0.message.tool_calls.0.function.name', 'studio_tool_18');

        Http::assertSent(fn ($r) => $r->url() === 'https://integrate.api.nvidia.com/v1/chat/completions'
            && count($r['tools']) === 19
            && $r['tools'][0]['function']['name'] === 'studio_tool_0'
            && $r['tools'][18]['function']['name'] === 'studio_tool_18'
            && $r['model'] === 'nvidia/nemotron-3.5-lightning-30b-a3b');
        Http::assertSentCount(1);
    }

    public function test_assistant_messages_with_nineteen_tool_calls_keep_all_ids(): void
    {
        config(['models.nvidia_key' => 'server-nvidia-token-123']);
        Http::fake(['integrate.api.nvidia.com/*' => Http::response($this->providerResponse())]);
        $calls = [];
        for ($i = 0; $i < 19; $i++) {
            $calls[] = [
                'id' => 'call_'.$i,
                'type' => 'function',
                'function' => ['name' => 'studio_tool_'.$i, 'arguments' => '{}'],
            ];
        }
        $this->withToken($this->developerKey())
            ->postJson('/api/v1/openmindai/chat/completions', [
                'model' => 'openmindai-super',
                'messages' => [
                    ['role' => 'user', 'content' => 'Complete this task'],
                    ['role' => 'assistant', 'content' => null, 'tool_calls' => $calls],
                    ['role' => 'tool', 'tool_call_id' => 'call_18', 'content' => 'done'],
                    ['role' => 'user', 'content' => 'Continue'],
                ],
                'tools' => $this->tools(19),
            ])
            ->assertOk();
        Http::assertSent(fn ($r) => count($r['messages'][1]['tool_calls']) === 19
            && $r['messages'][1]['tool_calls'][18]['id'] === 'call_18'
            && $r['messages'][2]['tool_call_id'] === 'call_18');
    }

    public function test_streaming_forwards_all_nineteen_tools_and_completes_sse(): void
    {
        config(['models.nvidia_key' => 'server-nvidia-token-123']);
        $stream = "data: {\"choices\":[{\"index\":0,\"delta\":{\"content\":\"OK\"},\"finish_reason\":null}]}\n\n"
            ."data: [DONE]\n\n";
        Http::fake(['integrate.api.nvidia.com/*' => Http::response($stream, 200, ['Content-Type' => 'text/event-stream'])]);

        $result = $this->withToken($this->developerKey())
            ->postJson('/api/v1/openmindai/chat/completions', [
                'model' => 'openmindai-super',
                'messages' => [['role' => 'user', 'content' => 'Use your editor tools']],
                'tools' => $this->tools(19),
                'stream' => true,
            ])->assertOk()->assertHeader('Content-Type', 'text/event-stream; charset=utf-8');

        $this->assertStringContainsString('data: [DONE]', $result->streamedContent());
        Http::assertSent(fn ($r) => count($r['tools']) === 19
            && $r['tools'][18]['function']['name'] === 'studio_tool_18'
            && $r['stream'] === true);
    }

    public function test_up_to_sixty_four_tools_are_accepted(): void
    {
        config(['models.nvidia_key' => 'server-nvidia-token-123']);
        Http::fake(['integrate.api.nvidia.com/*' => Http::response($this->providerResponse())]);
        $this->withToken($this->developerKey())
            ->postJson('/api/v1/openmindai/chat/completions', [
                'model' => 'openmindai-ultra',
                'messages' => [['role' => 'user', 'content' => 'Hello']],
                'tools' => $this->tools(64),
            ])->assertOk();
        Http::assertSent(fn ($r) => count($r['tools']) === 64);
    }

    public function test_more_than_sixty_four_tools_are_rejected_locally(): void
    {
        config(['models.nvidia_key' => 'server-nvidia-token-123']);
        Http::fake();
        $this->withToken($this->developerKey())
            ->postJson('/api/v1/openmindai/chat/completions', [
                'model' => 'openmindai-lite',
                'messages' => [['role' => 'user', 'content' => 'Hello']],
                'tools' => $this->tools(65),
            ])->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_provider_schema_rejection_reports_actionable_422_without_raw_body(): void
    {
        config(['models.nvidia_key' => 'server-nvidia-token-123']);
        Http::fake(['integrate.api.nvidia.com/*' => Http::response([
            'error' => 'private upstream diagnostic, should not be exposed',
        ], 422)]);
        $this->withToken($this->developerKey())
            ->postJson('/api/v1/openmindai/chat/completions', [
                'model' => 'openmindai-lite',
                'messages' => [['role' => 'user', 'content' => 'Run tools']],
                'tools' => $this->tools(19),
            ])->assertUnprocessable()
            ->assertJsonPath('error.type', 'upstream_error')
            ->assertJsonPath('error.upstream_status', 422)
            ->assertDontSee('private upstream diagnostic, should not be exposed');
    }

    public function test_oversized_tool_payload_rejected_before_upstream(): void
    {
        config(['models.nvidia_key' => 'server-nvidia-token-123']);
        Http::fake();
        $tools = $this->tools(19);
        $tools[0]['function']['description'] = str_repeat('x', 1050000);
        $this->withToken($this->developerKey())
            ->postJson('/api/v1/openmindai/chat/completions', [
                'model' => 'openmindai-lite',
                'messages' => [['role' => 'user', 'content' => 'Hello']],
                'tools' => $tools,
            ])->assertStatus(413);
        Http::assertNothingSent();
    }
}
