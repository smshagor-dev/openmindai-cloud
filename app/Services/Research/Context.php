<?php

namespace App\Services\Research;

use App\Models\Research\Finding;
use App\Models\Research\Project;
use App\Models\Research\Run;
use App\Models\Research\Source;
use App\Models\Research\Task;

class Context
{
    public function __construct(public Task $task, public string $claim) {}

    public function project(): Project
    {
        return Project::findOrFail($this->task->project_id);
    }

    public function run(): Run
    {
        return Run::findOrFail($this->task->run_id);
    }

    public function fenced(callable $fn): mixed
    {
        return Transactions::run(function () use ($fn) {
            $p = Project::whereKey($this->task->project_id)->lockForUpdate()->firstOrFail();
            $r = Run::whereKey($this->task->run_id)->lockForUpdate()->firstOrFail();
            $t = Task::whereKey($this->task->id)->lockForUpdate()->firstOrFail();
            if ($p->generation !== $r->generation || $r->status !== 'running' || $t->status !== 'running' || $t->claim !== $this->claim) {
                throw new StaleExecution;
            } $t->update(['lease_until' => now()->addSeconds(config('research.lease_seconds'))]);

            return $fn($t);
        });
    }

    public function event(string $type, array $data): void
    {
        $this->fenced(fn () => Events::add($this->task->project_id, $type, $data, $this->task->id));
    }

    public function checkpoint(array $data): void
    {
        $this->fenced(fn ($t) => $t->update(['checkpoint' => $data]));
    }

    public function sources(): array
    {
        return Source::where('project_id', $this->task->project_id)->orderBy('id')->limit(60)->get(['id', 'title', 'doi', 'year', 'authors', 'venue', 'url', 'text', 'verification'])->toArray();
    }

    public function findings(): array
    {
        $compact = function ($value, int $depth = 0) use (&$compact) {
            if (is_string($value)) {
                return mb_substr($value, 0, 1200);
            }
            if (! is_array($value)) {
                return $value;
            }
            if ($depth > 5) {
                return '[Nested context omitted; retrieve project sources for details.]';
            }
            $out = [];
            foreach (array_slice($value, 0, 8, true) as $key => $v) {
                $out[$key] = $compact($v, $depth + 1);
            }

return $out;
        };

        return Finding::where('project_id', $this->task->project_id)->latest('id')->limit(30)->get()->map(fn ($f) => ['finding_id' => $f->id, 'kind' => $f->kind, 'data' => $compact($f->data), 'context_excerpt' => true])->all();
    }

    public function assertSources(array $ids): void
    {
        $ids = array_unique(array_map('intval', $ids));
        abort_unless(Source::where('project_id', $this->task->project_id)->whereIn('id', $ids)->count() === count($ids), 422, 'Unscoped source IDs.');
    }
}
