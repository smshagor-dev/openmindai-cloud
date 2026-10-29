<?php

namespace App\Jobs\Research;

use App\Models\Research\Finding;
use App\Models\Research\Project;
use App\Models\Research\Run;
use App\Models\Research\Task;
use App\Services\Research\Context;
use App\Services\Research\Events;
use App\Services\Research\Orchestrator;
use App\Services\Research\Registry;
use App\Services\Research\StaleExecution;
use App\Services\Research\Transactions;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class ExecuteTask implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 440;

    public function __construct(public int $taskId) {}

    public function handle(): void
    {
        $task = Task::find($this->taskId);
        if (! $task) {
            return;
        }$claim = bin2hex(random_bytes(24));
        $claimed = Transactions::run(function () use ($task, $claim) {
            $p = Project::whereKey($task->project_id)->lockForUpdate()->firstOrFail();
            $r = Run::whereKey($task->run_id)->lockForUpdate()->firstOrFail();
            $t = Task::whereKey($task->id)->lockForUpdate()->firstOrFail();
            if (! in_array($t->status, ['queued', 'pending']) || $r->status !== 'running' || $p->generation !== $r->generation) {
                return false;
            }$t->update(['status' => 'running', 'claim' => $claim, 'attempts' => $t->attempts + 1, 'lease_until' => now()->addSeconds(config('research.lease_seconds'))]);
            Events::add($t->project_id, 'task.started', ['agent' => $t->agent, 'status' => 'running', 'task_id' => $t->id], $t->id);

            return true;
        });
        if (! $claimed) {
            return;
        }
        $ctx = new Context($task->fresh(), $claim);
        try {
            $registry = app(Registry::class);
            abort_unless($registry->snapshot($task->agent) === $task->definition_id, 409, 'Agent implementation changed; restart this component.');
            $agent = $registry->get($task->agent);
            $out = $agent->execute($ctx);
            $ctx->fenced(function ($t) use ($ctx, $out, $agent) {
                if ($out['_continue'] ?? false) {
                    $t->update(['status' => 'pending', 'claim' => null, 'lease_until' => null, 'errors' => 0, 'error_code' => null, 'available_at' => null]);

                    return;
                }Validator::make($out, $agent->definition()['output_schema'])->validate();
                Finding::updateOrCreate(['task_id' => $t->id, 'kind' => $t->agent], ['project_id' => $t->project_id, 'data' => $out]);
                app(Orchestrator::class)->expand($ctx, $out);
                app(Orchestrator::class)->revisit($ctx, $out);
                $t->update(['status' => 'completed', 'output' => $out, 'claim' => null, 'lease_until' => null, 'error_code' => null]);
                Events::add($t->project_id, 'task.completed', ['agent' => $t->agent, 'status' => 'completed', 'task_id' => $t->id], $t->id);
            });
        } catch (StaleExecution) {
        } catch (\Throwable $e) {
            $code = match (true) {
                $e instanceof ValidationException => 'structured_output_validation_failed',
                $e instanceof HttpExceptionInterface && $e->getStatusCode() === 422 && $e->getMessage() === 'Evidence quote or source could not be verified.' => 'evidence_quote_not_in_source',
                $e instanceof HttpExceptionInterface && $e->getStatusCode() === 409 => 'agent_implementation_changed',
                $e instanceof HttpExceptionInterface && $e->getStatusCode() === 429 => 'research_budget_exhausted',
                default => 'execution_or_validation_failed',
            };
            if ($e instanceof HttpExceptionInterface && $e->getStatusCode() === 429 && $e->getMessage() !== 'Research token reservation limit reached.') {
                try {
                    $ctx->fenced(function ($t) use ($e) {
                        $delay = max(1, min(300, (int) ($e->getHeaders()['Retry-After'] ?? 60)));
                        $t->update(['status' => 'pending', 'claim' => null, 'lease_until' => null, 'available_at' => now()->addSeconds($delay), 'error_code' => 'provider_rate_limited']);
                        Events::add($t->project_id, 'task.waiting', ['agent' => $t->agent, 'status' => 'waiting', 'error_code' => 'provider_rate_limited', 'retry_after' => $delay], $t->id);
                    });
                } catch (StaleExecution) {
                }
                return;
            }
            try {
                $ctx->fenced(function ($t) use ($code) {
                    $errors = $t->errors + 1;
                    $t->update(['status' => $errors >= config('research.attempts') ? 'failed' : 'pending', 'errors' => $errors, 'available_at' => now()->addSeconds(min(60, 5 * (2 ** $errors))), 'claim' => null, 'lease_until' => null, 'error_code' => $code]);
                    Events::add($t->project_id, 'task.error', ['agent' => $t->agent, 'status' => $t->status, 'error_code' => $code], $t->id);
                });
            } catch (StaleExecution) {
            }
        }
        app(Orchestrator::class)->pump($task->run_id);
    }
}
