<?php

namespace App\Services\Research;

use App\Jobs\Research\ExportDocument;
use App\Jobs\Research\NotifyDelivery;
use App\Models\Research\Delivery;
use App\Models\Research\Document;
use App\Models\Research\Export;
use App\Models\Research\Finding;
use App\Models\Research\Project;
use App\Models\Research\Run;

class Deliveries
{
    public const FORMATS = ['docx', 'pdf', 'tex', 'bib', 'md'];

    public function generate(int $runId): ?Delivery
    {
        return Transactions::run(function () use ($runId) {
            $run = Run::findOrFail($runId);
            $project = Project::whereKey($run->project_id)->lockForUpdate()->firstOrFail();
            if ($run->status !== 'completed' || ! $run->actor_id || ! app(Access::class)->project($project->id, $run->actor_id, ['owner', 'editor'])) {
                return null;
            }
            $saved = Document::where('project_id', $project->id)->firstOrFail();
            $document = $saved->content;
            $source = 'saved';
            $proposal = Finding::where('project_id', $project->id)->where('kind', 'manuscript')->latest('id')->first()?->data;
            if ($proposal && ($proposal['base_version'] ?? null) === $saved->version) {
                $document = $proposal['content'];
                $source = 'proposal';
            }
            $text = '';
            $walk = function ($n) use (&$walk, &$text) {
                if (($n['type'] ?? '') === 'text') {
                    $text .= $n['text'] ?? '';
                }foreach ($n['content'] ?? [] as $c) {
                    $walk($c);
                }
            };
            $walk($document);
            if (trim($text) === '') {
                return null;
            }
            $g = app(IntegrityGates::class)->inspect($project->id);
            $draft = $source === 'proposal' || ! $g['passed'];
            $fingerprint = hash('sha256', json_encode([$document, $source, $saved->version, $g, $project->settings], JSON_UNESCAPED_UNICODE));
            $existing = Delivery::where('project_id', $project->id)->where('fingerprint', $fingerprint)->first();
            if ($existing) {
                return $existing;
            }
            $delivery = Delivery::create(['project_id' => $project->id, 'run_id' => $runId, 'actor_id' => $run->actor_id, 'fingerprint' => $fingerprint, 'content_source' => $source, 'draft' => $draft]);
            if (Export::where('project_id', $project->id)->count() + 5 > 100) {
                $delivery->update(['status' => 'failed', 'error_code' => 'export_count_limit']);

                return $delivery;
            }
            $snapshot = app(ExportSnapshots::class)->make($project->id, $document, $saved->version, $draft);
            foreach (self::FORMATS as $format) {
                $export = Export::create(['project_id' => $project->id, 'actor_id' => $run->actor_id, 'delivery_id' => $delivery->id, 'format' => $format, 'draft' => $draft, 'snapshot' => $snapshot + ['format' => $format, 'content_source' => $source]]);
                ExportDocument::dispatch($export->id)->onConnection('database')->onQueue('research')->afterCommit();
            }
            Events::add($project->id, 'delivery.queued', ['delivery_id' => $delivery->id, 'formats' => self::FORMATS, 'draft' => $draft, 'content_source' => $source]);

            return $delivery;
        });
    }

    public function settle(int $id): void
    {
        Transactions::run(function () use ($id) {
            $hint = Delivery::find($id);
            if (! $hint) {
                return;
            }Project::whereKey($hint->project_id)->lockForUpdate()->firstOrFail();
            $delivery = Delivery::whereKey($id)->lockForUpdate()->firstOrFail();
            $files = Export::where('delivery_id', $id)->get();
            if ($files->count() !== 5 || $files->contains(fn ($e) => in_array($e->status, ['queued', 'running']))) {
                return;
            }
            $count = $files->where('status', 'completed')->count();
            $status = $count === 5 ? 'completed' : ($count ? 'partial' : 'failed');
            if ($delivery->status === $status) {
                return;
            }$delivery->update(['status' => $status]);
            Events::add($delivery->project_id, 'delivery.'.$status, ['delivery_id' => $id, 'status' => $status, 'completed_formats' => $files->where('status', 'completed')->pluck('format')->all(), 'draft' => $delivery->draft]);
            NotifyDelivery::dispatch($id)->onConnection('database')->onQueue('research')->afterCommit();
        });
    }

    public function retry(int $id): Delivery
    {
        return Transactions::run(function () use ($id) {
            $hint = Delivery::findOrFail($id);
            Project::whereKey($hint->project_id)->lockForUpdate()->firstOrFail();
            $d = Delivery::whereKey($id)->lockForUpdate()->firstOrFail();
            $failed = Export::where('delivery_id', $id)->where('status', 'failed')->get();
            abort_if($failed->isEmpty(), 422, 'No failed files to retry.');
            $d->update(['status' => 'queued', 'notified_at' => null]);
            foreach ($failed as $e) {
                $e->update(['status' => 'queued', 'error_code' => null]);
                ExportDocument::dispatch($e->id)->onConnection('database')->onQueue('research')->afterCommit();
            }

            return $d;
        });
    }
}
