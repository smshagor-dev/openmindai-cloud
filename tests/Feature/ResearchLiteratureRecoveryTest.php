<?php

namespace Tests\Feature;

use App\Jobs\Research\ExecuteTask;
use App\Models\Research\Evidence;
use App\Models\Research\Finding;
use App\Models\Research\Project;
use App\Models\Research\Run;
use App\Models\Research\Source;
use App\Models\Research\Task;
use App\Models\User;
use App\Services\Research\Context;
use App\Services\Research\Inference;
use App\Services\Research\Orchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ResearchLiteratureRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private function source(string $text): Source
    {
        Queue::fake();
        Http::preventStrayRequests();
        $user = User::create(['name' => 'Literature fixture', 'email' => 'literature@example.com', 'password' => 'SyntheticPassword123']);
        $user->forceFill(['email_verified_at' => now()])->save();
        $project = Project::create(['owner_id' => $user->id, 'title' => 'Evidence recovery fixture', 'prompt' => 'Review supplied evidence only.', 'settings' => []]);

        return Source::create(['project_id' => $project->id, 'fingerprint' => hash('sha256', $text), 'title' => 'Synthetic source', 'authors' => [], 'text' => $text, 'metadata' => []]);
    }

    private function start(Source $source): Run
    {
        $project = Project::findOrFail($source->project_id);

        return app(Orchestrator::class)->start($project, $project->owner_id, 'literature');
    }

    private function drain(Run $run): void
    {
        for ($i = 0; $i < 30 && $run->fresh()->status === 'running'; $i++) {
            app(Orchestrator::class)->pump($run->id);
            foreach (Task::where('run_id', $run->id)->where('status', 'queued')->get() as $task) {
                (new ExecuteTask($task->id))->handle();
            }
            $this->travel(20)->seconds();
        }
    }

    public function test_selected_quote_ids_resolve_to_exact_unicode_source_text_and_remain_unreviewed(): void
    {
        $text = 'বাংলা গবেষণা: controlled synthetic evidence stays traceable to its original source.';
        $source = $this->source($text);
        $this->mock(Inference::class)->shouldReceive('json')->once()->andReturnUsing(function (Context $ctx, $instruction, $data) use ($source, $text) {
            $this->assertSame($text, $data['source']['quote_options']['q1']);

            return ['analyses' => ['Synthetic analysis'], 'evidence' => [['source_id' => (string) $source->id, 'claim' => 'Synthetic fixture claim', 'quote_id' => 'q1']]];
        });
        $run = $this->start($source);
        $this->drain($run);
        $this->assertSame('completed', $run->fresh()->status);
        $evidence = Evidence::where('project_id', $source->project_id)->sole();
        $this->assertSame($text, $evidence->quote);
        $this->assertSame('unreviewed', $evidence->status);
        $this->assertSame('unreviewed', Finding::where('project_id', $source->project_id)->sole()->data['analyses'][0]['evidence_status']);
    }

    public function test_a_paraphrased_legacy_quote_gets_one_bounded_repair_before_any_evidence_is_saved(): void
    {
        $text = 'Controlled synthetic findings require exact quoted evidence.';
        $source = $this->source($text);
        $calls = 0;
        $this->mock(Inference::class)->shouldReceive('json')->twice()->andReturnUsing(function (Context $ctx, $instruction, $data) use ($source, &$calls) {
            $this->assertSame(0, Evidence::where('project_id', $source->project_id)->count());
            if (++$calls === 1) {
                return ['analyses' => ['Retained analysis'], 'evidence' => [['source_id' => $source->id, 'claim' => 'Synthetic claim', 'quote' => 'This paraphrase was never in the source.']]];
            }
            $this->assertSame(['Synthetic claim'], $data['claims_to_check']);

            return ['evidence' => [['source_id' => $source->id, 'claim' => 'Synthetic claim', 'quote_id' => 'q1']]];
        });
        $run = $this->start($source);
        $this->drain($run);
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertSame($text, Evidence::where('project_id', $source->project_id)->sole()->quote);
        $this->assertSame(1, Finding::where('project_id', $source->project_id)->sole()->data['coverage']['quote_repair_requests']);
        $this->assertSame(1, DB::table('research_events')->where('type', 'literature.quote_repair')->count());
    }

    public function test_failed_quote_repair_cannot_store_foreign_or_fabricated_evidence_or_advance_checkpoint(): void
    {
        $source = $this->source('An actual synthetic source contains only this controlled text.');
        $this->mock(Inference::class)->shouldReceive('json')->twice()->andReturn(
            ['analyses' => ['Synthetic analysis'], 'evidence' => [['source_id' => $source->id, 'claim' => 'Claim', 'quote' => 'Not an actual quotation.']]],
            ['evidence' => [['source_id' => $source->id + 100, 'claim' => 'Claim', 'quote_id' => 'q1']]],
        );
        $run = $this->start($source);
        $task = Task::where('run_id', $run->id)->sole();
        (new ExecuteTask($task->id))->handle();
        $task->refresh();
        $this->assertSame('pending', $task->status);
        $this->assertSame(1, $task->errors);
        $this->assertSame('evidence_quote_not_in_source', $task->error_code);
        $this->assertEmpty($task->checkpoint);
        $this->assertSame(0, Evidence::where('project_id', $source->project_id)->count());
        $this->assertSame(0, Finding::where('project_id', $source->project_id)->count());
    }

    public function test_empty_repaired_evidence_is_explicitly_missing_and_never_promoted_to_verified(): void
    {
        $source = $this->source('Controlled synthetic context cannot prove every proposed claim.');
        $this->mock(Inference::class)->shouldReceive('json')->twice()->andReturn(
            ['analyses' => ['Uncertain interpretation'], 'evidence' => [['source_id' => $source->id, 'claim' => 'Unsupported interpretation', 'quote' => 'An invented quotation.']]],
            ['evidence' => []],
        );
        $run = $this->start($source);
        $this->drain($run);
        $data = Finding::where('project_id', $source->project_id)->sole()->data;
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertSame('missing', $data['analyses'][0]['evidence_status']);
        $this->assertSame(1, $data['coverage']['chunks_without_supported_quotes']);
        $this->assertSame(0, Evidence::where('project_id', $source->project_id)->count());
        $this->assertSame(1, DB::table('research_events')->where('type', 'literature.evidence_limited')->count());
    }

    public function test_retry_budget_resets_after_real_chunk_progress_but_failure_history_is_retained(): void
    {
        $source = $this->source(str_repeat('Controlled synthetic evidence. ', 600));
        $attempts = [];
        $this->mock(Inference::class)->shouldReceive('json')->andReturnUsing(function (Context $ctx, $instruction, $data) use (&$attempts, $source) {
            $index = $ctx->task->checkpoint['index'] ?? 0;
            if (($attempts[$index] ?? 0) === 0) {
                $attempts[$index] = 1;
                throw new \RuntimeException('synthetic transient provider failure');
            }

            return ['analyses' => ['Chunk '.$index], 'evidence' => [['source_id' => $source->id, 'claim' => 'Synthetic chunk '.$index, 'quote_id' => 'q1']]];
        });
        $run = $this->start($source);
        $this->drain($run);
        $task = Task::where('run_id', $run->id)->sole();
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertSame(3, $task->checkpoint['index']);
        $this->assertSame(0, $task->errors);
        $this->assertNull($task->error_code);
        $this->assertSame(3, DB::table('research_events')->where('task_id', $task->id)->where('type', 'task.error')->count());
        $this->assertCount(3, Finding::where('project_id', $source->project_id)->sole()->data['analyses']);
    }

    public function test_repeated_failures_without_chunk_progress_still_stop_after_three_attempts(): void
    {
        $source = $this->source('Controlled synthetic source has no successful inference available.');
        $this->mock(Inference::class)->shouldReceive('json')->times(3)->andThrow(new \RuntimeException('synthetic persistent provider failure'));
        $run = $this->start($source);
        $this->drain($run);
        $task = Task::where('run_id', $run->id)->sole();
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertSame('failed', $task->status);
        $this->assertSame(3, $task->errors);
        $this->assertEmpty($task->checkpoint);
        $this->assertSame(0, Evidence::where('project_id', $source->project_id)->count());
    }

    public function test_legacy_checkpoint_resumes_at_its_next_chunk_without_rewriting_prior_analysis(): void
    {
        $source = $this->source(str_repeat('A', 8000).'The second chunk contains actual synthetic source evidence.');
        $legacy = ['source_id' => $source->id, 'part' => 0, 'analysis' => ['Original saved analysis']];
        $this->mock(Inference::class)->shouldReceive('json')->once()->andReturnUsing(function (Context $ctx, $instruction, $data) use ($source) {
            $this->assertSame(1, $data['source']['part']);

            return ['analyses' => ['New analysis'], 'evidence' => [['source_id' => $source->id, 'claim' => 'New synthetic claim', 'quote_id' => 'q1']]];
        });
        $run = $this->start($source);
        Task::where('run_id', $run->id)->update(['checkpoint' => ['index' => 1, 'analyses' => [$legacy]], 'errors' => 2, 'error_code' => 'execution_or_validation_failed']);
        $this->drain($run);
        $data = Finding::where('project_id', $source->project_id)->sole()->data;
        $this->assertSame('completed', $run->fresh()->status);
        $retained = $data['analyses'][0];
        // JSON object key order is not preserved by MySQL. Keep value types
        // and nested analysis/list order strict when checking preservation.
        ksort($legacy);
        ksort($retained);
        $this->assertSame($legacy, $retained);
        $this->assertCount(2, $data['analyses']);
        $this->assertSame(1, $data['coverage']['quote_tracked_chunks']);
        $this->assertSame(0, Task::where('run_id', $run->id)->sole()->errors);
    }

    public function test_quote_id_output_passes_the_real_inference_validator_and_gateway_with_a_mocked_provider(): void
    {
        $text = 'Controlled synthetic evidence is copied exactly from this source.';
        $source = $this->source($text);
        config(['research.model' => 'openmindai-ultra', 'research.fallback_models' => [], 'models.nvidia_key' => 'synthetic-provider-only']);
        DB::table('hosted_code_agents')->where('slug', 'openmindai-ultra')->update(['enabled' => true, 'api_ready' => true, 'api_protocol' => 'chat', 'last_test_status' => 'success']);
        $result = ['analyses' => ['Synthetic provider analysis'], 'evidence' => [['source_id' => $source->id, 'claim' => 'Synthetic claim', 'quote_id' => 'q1']]];
        Http::fake(['integrate.api.nvidia.com/*' => Http::response(['id' => 'synthetic-completion', 'choices' => [['message' => ['role' => 'assistant', 'content' => json_encode($result)], 'finish_reason' => 'stop']], 'usage' => ['total_tokens' => 100]], 200)]);
        $run = $this->start($source);
        $this->drain($run);
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertSame($text, Evidence::where('project_id', $source->project_id)->sole()->quote);
        Http::assertSentCount(1);
    }

    private function checkpointedSources(int $count): array
    {
        $first = $this->source('Synthetic comparison source 0.');
        $sources = [$first];
        for ($i = 1; $i < $count; $i++) {
            $sources[] = Source::create(['project_id' => $first->project_id, 'fingerprint' => hash('sha256', 'comparison-'.$i), 'title' => 'Synthetic source '.$i, 'authors' => [], 'text' => 'Synthetic comparison source '.$i, 'metadata' => []]);
        }
        $run = $this->start($first);
        $analyses = array_map(fn ($source) => ['source_id' => $source->id, 'part' => 0, 'analysis' => ['Previously saved synthetic analysis']], $sources);
        Task::where('run_id', $run->id)->update(['checkpoint' => ['index' => $count, 'analyses' => $analyses, 'quote_repairs' => 2]]);

        return [$run, $sources, $analyses];
    }

    private function comparisonRows(array $sources): array
    {
        return ['themes' => [], 'contradictions' => [], 'matrix' => array_map(fn ($s) => ['source_id' => $s['source_id'], 'methodology' => 'Synthetic method', 'findings' => 'Synthetic finding', 'limitations' => 'Limited supplied excerpt'], $sources)];
    }

    public function test_final_comparison_is_bounded_checkpointed_and_synthesized_without_reanalyzing_sources(): void
    {
        [$run, $sources, $analyses] = $this->checkpointedSources(14);
        $calls = 0;
        $this->mock(Inference::class)->shouldReceive('json')->times(4)->andReturnUsing(function (Context $ctx, $instruction, $data) use (&$calls) {
            $calls++;
            $this->assertArrayNotHasKey('source', $data);
            $this->assertLessThanOrEqual(6, count($data['sources']));
            if ($calls <= 3) {
                $this->assertCount($calls - 1, $ctx->task->checkpoint['comparison_batches'] ?? []);

                return $this->comparisonRows($data['sources']);
            }
            $this->assertCount(3, $ctx->task->checkpoint['comparison_batches']);

            return ['themes' => [['name' => 'Cross-batch interpretation', 'summary' => 'A limited synthetic comparison.', 'source_ids' => array_merge(...array_column($data['sources'], 'source_ids'))]], 'contradictions' => [], 'matrix' => []];
        });
        $this->drain($run);
        $task = Task::where('run_id', $run->id)->sole();
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertEquals($analyses, $task->checkpoint['analyses']);
        $this->assertSame(14, $task->checkpoint['index']);
        $this->assertSame(2, $task->checkpoint['quote_repairs']);
        $this->assertCount(14, $task->output['matrix']);
        $this->assertSame(3, $task->output['coverage']['comparison_batches_completed']);
    }

    public function test_failed_comparison_retries_only_the_unfinished_batch(): void
    {
        [$run] = $this->checkpointedSources(8);
        $failed = false;
        $this->mock(Inference::class)->shouldReceive('json')->times(4)->andReturnUsing(function (Context $ctx, $instruction, $data) use (&$failed) {
            $done = count($ctx->task->checkpoint['comparison_batches'] ?? []);
            if ($done === 1 && ! $failed) {
                $failed = true;
                throw new \RuntimeException('Synthetic transient failure');
            }
            if ($done === 2) {
                return ['themes' => [], 'contradictions' => [], 'matrix' => []];
            }

            return $this->comparisonRows($data['sources']);
        });
        $this->drain($run);
        $task = Task::where('run_id', $run->id)->sole();
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertSame(0, $task->errors);
        $this->assertCount(8, $task->output['matrix']);
        $this->assertSame(1, DB::table('research_events')->where('task_id', $task->id)->where('type', 'task.error')->count());
    }

    public function test_comparison_cannot_cite_an_unprovided_source_even_when_owned_by_the_same_project(): void
    {
        [$run, $sources] = $this->checkpointedSources(8);
        $this->mock(Inference::class)->shouldReceive('json')->twice()->andReturn(['themes' => [['name' => 'Unsupported', 'summary' => 'Not supplied in first batch', 'source_ids' => [$sources[7]->id]]], 'contradictions' => [], 'matrix' => []]);
        $task = Task::where('run_id', $run->id)->sole();
        (new ExecuteTask($task->id))->handle();
        $task->refresh();
        $this->assertSame('pending', $task->status);
        $this->assertSame('structured_output_validation_failed', $task->error_code);
        $this->assertArrayNotHasKey('comparison_batches', $task->checkpoint);
        $this->assertSame(8, $task->checkpoint['index']);
        $this->assertSame(0, Finding::where('project_id', $task->project_id)->count());
    }

    public function test_real_inference_schema_failure_gets_one_repair_without_weakening_contradiction_evidence(): void
    {
        [$run, $sources] = $this->checkpointedSources(2);
        config(['research.model' => 'openmindai-ultra', 'research.fallback_models' => [], 'models.nvidia_key' => 'synthetic-provider-only']);
        DB::table('hosted_code_agents')->where('slug', 'openmindai-ultra')->update(['enabled' => true, 'api_ready' => true, 'api_protocol' => 'chat', 'last_test_status' => 'success']);
        $invalid = ['themes' => [], 'contradictions' => [['description' => 'A single-source interpretation', 'source_ids' => [$sources[0]->id]]], 'matrix' => []];
        $valid = ['themes' => [], 'contradictions' => [], 'matrix' => []];
        $calls = 0;
        Http::fake(['integrate.api.nvidia.com/*' => function ($request) use (&$calls, $invalid, $valid) {
            $calls++;
            if ($calls === 2) {
                $this->assertStringContainsString('Never add a source just to satisfy validation', $request['messages'][0]['content']);
            }

            return Http::response(['choices' => [['message' => ['role' => 'assistant', 'content' => json_encode($calls === 1 ? $invalid : $valid)], 'finish_reason' => 'stop']], 'usage' => ['total_tokens' => 100]], 200);
        }]);
        $this->drain($run);
        $task = Task::where('run_id', $run->id)->sole();
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertSame([], $task->output['contradictions']);
        $this->assertSame(2, $task->checkpoint['index']);
        $this->assertSame(12000, $run->fresh()->reserved_tokens);
        $this->assertSame(1, DB::table('research_events')->where('task_id', $task->id)->where('type', 'literature.comparison_repair')->count());
        Http::assertSentCount(2);
    }

    public function test_two_repeated_ids_do_not_count_as_two_sources_and_repair_may_omit_the_contradiction(): void
    {
        [$run, $sources] = $this->checkpointedSources(2);
        $this->mock(Inference::class)->shouldReceive('json')->twice()->andReturn(
            ['themes' => [], 'contradictions' => [['description' => 'Unsupported comparison', 'source_ids' => [$sources[0]->id, $sources[0]->id]]], 'matrix' => []],
            ['themes' => [], 'contradictions' => [], 'matrix' => []],
        );
        $this->drain($run);
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertSame([], Finding::where('project_id', $sources[0]->project_id)->sole()->data['contradictions']);
    }

    public function test_comparison_does_not_treat_provider_transport_failure_as_a_schema_repair(): void
    {
        [$run] = $this->checkpointedSources(2);
        $this->mock(Inference::class)->shouldReceive('json')->once()->andThrow(new \RuntimeException('Synthetic transport failure'));
        $task = Task::where('run_id', $run->id)->sole();
        (new ExecuteTask($task->id))->handle();
        $this->assertSame('pending', $task->fresh()->status);
        $this->assertSame(1, $task->fresh()->errors);
        $this->assertArrayNotHasKey('comparison_batches', $task->fresh()->checkpoint);
        $this->assertSame(0, DB::table('research_events')->where('type', 'literature.comparison_repair')->count());
    }
}
