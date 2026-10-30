<?php

namespace App\Jobs\Research;

use App\Models\Research\Project;
use App\Models\Research\Source;
use App\Services\Research\Events;
use App\Services\Research\Transactions;
use App\Services\Research\Worker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

class ParseSource implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 220;

    public function __construct(public int $sourceId, public string $path, public string $hash) {}

    private function mutate(Source $source, callable $fn): bool
    {
        return Transactions::run(function () use ($source, $fn) {
            Project::whereKey($source->project_id)->lockForUpdate()->firstOrFail();
            $source->refresh();
            if ($source->private_path !== $this->path || ($source->metadata['upload_sha256'] ?? '') !== $this->hash || ! in_array($source->metadata['parse_status'] ?? '', ['pending', 'running'], true)) {
                return false;
            }

            return $fn($source);
        });
    }

    public function handle(): void
    {
        $source = Source::find($this->sourceId);
        if (! $source) {
            return;
        }
        try {
            $started = $this->mutate($source, function ($s) {
                if (($s->metadata['parse_status'] ?? '') !== 'pending') {
                    return false;
                }$s->update(['metadata' => array_merge($s->metadata, ['parse_status' => 'running', 'parse_started_at' => now()->toIso8601String()])]);

                return true;
            });
            if (! $started) {
                return;
            }
            $bytes = Storage::disk('local')->get($this->path);
            if (! hash_equals($this->hash, hash('sha256', $bytes))) {
                throw new \RuntimeException('checksum_mismatch');
            }
            $out = app(Worker::class)->call('extract', ['data' => base64_encode($bytes)]);
            $text = $out['text'] ?? null;
            if (! is_string($text) || strlen($text) > 2000000) {
                throw new \RuntimeException('invalid_pdf_text');
            }
            $this->mutate($source, function ($s) use ($text, $out) {
                $s->update(['text' => $text, 'metadata' => array_merge($s->metadata, ['parse_status' => 'completed', 'pages' => $out['pages'] ?? null])]);
                Events::add($s->project_id, 'pdf.parsed', ['source_id' => $s->id, 'status' => 'completed']);

                return true;
            });
        } catch (\Throwable) {
            $this->mutate($source, function ($s) {
                $s->update(['metadata' => array_merge($s->metadata, ['parse_status' => 'failed'])]);
                Events::add($s->project_id, 'pdf.failed', ['source_id' => $s->id, 'error_code' => 'pdf_processing_failed', 'status' => 'failed']);

                return true;
            });
        }
    }
}
