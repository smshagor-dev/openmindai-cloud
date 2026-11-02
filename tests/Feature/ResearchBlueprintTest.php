<?php

namespace Tests\Feature;

use App\Models\Research\Document;
use App\Models\Research\Project;
use App\Models\Research\Run;
use App\Models\Research\Source;
use App\Models\Research\Task;
use App\Models\User;
use App\Services\Research\Agents\Nvidia;
use App\Services\Research\Blueprint;
use App\Services\Research\Context;
use App\Services\Research\Registry;
use App\Services\WebSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ResearchBlueprintTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        Queue::fake();
        $u = User::create(['name' => 'Blueprint fixture', 'email' => uniqid().'@example.test', 'password' => 'StrongPassword123']);
        $u->forceFill(['email_verified_at' => now()])->save();
        $p = Project::create(['owner_id' => $u->id, 'title' => 'Blueprint fixture', 'prompt' => 'Research safe citations', 'settings' => []]);
        $p->refresh();
        Document::create(['project_id' => $p->id, 'content' => ['type' => 'doc', 'content' => [['type' => 'paragraph']]]]);
        $run = Run::create(['project_id' => $p->id, 'actor_id' => $u->id, 'kind' => 'component', 'status' => 'running', 'generation' => $p->generation]);
        $task = Task::create(['project_id' => $p->id, 'run_id' => $run->id, 'key' => 'nvidia', 'agent' => 'nvidia', 'definition_id' => app(Registry::class)->snapshot('nvidia'), 'input' => [], 'status' => 'running', 'claim' => 'blueprint-claim']);
        $model = DB::table('hosted_code_agents')->where('slug', 'openmindai-ultra')->first();
        DB::table('hosted_code_agents')->where('id', $model->id)->update(['enabled' => true, 'api_ready' => true, 'last_test_status' => 'success']);
        $token = Crypt::encryptString(json_encode(['task_id' => $task->id, 'claim' => $task->claim, 'actor_id' => $u->id, 'model' => $model->provider_model, 'expires' => now()->addMinutes(30)->timestamp]));

        return [$p, $run, $task, $u, $model, $token];
    }

    public function test_bridge_rejects_unauthenticated_expired_and_stale_task_claims(): void
    {
        [$p,$run,$task,$u,$model,$token] = $this->fixture();
        $this->postJson('/api/v1/internal/blueprint/sources', ['operation' => 'search', 'query' => 'test'])->assertUnauthorized();
        $expired = Crypt::encryptString(json_encode(['task_id' => $task->id, 'claim' => $task->claim, 'actor_id' => $u->id, 'model' => $model->provider_model, 'expires' => now()->subMinute()->timestamp]));
        $this->withToken($expired)->postJson('/api/v1/internal/blueprint/sources', ['operation' => 'search', 'query' => 'test'])->assertUnauthorized();
        $task->update(['claim' => null, 'status' => 'pending']);
        $this->withToken($token)->postJson('/api/v1/internal/blueprint/sources', ['operation' => 'search', 'query' => 'test'])->assertStatus(409);
        $this->assertSame(0, Source::count());
        Http::preventStrayRequests();
    }

    public function test_public_requests_cannot_enable_private_blueprint_context_limits(): void
    {
        [$p,$run,$task,$u] = $this->fixture();
        Sanctum::actingAs($u, ['user']);
        $this->postJson('/api/v1/mobile/chat/completions', ['model' => 'openmindai-ultra', 'messages' => [['role' => 'user', 'content' => str_repeat('x', 17000)]], 'blueprint_context' => true])->assertStatus(422);
    }

    public function test_source_tools_are_scoped_to_the_active_project_and_cannot_read_arbitrary_urls(): void
    {
        [$p,$run,$task,$u,$model,$token] = $this->fixture();
        $this->mock(WebSearch::class)->shouldReceive('search')->once()->andReturn([['url' => 'https://www.php.net/manual/en/function.array-map.php', 'title' => 'array_map', 'snippet' => 'Applies the callback to array elements.']]);
        $this->withToken($token)->postJson('/api/v1/internal/blueprint/sources', ['operation' => 'search', 'query' => 'PHP array_map official'])->assertOk()->assertJsonPath('sources.0.title', 'array_map');
        $source = Source::firstOrFail();
        $this->assertSame($p->id, $source->project_id);
        $this->assertSame('unverified', $source->verification);
        $this->withToken($token)->postJson('/api/v1/internal/blueprint/sources', ['operation' => 'read', 'query' => 'https://example.com/not-discovered'])->assertNotFound();
    }

    public function test_blueprint_inference_uses_existing_gateway_without_a_fixed_output_cap(): void
    {
        [$p,$run,$task,$u,$model,$token] = $this->fixture();
        config(['models.nvidia_key' => 'synthetic-key-for-tests', 'models.nvidia_requests_per_minute' => 0]);
        Http::fake(['integrate.api.nvidia.com/*' => Http::response(['choices' => [['message' => ['content' => 'Fixture response'], 'finish_reason' => 'stop']], 'usage' => ['total_tokens' => 2]], 200)]);
        $this->withToken($token)->postJson('/api/v1/internal/blueprint/v1/chat/completions', ['model' => $model->provider_model, 'messages' => [['role' => 'system', 'content' => str_repeat('x', 17000)], ['role' => 'user', 'content' => 'Test the research gateway']], 'max_tokens' => 32, 'stream' => false])->assertOk();
        Http::assertSent(fn ($r) => $r['model'] === $model->provider_model && ! isset($r['max_tokens']));
        $this->withToken($token)->postJson('/api/v1/internal/blueprint/v1/chat/completions', ['model' => 'unscoped-model', 'messages' => [['role' => 'user', 'content' => 'Test']]])->assertForbidden();
    }

    public function test_nvidia_report_produces_scoped_manuscript_sections_without_reexecuting_a_completed_checkpoint(): void
    {
        [$p,$run,$task] = $this->fixture();
        $source = Source::create(['project_id' => $p->id, 'fingerprint' => 'fixture-source', 'title' => 'Official reference', 'authors' => [], 'metadata' => [], 'url' => 'https://example.com/reference']);
        $this->mock(Blueprint::class)->shouldReceive('research')->once()->andReturn(['report' => "# Evidence\n\nRead the [official reference](https://example.com/reference). No experiments were performed.", 'upstream_commit' => Blueprint::COMMIT, 'engine' => 'nvidia-deep-researcher-2.2', 'sources' => [['id' => $source->id, 'url' => $source->url]], 'sandbox_execution' => false]);
        $ctx = new Context($task->fresh(), $task->claim);
        $out = app(Nvidia::class)->execute($ctx);
        $this->assertSame([$source->id], $out['sections'][0]['paragraphs'][0]['source_ids']);
        $this->assertSame($out, app(Nvidia::class)->execute(new Context($task->fresh(), $task->claim)));
    }
}
