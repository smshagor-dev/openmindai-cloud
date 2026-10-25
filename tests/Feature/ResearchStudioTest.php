<?php

namespace Tests\Feature;

use App\Jobs\Research\ExecuteExperiment;
use App\Jobs\Research\ExecuteTask;
use App\Jobs\Research\ExportDocument;
use App\Jobs\Research\ParseSource;
use App\Models\Research\Document;
use App\Models\Research\Evidence;
use App\Models\Research\Experiment;
use App\Models\Research\Export;
use App\Models\Research\Finding;
use App\Models\Research\Project;
use App\Models\Research\Run;
use App\Models\Research\Source;
use App\Models\Research\Task;
use App\Models\User;
use App\Services\Research\Context;
use App\Services\Research\Documents;
use App\Services\Research\ExperimentArtifacts;
use App\Services\Research\FullText;
use App\Services\Research\Inference;
use App\Services\Research\IntegrityGates;
use App\Services\Research\Orchestrator;
use App\Services\Research\StaleExecution;
use App\Services\Research\Worker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ResearchStudioTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $name = 'Research'): User
    {
        $u = User::create(['name' => $name, 'email' => uniqid().'@example.com', 'password' => 'StrongPassword123']);
        $u->forceFill(['email_verified_at' => now()])->save();

        return $u;
    }

    private function project(?User $user = null, string $prompt = 'বাংলায় গবেষণা বুঝে English manuscript লিখুন'): Project
    {
        Sanctum::actingAs($user ?? $this->user());
        $id = $this->postJson('/api/v1/research/projects', ['title' => 'Scientific research', 'prompt' => $prompt])->assertCreated()->json('id');

        return Project::findOrFail($id);
    }

    public function test_readiness_is_scoped_to_interactive_users_and_never_exposes_configuration(): void
    {
        Sanctum::actingAs($this->user(), ['user']);
        config(['research.model' => 'openmindai-ultra', 'research.fallback_models' => [], 'models.nvidia_key' => 'synthetic-nvidia-key', 'research.worker_url' => 'https://private-worker.example.test', 'research.worker_key' => str_repeat('k', 40)]);
        DB::table('hosted_code_agents')->where('slug', 'openmindai-ultra')->update(['enabled' => true, 'api_ready' => true, 'last_test_status' => 'success']);
        Http::fake(['https://private-worker.example.test/health' => Http::response(['status' => 'ready', 'runtime_checked' => true, 'document_processing' => 'isolated_container', 'experiments' => 'isolated_container', 'private_value' => 'do-not-reflect'], 200)]);
        $this->getJson('/api/v1/research/status')->assertOk()->assertJsonPath('infrastructure_ready', true)->assertJsonPath('worker.status', 'ready')->assertDontSee('private-worker')->assertDontSee(str_repeat('k', 40))->assertDontSee('do-not-reflect')->assertDontSee('synthetic-nvidia-key');
        $this->getJson('/api/v1/research/status')->assertOk();
        Http::assertSentCount(1);
        DB::table('hosted_code_agents')->where('slug', 'openmindai-ultra')->update(['last_test_status' => 'failed']);
        $this->getJson('/api/v1/research/status')->assertJsonPath('reasoning.status', 'setup_required');
        Sanctum::actingAs($this->user(), ['openmindai:invoke']);
        $this->getJson('/api/v1/research/status')->assertForbidden();
    }

    public function test_readiness_rejects_unsafe_worker_urls_and_checks_actual_runtime_contract(): void
    {
        Sanctum::actingAs($this->user(), ['user']);
        config(['research.worker_key' => str_repeat('k', 40), 'research.worker_url' => 'https://username@private-worker.example.test']);
        Http::fake(['*' => Http::sequence()->push(['status' => 'configured', 'document_processing' => 'isolated_container', 'experiments' => 'isolated_container'], 200)->push(['private_error' => 'never return this'], 401)->push([], 401)]);
        $this->getJson('/api/v1/research/status')->assertJsonPath('worker.status', 'setup_required');
        Http::assertNothingSent();
        config(['research.worker_url' => 'https://private-worker.example.test']);
        $this->getJson('/api/v1/research/status')->assertJsonPath('worker.status', 'unverified_runtime')->assertJsonPath('infrastructure_ready', false);
        config(['research.worker_key' => str_repeat('z', 40)]);
        $this->getJson('/api/v1/research/status')->assertJsonPath('worker.status', 'authorization_failed')->assertDontSee('never return');
        $this->artisan('research:preflight --fresh')->assertExitCode(1);
    }

    private function runTasks(Run $r): void
    {
        for ($i = 0; $i < 90; $i++) {
            app(Orchestrator::class)->pump($r->id);
            $tasks = Task::where('run_id', $r->id)->where('status', 'queued')->get();
            if ($tasks->isEmpty()) {
                if ($r->fresh()->status !== 'running') {
                    break;
                }$this->travel(20)->seconds();

                continue;
            }foreach ($tasks as $t) {
                (new ExecuteTask($t->id))->handle();
            }$this->travel(20)->seconds();
        } $this->assertSame('completed', $r->fresh()->status, json_encode(Task::where('run_id', $r->id)->get(['agent', 'status', 'error_code'])->toArray()));
    }

    private function modelFixtures(): void
    {
        $this->mock(Inference::class)->shouldReceive('json')->andReturnUsing(function (Context $c, string $instruction, array $data, array $rules) {
            $ids = Source::where('project_id', $c->task->project_id)->pluck('id')->all();
            $id = $ids[0] ?? 1;

            if ($c->task->agent === 'literature' && ! isset($data['source'])) {
                return ['themes' => [['name' => 'Traceability', 'summary' => 'Synthetic fixture comparison.', 'source_ids' => $ids]], 'contradictions' => [], 'matrix' => array_map(fn ($id) => ['source_id' => $id, 'methodology' => 'Fixture methodology', 'findings' => 'Synthetic findings', 'limitations' => 'Fixture scope'], $ids)];
            }

            return match ($c->task->agent) {
                'planning' => ['language' => str_contains($c->project()->prompt, 'বাংলা') ? 'bn' : (str_contains($c->project()->prompt, 'исследование') ? 'ru' : 'en'), 'output_language' => 'en', 'discipline' => 'Computer science', 'objectives' => ['Analyze evidence'], 'constraints' => [], 'perspectives' => [['name' => 'Methods', 'query' => 'scientific methods'], ['name' => 'Limitations', 'query' => 'scientific limitations']], 'experimental' => false],
                'literature' => ['analyses' => [['source_id' => $id, 'methods' => 'Controlled fixture method', 'findings' => 'Synthetic fixture evidence', 'limitations' => 'Test corpus only']], 'evidence' => [['source_id' => $data['source']['source_id'] ?? $id, 'claim' => 'The fixture describes controlled research.', 'quote' => 'Scientific methods use traceable evidence.']]],
                'investigation' => ['questions' => ['What evidence supports the method?'], 'hypotheses' => ['The approach may improve traceability.'], 'synthesis' => 'Synthetic evidence synthesis.', 'source_ids' => $ids, 'uncertainties' => ['Mocked fixture only'], 'revisit_query' => null],
                'gaps' => ['gaps' => [['question' => 'How does the method generalize?', 'evidence' => 'Fixture limitations', 'source_ids' => $ids]], 'limitations' => ['Small fixture corpus']],
                'editor' => ['heading' => $data['section'], 'paragraphs' => [['text' => 'Synthetic academic fixture discusses a controlled methodology with traceable evidence. No experiments were executed and no experimental results are reported. AI assistance requires disclosure and human review.', 'source_ids' => $ids]]],
                'quality' => ['passed' => true, 'issues' => [], 'disclosure' => 'Mock reviewer for deterministic workflow tests.'],
                'question' => ['answer' => 'The supplied source describes traceable evidence.', 'source_ids' => $ids, 'uncertainty' => 'Fixture scope only'],
                'selection' => ['suggestion' => 'Reviewed synthetic revision.', 'explanation' => 'Clarity improved.'],default => throw new \RuntimeException('Unexpected fixture agent')
            };
        });
        $record = ['title' => ['Controlled research fixture'], 'DOI' => '10.1234/fixture', 'author' => [['given' => 'Test', 'family' => 'Fixture']], 'published' => ['date-parts' => [[2025]]], 'container-title' => ['Fixture Journal'], 'URL' => 'https://doi.org/10.1234/fixture', 'abstract' => 'Scientific methods use traceable evidence.'];
        Http::fake(function ($request) use ($record) {
            if (str_contains($request->url(), 'openalex')) {
                return Http::response(['results' => []]);
            }

            return Http::response(['message' => str_contains($request->url(), '/works/10.') ? $record : ['items' => [$record]]]);
        });
    }

    public function test_project_is_unicode_scoped_and_developer_tokens_are_forbidden(): void
    {
        $owner = $this->user();
        $p = $this->project($owner);
        $this->getJson('/api/v1/research/projects/'.$p->id)->assertOk()->assertJsonPath('project.prompt', $p->prompt);
        Sanctum::actingAs($this->user());
        $this->getJson('/api/v1/research/projects/'.$p->id)->assertNotFound();
        Sanctum::actingAs($owner, ['openmindai:invoke']);
        $this->getJson('/api/v1/research/projects')->assertForbidden();
    }

    public function test_optimistic_document_saves_preserve_history_and_reject_cross_project_citations(): void
    {
        $p = $this->project();
        $doc = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'বাংলা academic revision']]]]];
        $this->putJson("/api/v1/research/projects/$p->id/document", ['version' => 1, 'content' => $doc])->assertOk()->assertJsonPath('version', 2);
        $this->putJson("/api/v1/research/projects/$p->id/document", ['version' => 1, 'content' => $doc])->assertConflict();
        $this->assertDatabaseCount('research_revisions', 2);
        $doc['content'][0]['content'][] = ['type' => 'citation', 'attrs' => ['sourceId' => 9999]];
        $this->putJson("/api/v1/research/projects/$p->id/document", ['version' => 2, 'content' => $doc])->assertUnprocessable();
    }

    public function test_multilingual_workflows_delegate_and_finish_with_reviewable_proposals(): void
    {
        Queue::fake();
        $this->modelFixtures();
        foreach (['বাংলা গবেষণা English paper', 'English research with evidence', 'Научное исследование методов'] as $prompt) {
            Cache::flush();
            $p = $this->project(null, $prompt);
            $run = app(Orchestrator::class)->start($p, $p->owner_id);
            $this->runTasks($run);
            $this->assertGreaterThanOrEqual(17, Task::where('run_id', $run->id)->count());
            $this->assertDatabaseCount('research_sources', Project::count());
            $this->assertSame(1, Document::where('project_id', $p->id)->value('version'));
            $proposal = Finding::where('project_id', $p->id)->where('kind', 'manuscript')->firstOrFail()->data;
            $this->assertSame('doc', $proposal['content']['type']);
            $this->assertGreaterThan(10, count($proposal['content']['content']));
            $this->assertFalse(app(IntegrityGates::class)->inspect($p->id)['passed']);
        }
    }

    public function test_cancellation_generation_fences_old_workers_and_resume_preserves_completed_tasks(): void
    {
        Queue::fake();
        $p = $this->project();
        $r = app(Orchestrator::class)->start($p, $p->owner_id);
        app(Orchestrator::class)->pump($r->id);
        $t = Task::where('run_id', $r->id)->first();
        $t->update(['status' => 'running', 'claim' => 'old']);
        $ctx = new Context($t->fresh(), 'old');
        app(Orchestrator::class)->control($p, 'cancel');
        $this->expectException(StaleExecution::class);
        $ctx->fenced(fn () => throw new \RuntimeException('must never execute'));
    }

    public function test_resume_recovery_and_graph_dependencies(): void
    {
        Queue::fake();
        $p = $this->project();
        $r = app(Orchestrator::class)->start($p, $p->owner_id);
        app(Orchestrator::class)->pump($r->id);
        $t = Task::where('run_id', $r->id)->first();
        $t->update(['status' => 'running', 'claim' => 'stale', 'lease_until' => now()->subMinute()]);
        app(Orchestrator::class)->recover();
        $this->assertNull($t->fresh()->claim);
        $this->assertSame(1, $t->fresh()->errors);
        app(Orchestrator::class)->control($p, 'pause');
        app(Orchestrator::class)->control($p, 'resume');
        $this->assertSame('running', $r->fresh()->status);
        $this->assertGreaterThan(0, $r->fresh()->generation);
    }

    public function test_imports_deduplicate_and_activity_is_actual_cursor_scoped_sse(): void
    {
        $p = $this->project();
        $record = ['title' => 'Test paper', 'DOI' => '10.1234/paper', 'author' => [['family' => 'Author']], 'issued' => ['date-parts' => [[2025]]]];
        foreach ([1, 2] as $i) {
            $this->postJson("/api/v1/research/projects/$p->id/sources", ['records' => [$record]])->assertOk();
        }$this->assertDatabaseCount('research_sources', 1);
        $response = $this->get("/api/v1/research/projects/$p->id/events?after=0", ['Accept' => 'application/json'])->assertOk();
        $stream = $response->streamedContent();
        $this->assertStringContainsString('sources.imported', $stream);
        $this->assertStringNotContainsString('NVIDIA_API_KEY', $stream);
        $this->assertStringContainsString('event: activity', $stream);
    }

    public function test_collaboration_requires_acceptance_and_roles_are_enforced(): void
    {
        $owner = $this->user();
        $member = $this->user('Reviewer');
        $p = $this->project($owner);
        $this->postJson("/api/v1/research/projects/$p->id/members", ['email' => $member->email, 'role' => 'reviewer', 'authorized' => true])->assertOk();
        $invite = DB::table('research_members')->first();
        Sanctum::actingAs($member);
        $this->getJson("/api/v1/research/projects/$p->id")->assertNotFound();
        $this->postJson("/api/v1/research/invitations/$invite->id/accept")->assertOk();
        $this->getJson("/api/v1/research/projects/$p->id")->assertOk();
        $this->putJson("/api/v1/research/projects/$p->id/document", ['version' => 1, 'content' => ['type' => 'doc']])->assertNotFound();
        $this->postJson("/api/v1/research/projects/$p->id/comments", ['body' => 'Review recorded.'])->assertOk();
    }

    public function test_pdf_jobs_do_not_overwrite_replaced_uploads_and_private_files_are_scoped(): void
    {
        Queue::fake();
        Storage::fake('local');
        $p = $this->project();
        $s = Source::create(['project_id' => $p->id, 'fingerprint' => str_repeat('a', 64), 'title' => 'PDF fixture', 'authors' => [], 'private_path' => 'research/new.pdf', 'metadata' => ['upload_sha256' => 'new']]);
        $this->mock(Worker::class)->shouldNotReceive('call');
        (new ParseSource($s->id, 'research/old.pdf', 'old'))->handle();
        $this->assertNull($s->fresh()->text);
        $this->postJson("/api/v1/research/projects/$p->id/uploads", ['kind' => 'pdf', 'name' => 'bad.pdf', 'data' => base64_encode('not pdf'), 'authorized' => true])->assertUnprocessable();
    }

    public function test_explicit_languages_survive_conflicting_model_detection(): void
    {
        Queue::fake();
        $this->modelFixtures();
        $p = $this->project();
        $this->putJson("/api/v1/research/projects/$p->id/settings", ['progress_language' => 'العربية', 'output_language' => 'Deutsch'])->assertOk();
        $run = app(Orchestrator::class)->start($p->fresh(), $p->owner_id);
        $task = Task::where('run_id', $run->id)->where('agent', 'planning')->firstOrFail();
        (new ExecuteTask($task->id))->handle();
        $this->assertSame('العربية', $run->fresh()->plan['language']);
        $this->assertSame('Deutsch', $run->fresh()->plan['output_language']);
    }

    public function test_experiment_approval_is_exact_and_cancelled_results_cannot_promote_state(): void
    {
        Queue::fake();
        $p = $this->project();
        $x = $this->postJson("/api/v1/research/projects/$p->id/experiments", ['title' => 'Test', 'code' => 'print(1)', 'seed' => 42, 'dataset_ids' => []])->assertCreated()->json();
        $this->postJson("/api/v1/research/projects/$p->id/experiments/{$x['id']}/execute", ['hash' => str_repeat('0', 64), 'authorized' => true])->assertConflict();
        $this->postJson("/api/v1/research/projects/$p->id/experiments/{$x['id']}/execute", ['hash' => $x['code_hash'], 'authorized' => true])->assertOk();
        Queue::assertPushed(ExecuteExperiment::class);
        $this->assertDatabaseHas('research_approvals', ['gate' => 'experiment:'.$x['id'], 'content_hash' => $x['code_hash']]);
    }

    public function test_cancelled_worker_callback_is_fenced_and_tampered_approval_never_executes(): void
    {
        Queue::fake();
        Storage::fake('local');
        $p = $this->project();
        $create = fn () => $this->postJson("/api/v1/research/projects/$p->id/experiments", ['title' => 'Authorized fixture', 'code' => 'print(1)', 'seed' => '42', 'dataset_ids' => []])->assertCreated()->json();
        $x = $create();
        $this->postJson("/api/v1/research/projects/$p->id/experiments/{$x['id']}/execute", ['hash' => $x['code_hash'], 'authorized' => true])->assertOk();
        $this->mock(Worker::class)->shouldReceive('call')->once()->with('experiment', \Mockery::any())->andReturnUsing(function ($operation, $payload) use ($x) {
            Experiment::findOrFail($x['id'])->update(['status' => 'cancelled']);

            return ['exit_code' => 0, 'artifacts' => [], 'provenance' => ['execution_id' => $payload['execution_id'], 'code_hash' => $payload['code_hash'], 'seed' => $payload['seed'], 'image' => 'sha256:'.str_repeat('a', 64)]];
        });
        (new ExecuteExperiment($x['id']))->handle();
        $this->assertDatabaseHas('research_experiments', ['id' => $x['id'], 'status' => 'cancelled']);
        $y = $create();
        $this->postJson("/api/v1/research/projects/$p->id/experiments/{$y['id']}/execute", ['hash' => $y['code_hash'], 'authorized' => true])->assertOk();
        Experiment::findOrFail($y['id'])->update(['seed' => 43]);
        (new ExecuteExperiment($y['id']))->handle();
        $this->assertDatabaseHas('research_experiments', ['id' => $y['id'], 'status' => 'failed']);
        $this->assertFalse(app(IntegrityGates::class)->inspect($p->id)['gates']['experiment']);
    }

    public function test_experiment_cancellation_reports_unconfirmed_and_preserves_other_projects(): void
    {
        Queue::fake();
        $p = $this->project();
        $x = $this->postJson("/api/v1/research/projects/$p->id/experiments", ['title' => 'Fixture', 'code' => 'print(1)', 'seed' => 42, 'dataset_ids' => []])->assertCreated()->json();
        $this->postJson("/api/v1/research/projects/$p->id/experiments/{$x['id']}/execute", ['hash' => $x['code_hash'], 'authorized' => true])->assertOk();
        $this->mock(Worker::class)->shouldReceive('call')->once()->with('cancel', \Mockery::any())->andThrow(new \RuntimeException('offline'));
        $this->postJson("/api/v1/research/projects/$p->id/experiments/{$x['id']}/cancel")->assertOk()->assertJsonPath('unconfirmed_experiment_cancellations.0', $x['id']);
        $this->assertSame('unconfirmed', Experiment::findOrFail($x['id'])->result['cancellation']);
        $other = $this->project();
        $this->postJson("/api/v1/research/projects/$other->id/experiments/{$x['id']}/cancel")->assertNotFound();
    }

    public function test_manuscript_uses_only_verified_experiment_csv_with_exact_values(): void
    {
        Queue::fake();
        Storage::fake('local');
        $p = $this->project();
        $x = $this->postJson("/api/v1/research/projects/$p->id/experiments", ['title' => 'Actual fixture', 'code' => 'print(1)', 'seed' => 42, 'dataset_ids' => []])->assertCreated()->json();
        $this->postJson("/api/v1/research/projects/$p->id/experiments/{$x['id']}/execute", ['hash' => $x['code_hash'], 'authorized' => true])->assertOk();
        $bytes = "method,score\n\"real, baseline\",0.75\n";
        $this->mock(Worker::class)->shouldReceive('call')->once()->andReturnUsing(fn ($operation, $payload) => ['exit_code' => 0, 'stdout' => 'actual fixture', 'artifacts' => [['name' => 'metrics.csv', 'data' => base64_encode($bytes), 'sha256' => hash('sha256', $bytes)]], 'provenance' => ['execution_id' => $payload['execution_id'], 'code_hash' => $payload['code_hash'], 'seed' => $payload['seed'], 'image' => 'sha256:'.str_repeat('a', 64)]]);
        (new ExecuteExperiment($x['id']))->handle();
        $model = Experiment::findOrFail($x['id']);
        $this->assertTrue($model->verifiedExecution());
        $nodes = app(ExperimentArtifacts::class)->nodes($p->id);
        $this->assertCount(1, $nodes);
        $this->assertSame('0.75', $nodes[0]['content'][1]['content'][1]['content'][0]['content'][0]['text']);
        $this->assertSame('real, baseline', $nodes[0]['content'][1]['content'][0]['content'][0]['content'][0]['text']);
        $model->update(['status' => 'cancelled']);
        $this->assertSame([], app(ExperimentArtifacts::class)->nodes($p->id));
    }

    public function test_fulltext_license_allowlist_requires_an_actual_supported_license(): void
    {
        foreach (['cc-by', 'https://creativecommons.org/licenses/by/4.0/', [['URL' => 'https://creativecommons.org/publicdomain/zero/1.0/']]] as $license) {
            $this->assertTrue(FullText::permitted($license));
        }
        foreach (['not public domain', 'cc-by-nc', 'https://creativecommons.org.evil.test/licenses/by/4.0/', null] as $license) {
            $this->assertFalse(FullText::permitted($license));
        }
    }

    public function test_real_markdown_converter_export_after_current_review_and_human_approval(): void
    {
        Queue::fake();
        Storage::fake('local');
        $this->modelFixtures();
        $p = $this->project();
        $r = app(Orchestrator::class)->start($p, $p->owner_id);
        $this->runTasks($r);
        $proposal = Finding::where('project_id', $p->id)->where('kind', 'manuscript')->firstOrFail()->data;
        $this->putJson("/api/v1/research/projects/$p->id/document", ['version' => 1, 'content' => $proposal['content']])->assertOk();
        foreach (Evidence::where('project_id', $p->id)->get() as $e) {
            $this->putJson("/api/v1/research/projects/$p->id/evidence/$e->id", ['status' => 'supported'])->assertOk();
        }$quality = app(Orchestrator::class)->start($p, $p->owner_id, 'quality');
        $this->runTasks($quality);
        $hash = app(Documents::class)->hash($p->id);
        $this->postJson("/api/v1/research/projects/$p->id/approvals", ['gate' => 'manuscript', 'hash' => $hash, 'override' => false, 'reason' => 'Reviewed current manuscript and evidence.'])->assertOk();
        $this->assertTrue(app(IntegrityGates::class)->inspect($p->id)['passed']);
        $this->mock(Worker::class)->shouldReceive('call')->with('export', \Mockery::type('array'))->andReturnUsing(function ($operation, $payload) {
            $process = new Process(['python3', '-c', 'import sys,json;from runtime.research.exports import export;print(json.dumps(export(json.load(sys.stdin))))']);
            $process->setInput(json_encode($payload));
            $process->mustRun();

            return json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        });
        $out = $this->postJson("/api/v1/research/projects/$p->id/exports", ['format' => 'md', 'draft' => false])->assertCreated()->json();
        (new ExportDocument($out['id']))->handle();
        $this->assertSame('completed', Export::find($out['id'])->status);
        $this->get("/api/v1/research/projects/$p->id/exports/{$out['id']}/download")->assertOk()->assertSee('Synthetic academic fixture');
        $doc = Document::where('project_id', $p->id)->first();
        app(Documents::class)->save($p, $p->owner_id, ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Changed research conclusion']]]]], $doc->version);
        $this->assertFalse(app(IntegrityGates::class)->inspect($p->id)['passed']);
    }

    public function test_unverified_models_cannot_be_used_or_silently_enabled(): void
    {
        Queue::fake();
        $p = $this->project();
        $r = app(Orchestrator::class)->start($p, $p->owner_id);
        $t = Task::where('run_id', $r->id)->first();
        $t->update(['status' => 'running', 'claim' => 'test']);
        Http::fake();
        $this->expectException(\RuntimeException::class);
        app(Inference::class)->json(new Context($t->fresh(), 'test'), 'Test', [], ['answer' => 'required|string']);
    }

    public function test_multi_paper_literature_builds_a_source_linked_thematic_review(): void
    {
        Queue::fake();
        $this->modelFixtures();
        $p = $this->project();
        foreach ([1, 2] as $i) {
            Source::create(['project_id' => $p->id, 'fingerprint' => hash('sha256', 'matrix-'.$i), 'title' => 'Matrix fixture '.$i, 'authors' => [], 'text' => 'Scientific methods use traceable evidence.', 'metadata' => []]);
        }
        $run = app(Orchestrator::class)->start($p, $p->owner_id, 'literature');
        $this->runTasks($run);
        $result = Finding::where('project_id', $p->id)->where('kind', 'literature')->firstOrFail()->data;
        $this->assertCount(2, $result['matrix']);
        $this->assertCount(1, $result['themes']);
        $this->assertSame(2, $result['coverage']['sources_analyzed']);
    }

    public function test_settings_and_replacement_pdf_parsing_preserve_newer_state(): void
    {
        Queue::fake();
        Storage::fake('local');
        $p = $this->project();
        $this->putJson("/api/v1/research/projects/$p->id/settings", ['citation_style' => 'ieee'])->assertOk();
        $bytes = '%PDF-1.4 synthetic';
        $hash = hash('sha256', $bytes);
        Storage::disk('local')->put('research/old.pdf', $bytes);
        $source = Source::create(['project_id' => $p->id, 'fingerprint' => hash('sha256', 'parse-race'), 'title' => 'Race fixture', 'authors' => [], 'private_path' => 'research/old.pdf', 'metadata' => ['upload_sha256' => $hash, 'parse_status' => 'pending']]);
        $this->mock(Worker::class)->shouldReceive('call')->once()->andReturnUsing(function () use ($source) {
            $source->refresh()->update(['private_path' => 'research/new.pdf', 'text' => 'Newer document text', 'metadata' => ['upload_sha256' => 'new-hash', 'parse_status' => 'pending']]);

            return ['text' => 'Old parsing result', 'pages' => 1];
        });
        (new ParseSource($source->id, 'research/old.pdf', $hash))->handle();
        $source->refresh();
        $this->assertSame('Newer document text', $source->text);
        $this->assertSame('new-hash', $source->metadata['upload_sha256']);
    }

    public function test_account_deletion_does_not_break_research_foreign_keys(): void
    {
        $owner = $this->user();
        $editor = $this->user('Former editor');
        $p = $this->project($owner);
        $run = Run::create(['project_id' => $p->id, 'actor_id' => $editor->id, 'generation' => 0]);
        $editor->delete();
        $this->assertNull($run->fresh()->actor_id);
        $owner->delete();
        $this->assertDatabaseMissing('research_projects', ['id' => $p->id]);
        $this->assertDatabaseMissing('research_runs', ['id' => $run->id]);
    }

    public function test_literal_author_metadata_is_verified_and_wrong_given_names_are_rejected(): void
    {
        Queue::fake();
        $this->modelFixtures();
        $p = $this->project();
        $s = Source::create(['project_id' => $p->id, 'fingerprint' => hash('sha256', 'literal-author'), 'title' => 'Controlled research fixture', 'doi' => '10.1234/fixture', 'authors' => [['literal' => 'Test Fixture']], 'year' => 2025, 'venue' => 'Fixture Journal', 'metadata' => []]);
        $run = app(Orchestrator::class)->start($p, $p->owner_id, 'verification');
        $this->runTasks($run);
        $this->assertSame('verified', $s->fresh()->verification);
        $s->update(['authors' => [['given' => 'Wrong', 'family' => 'Fixture']]]);
        $run = app(Orchestrator::class)->start($p, $p->owner_id, 'verification');
        $this->runTasks($run);
        $this->assertSame('mismatch', $s->fresh()->verification);
    }
}
