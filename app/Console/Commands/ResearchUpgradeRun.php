<?php

namespace App\Console\Commands;

use App\Models\Research\Project;
use App\Models\Research\Run;
use App\Models\Research\Task;
use App\Services\Research\Events;
use App\Services\Research\Registry;
use App\Services\Research\Transactions;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ResearchUpgradeRun extends Command
{
    protected $signature = 'research:upgrade-run {run : Paused run ID} {--apply= : Exact reviewed dry-run plan hash}';

    protected $description = 'Plan or audit a compatible paused-run implementation upgrade without resetting work or resuming execution';

    public function handle(Registry $registry): int
    {
        try {
            $result = Transactions::run(function () use ($registry) {
                $hint = Run::findOrFail($this->argument('run'));
                $project = Project::whereKey($hint->project_id)->lockForUpdate()->firstOrFail();
                $run = Run::whereKey($hint->id)->lockForUpdate()->firstOrFail();
                abort_unless($run->status === 'paused' && Run::where('project_id', $project->id)->latest('id')->value('id') === $run->id, 409, 'Only the latest paused run can be upgraded.');
                $tasks = Task::where('run_id', $run->id)->orderBy('id')->lockForUpdate()->get();
                $changes = [];
                $state = [];
                foreach ($tasks as $task) {
                    abort_if($task->claim !== null || in_array($task->status, ['queued', 'running'], true), 409, 'Pause and fence active tasks before upgrading.');
                    $state[] = [$task->id, $task->status, $task->definition_id, hash('sha256', json_encode($this->canonical($task->checkpoint)))];
                    if ($task->status === 'completed') {
                        continue;
                    }
                    $next = $registry->snapshot($task->agent);
                    if ($next === $task->definition_id) {
                        continue;
                    }
                    $previous = DB::table('research_agent_definitions')->where('id', $task->definition_id)->first();
                    abort_unless($previous && $previous->key === $task->agent && $this->canonical(json_decode($previous->definition, true)) === $this->canonical($registry->get($task->agent)->definition()), 409, 'Agent contract changed; a checkpoint upgrade is not compatible.');
                    $changes[] = ['task_id' => $task->id, 'agent' => $task->agent, 'from' => $task->definition_id, 'to' => $next];
                }
                $plan = ['run_id' => $run->id, 'project_id' => $project->id, 'generation' => $project->generation, 'changes' => $changes];
                $hash = hash('sha256', json_encode([$plan, $state]));
                $apply = $this->option('apply');
                if ($apply !== null) {
                    abort_unless(hash_equals($hash, $apply), 409, 'Plan changed; review a fresh dry-run plan.');
                    foreach ($changes as $change) {
                        Task::whereKey($change['task_id'])->update(['definition_id' => $change['to']]);
                        Events::add($project->id, 'task.implementation_upgraded', ['agent' => $change['agent'], 'version' => $change['from'].':'.$change['to'], 'status' => 'paused'], $change['task_id']);
                    }
                }

                return [...$plan, 'plan_hash' => $hash, 'applied' => $apply !== null];
            });
            $this->line(json_encode($result, JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error('Upgrade refused. Verify the latest paused run, unchanged agent contracts and exact reviewed plan hash. No task changes were applied.');

            return self::FAILURE;
        }
    }

    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn ($item) => $this->canonical($item), $value);
    }
}
