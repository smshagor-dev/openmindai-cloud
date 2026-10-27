<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MobileVisionGatewayTest extends TestCase
{
    use RefreshDatabase;

    private function token(array $abilities = ['user']): string
    {
        $user = User::create(['name' => 'Vision', 'email' => uniqid().'@example.com', 'password' => 'TestPassword123']);
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user->createToken('Mobile', $abilities)->plainTextToken;
    }

    private function payload(): array
    {
        return [
            'model' => 'nvidia-llama-3.2-90b-vision-instruct',
            'messages' => [['role' => 'user', 'content' => [
                ['type' => 'text', 'text' => 'Explain this image'],
                ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aV7sAAAAASUVORK5CYII=']],
            ]]],
            'stream' => false,
        ];
    }

    public function test_mobile_catalog_advertises_vision_capability(): void
    {
        $response = $this->withToken($this->token())->getJson('/api/v1/mobile/models')->assertOk();
        $models = collect($response->json('data'))->keyBy('id');
        $this->assertTrue($models['nvidia-llama-3.2-90b-vision-instruct']['supports_vision']);
        $this->assertFalse($models['openmindai-lite']['supports_vision']);
    }

    public function test_mobile_image_bytes_reach_only_the_selected_vision_provider(): void
    {
        config(['models.nvidia_key' => 'test-provider-secret-123']);
        DB::table('hosted_code_agents')->where('slug', $this->payload()['model'])
            ->update(['enabled' => true, 'api_ready' => true]);
        Http::fake(['integrate.api.nvidia.com/*' => Http::response([
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'An image.']]],
        ])]);
        $payload = $this->payload();
        $this->withToken($this->token())->postJson('/api/v1/mobile/chat/completions', $payload)
            ->assertOk()->assertJsonPath('choices.0.message.content', 'An image.');
        Http::assertSent(fn ($request) => $request->url() === 'https://integrate.api.nvidia.com/v1/chat/completions'
            && $request['model'] === 'meta/llama-3.2-90b-vision-instruct'
            && $request['messages'][0]['role'] === 'user'
            && $request['messages'][0]['content'][2] === $payload['messages'][0]['content'][1]
            && str_contains($request['messages'][0]['content'][0]['text'], 'Multilingual request handling:'));
        $this->assertDatabaseCount('api_credit_usage', 1);
    }

    public function test_invalid_or_remote_images_are_rejected_before_provider_calls(): void
    {
        Http::fake();
        $this->withToken($this->token());
        foreach (['https://127.0.0.1/private.png', 'file:///private.png', 'data:image/png;base64,SGVsbG8=',
            'data:image/png;base64,'.str_repeat('A', 180000)] as $url) {
            $payload = $this->payload();
            $payload['messages'][0]['content'][1]['image_url']['url'] = $url;
            $this->postJson('/api/v1/mobile/chat/completions', $payload)->assertStatus(422);
        }
        Http::assertNothingSent();
    }

    public function test_image_count_roles_and_text_only_models_are_enforced(): void
    {
        Http::fake();
        $this->withToken($this->token());
        $payload = $this->payload();
        $payload['model'] = 'openmindai-lite';
        $this->postJson('/api/v1/mobile/chat/completions', $payload)->assertStatus(422);
        $payload = $this->payload();
        $payload['messages'][0]['role'] = 'assistant';
        $this->postJson('/api/v1/mobile/chat/completions', $payload)->assertStatus(422);
        $payload = $this->payload();
        $payload['messages'][0]['content'] = array_fill(0, 5, $payload['messages'][0]['content'][1]);
        $this->postJson('/api/v1/mobile/chat/completions', $payload)->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_mobile_vision_requires_an_interactive_account_token(): void
    {
        Http::fake();
        $this->postJson('/api/v1/mobile/chat/completions', $this->payload())->assertUnauthorized();
        $this->withToken($this->token(['openmindai:invoke']))
            ->postJson('/api/v1/mobile/chat/completions', $this->payload())->assertForbidden();
        Http::assertNothingSent();
    }
}
