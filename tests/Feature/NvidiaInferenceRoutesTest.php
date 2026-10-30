<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NvidiaInferenceRoutesTest extends TestCase
{
    use RefreshDatabase;

    private function developer(): string
    {
        $user = User::create([
            'name' => 'Developer',
            'email' => uniqid('model-').'@example.com',
            'password' => 'CorrectPassword123',
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user->createToken('developer:Studio', ['openmindai:invoke'])->plainTextToken;
    }

    private function administrator(): User
    {
        $user = User::create([
            'name' => 'Administrator',
            'email' => 'model-admin@example.com',
            'password' => 'CorrectPassword123',
        ]);
        $user->forceFill(['is_admin' => true, 'email_verified_at' => now()])->save();
        $this->actingAs($user)->withSession(['admin_verified' => true]);

        return $user;
    }

    public function test_documented_specialist_routes_are_configured_in_database(): void
    {
        foreach ([
            ['nvidia-nemotron-3.5-embed-1b', 'integrate.api.nvidia.com', '/v1/embeddings'],
            ['nvidia-nemotron-3.5-embed-8b', 'integrate.api.nvidia.com', '/v1/embeddings'],
            ['nvidia-nemotron-3-embed-1b', 'integrate.api.nvidia.com', '/v1/embeddings'],
            ['nvidia-nemotron-3.5-rerank-8b', 'ai.api.nvidia.com', '/v1/retrieval/nvidia/nemotron-3.5-rerank-8b/reranking'],
            ['nvidia-kumo-tabular', 'ai.api.nvidia.com', '/v1/structured-data/nvidia/kumo-tabular/predictions'],
            ['nvidia-kumo-relational', 'ai.api.nvidia.com', '/v1/structured-data/nvidia/kumo-relational/predictions'],
            ['nvidia-rfd4-proteina', 'health.api.nvidia.com', '/v1/biology/ipd/rfd4-proteina/generative'],
            ['nvidia-riva-translate-4b-instruct-v2', 'integrate.api.nvidia.com', '/v1/chat/completions'],
        ] as [$slug, $origin, $path]) {
            $this->assertDatabaseHas('hosted_code_agents', [
                'slug' => $slug, 'api_origin' => $origin, 'api_path' => $path, 'api_ready' => true,
            ]);
        }
        $this->assertDatabaseHas('hosted_code_agents', [
            'slug' => 'nvidia-cosmos3-nano', 'api_ready' => true,
            'api_origin' => 'ai.api.nvidia.com',
            'api_path' => '/v1/cosmos/nvidia/cosmos3-nano',
            'enabled' => true,
        ]);
    }

    public function test_embedding_uses_documented_endpoint_and_default_query_mode(): void
    {
        config(['models.nvidia_key' => 'test-secret-123456789']);
        Http::fake(['integrate.api.nvidia.com/v1/embeddings' => Http::response([
            'data' => [['embedding' => [0.1, 0.2]]],
        ])]);
        $this->withToken($this->developer())->postJson('/api/v1/openmindai/models/nvidia-nemotron-3.5-embed-8b/invoke', [
            'input' => ['input' => 'Hello world', 'model' => 'attacker/model'],
        ])->assertOk()->assertJsonPath('output.data.0.embedding.0', 0.1);
        Http::assertSent(fn ($r) => $r->url() === 'https://integrate.api.nvidia.com/v1/embeddings'
            && $r['model'] === 'nvidia/nemotron-3.5-embed-8b'
            && $r['input_type'] === 'query');
        $this->withToken($this->developer())->postJson('/api/v1/openmindai/models/nvidia-nemotron-3.5-embed-8b/invoke', [
            'input' => ['input' => ''],
        ])->assertUnprocessable();
        Http::assertSentCount(1);
    }

    public function test_malformed_embedding_output_is_rejected_without_consuming_credit(): void
    {
        config(['models.nvidia_key' => 'test-secret-123456789']);
        Http::fake(['integrate.api.nvidia.com/v1/embeddings' => Http::response([
            'data' => [['embedding' => ['unexpected text']]],
        ])]);
        $this->withToken($this->developer())->postJson('/api/v1/openmindai/models/nvidia-nemotron-3.5-embed-8b/invoke', [
            'input' => ['input' => 'Hello world'],
        ])->assertStatus(502);
        $this->assertDatabaseCount('api_credit_usage', 0);
    }

    public function test_rerank_uses_retrieval_api_host_not_chat_host(): void
    {
        config(['models.nvidia_key' => 'test-secret-123456789']);
        Http::fake(['ai.api.nvidia.com/v1/retrieval/nvidia/nemotron-3.5-rerank-8b/reranking' => Http::response(['rankings' => [['index' => 0, 'logit' => 2.1]]])]);
        $key = $this->developer();
        $this->withToken($key)->postJson('/api/v1/openmindai/models/nvidia-nemotron-3.5-rerank-8b/invoke', [
            'input' => [
                'query' => ['text' => 'useful'],
                'passages' => [['text' => 'A useful document']],
            ],
        ])->assertOk()->assertJsonPath('output.rankings.0.index', 0);
        Http::assertSent(fn ($r) => str_starts_with($r->url(), 'https://ai.api.nvidia.com/v1/retrieval/')
            && $r['model'] === 'nvidia/nemotron-3.5-rerank-8b');
    }

    public function test_kumo_uses_model_family_name_and_structured_data_host(): void
    {
        config(['models.nvidia_key' => 'test-secret-123456789']);
        Http::fake(['ai.api.nvidia.com/v1/structured-data/nvidia/kumo-tabular/predictions' => Http::response(['predictions' => [['value' => 1]]])]);
        $key = $this->developer();
        $path = '/api/v1/openmindai/models/nvidia-kumo-tabular/invoke';
        $this->withToken($key)->postJson($path, ['input' => ['model' => 'bad', 'task' => []]])->assertUnprocessable();
        $this->withToken($key)->postJson($path, [
            'input' => ['model' => 'bad', 'task' => [], 'schema' => [],
                'context' => [], 'predict' => [], 'output' => []],
        ])->assertOk()->assertJsonPath('output.predictions.0.value', 1);
        Http::assertSent(fn ($r) => $r->url() === 'https://ai.api.nvidia.com/v1/structured-data/nvidia/kumo-tabular/predictions'
            && $r['model'] === 'kumo-tabular');
    }

    public function test_rfd4_uses_health_api_and_never_adds_catalog_model_field(): void
    {
        config(['models.nvidia_key' => 'test-secret-123456789']);
        Http::fake(['health.api.nvidia.com/v1/biology/ipd/rfd4-proteina/generative' => Http::response(['outputs' => [['sequence' => 'AGTG']]])]);
        $this->withToken($this->developer())->postJson('/api/v1/openmindai/models/nvidia-rfd4-proteina/invoke', [
            'input' => [
                'model' => 'foreign/model', 'task' => 'generation',
                'input' => ['sequence' => 'AGTG'],
            ],
        ])->assertOk()->assertJsonPath('output.outputs.0.sequence', 'AGTG');
        Http::assertSent(fn ($r) => $r->url() === 'https://health.api.nvidia.com/v1/biology/ipd/rfd4-proteina/generative'
            && ! isset($r['model']));
    }

    public function test_live_test_requires_admin_password_and_records_success_without_secrets(): void
    {
        config(['models.nvidia_key' => 'test-secret-123456789']);
        Http::fake(['integrate.api.nvidia.com/v1/embeddings' => Http::response(['data' => [['embedding' => [0.2]]]])]);
        $this->administrator();
        $endpoint = '/admin/nvidia-models/nvidia-nemotron-3.5-embed-1b/test';
        $this->postJson($endpoint, ['current_password' => 'bad'])->assertUnprocessable();
        Http::assertNothingSent();
        $this->post($endpoint, ['current_password' => 'CorrectPassword123'])->assertRedirect();
        $this->assertDatabaseHas('hosted_code_agents', [
            'slug' => 'nvidia-nemotron-3.5-embed-1b',
            'last_test_status' => 'success', 'last_test_http_status' => 200,
        ]);
        $this->get('/admin/nvidia-models')->assertOk()
            ->assertSee('NVIDIA live test:')->assertSee('Passed');
    }

    public function test_live_test_records_upstream_auth_errors_without_exposing_nvidia_key(): void
    {
        config(['models.nvidia_key' => 'test-secret-123456789']);
        Http::fake(['integrate.api.nvidia.com/v1/embeddings' => Http::response(['error' => 'private provider details'], 403)]);
        $this->administrator();
        $this->post('/admin/nvidia-models/nvidia-nemotron-3.5-embed-1b/test', [
            'current_password' => 'CorrectPassword123',
        ])->assertRedirect()->assertSessionMissing('test-secret-123456789');
        $this->assertDatabaseHas('hosted_code_agents', [
            'slug' => 'nvidia-nemotron-3.5-embed-1b',
            'last_test_status' => 'access_denied', 'last_test_http_status' => 403,
        ]);
    }

    public function test_admin_cannot_choose_arbitrary_host_or_traversal_path(): void
    {
        $this->administrator();
        $slug = 'nvidia-nemotron-3.5-embed-1b';
        $this->patchJson('/admin/nvidia-models/'.$slug, [
            'api_origin' => 'attacker.example', 'api_ready' => true,
        ])->assertUnprocessable();
        $this->patchJson('/admin/nvidia-models/'.$slug, [
            'api_path' => '/v1/../../secrets', 'api_ready' => true,
        ])->assertUnprocessable();
    }
}
