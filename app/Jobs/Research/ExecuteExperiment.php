<?php

namespace App\Jobs\Research;

use App\Models\Research\Experiment;
use App\Models\Research\Project;
use App\Services\Research\Events;
use App\Services\Research\Transactions;
use App\Services\Research\Worker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ExecuteExperiment implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 220;

    public function __construct(public int $experimentId) {}

    public function handle(): void
    {
        $x = Experiment::find($this->experimentId);
        if (! $x || ! Experiment::whereKey($x->id)->where('status', 'queued')->update(['status' => 'running', 'updated_at' => now()])) {
            return;
        }
        try {
            $datasets = [];
            foreach ($x->dataset_ids as $id) {
                $a = DB::table('research_artifacts')->where('project_id', $x->project_id)->where('kind', 'dataset')->findOrFail($id);
                $bytes = Storage::disk('local')->get($a->path);
                if (! hash_equals($a->sha256, hash('sha256', $bytes))) {
                    throw new \RuntimeException('dataset_checksum');
                }$datasets[] = ['name' => 'dataset-'.$a->id.'.csv', 'data' => base64_encode($bytes), 'sha256' => $a->sha256];
            }abort_if(array_sum(array_map(fn ($d) => strlen($d['data']), $datasets)) > 12000000, 422);
            $out = app(Worker::class)->call('experiment', ['execution_id' => $x->execution_id, 'code' => $x->code, 'code_hash' => hash('sha256', $x->code), 'seed' => $x->seed, 'datasets' => $datasets]);
            $provenance = $out['provenance'] ?? [];
            if (($provenance['execution_id'] ?? '') !== $x->execution_id || ($provenance['code_hash'] ?? '') !== hash('sha256', $x->code) || ! preg_match('/^sha256:[a-f0-9]{64}$/', $provenance['image'] ?? '')) {
                throw new \RuntimeException('execution_provenance_invalid');
            }
            Transactions::run(function () use ($x, $out) {
                Project::whereKey($x->project_id)->lockForUpdate()->firstOrFail();
                $locked = Experiment::whereKey($x->id)->lockForUpdate()->firstOrFail();
                if ($locked->status !== 'running') {
                    return;
                }$artifacts = $out['artifacts'] ?? [];
                abort_if(count($artifacts) > 20, 422);
                $total = 0;
                foreach ($artifacts as $a) {
                    $bytes = base64_decode($a['data'] ?? '', true);
                    if ($bytes === false || ! hash_equals(hash('sha256', $bytes), (string) ($a['sha256'] ?? ''))) {
                        throw new \RuntimeException('artifact_checksum');
                    }$total += strlen($bytes);
                }abort_if($total > 12000000 || $total + DB::table('research_artifacts')->where('project_id', $x->project_id)->sum('bytes') + DB::table('research_exports')->where('project_id', $x->project_id)->sum('bytes') > config('research.storage_bytes'), 422);
                foreach ($artifacts as $i => $a) {
                    $bytes = base64_decode($a['data']);
                    $path = 'research/'.$x->project_id.'/'.$x->execution_id.'-'.$i;
                    Storage::disk('local')->put($path, $bytes);
                    DB::table('research_artifacts')->insert(['project_id' => $x->project_id, 'experiment_id' => $x->id, 'kind' => 'experiment', 'name' => mb_substr(basename($a['name']), 0, 150), 'mime' => (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes), 'path' => $path, 'sha256' => $a['sha256'], 'bytes' => strlen($bytes), 'created_at' => now(), 'updated_at' => now()]);
                }unset($out['artifacts']);
                $locked->update(['status' => ($out['exit_code'] ?? -1) === 0 ? 'completed' : 'failed', 'result' => $out]);
                Events::add($x->project_id, 'experiment.finished', ['execution_id' => $x->execution_id, 'status' => $locked->status]);
            });
        } catch (\Throwable) {
            Experiment::whereKey($x->id)->where('status', 'running')->update(['status' => 'failed', 'result' => json_encode(['error_code' => 'experiment_worker_failed']), 'updated_at' => now()]);
            Events::add($x->project_id, 'experiment.failed', ['execution_id' => $x->execution_id, 'error_code' => 'experiment_worker_failed']);
        }
    }
}
