<?php

namespace App\Jobs\Research;

use App\Models\Research\Export;
use App\Models\Research\Project;
use App\Services\Research\Events;
use App\Services\Research\Transactions;
use App\Services\Research\Worker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ExportDocument implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 220;

    public function __construct(public int $exportId) {}

    public function handle(): void
    {
        $e = Export::find($this->exportId);
        if (! $e || ! Export::whereKey($e->id)->where('status', 'queued')->update(['status' => 'running', 'updated_at' => now()])) {
            return;
        }
        try {
            $out = app(Worker::class)->call('export', $e->snapshot);
            $bytes = base64_decode($out['data'] ?? '', true);
            if (! $bytes || ! ($out['validated'] ?? false) || ! hash_equals(hash('sha256', $bytes), (string) ($out['sha256'] ?? ''))) {
                throw new \RuntimeException('invalid_export');
            }Transactions::run(function () use ($e, $bytes, $out) {
                Project::whereKey($e->project_id)->lockForUpdate()->firstOrFail();
                abort_if(DB::table('research_artifacts')->where('project_id', $e->project_id)->sum('bytes') + Export::where('project_id', $e->project_id)->sum('bytes') + strlen($bytes) > config('research.storage_bytes'), 422);
                if (Export::whereKey($e->id)->value('status') !== 'running') {
                    return;
                }$path = 'research/'.$e->project_id.'/export-'.$e->id;
                Storage::disk('local')->put($path, $bytes);
                Export::whereKey($e->id)->where('status', 'running')->update(['status' => 'completed', 'path' => $path, 'sha256' => $out['sha256'], 'bytes' => strlen($bytes), 'updated_at' => now()]);
                Events::add($e->project_id, 'export.completed', ['format' => $e->format, 'status' => 'completed']);
            });
        } catch (\Throwable) {
            Export::whereKey($e->id)->where('status', 'running')->update(['status' => 'failed', 'error_code' => 'export_processing_failed', 'updated_at' => now()]);
            Events::add($e->project_id, 'export.failed', ['format' => $e->format, 'error_code' => 'export_processing_failed']);
        }
    }
}
