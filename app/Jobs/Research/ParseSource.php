<?php

namespace App\Jobs\Research;

use App\Models\Research\Source;
use App\Services\Research\Events;
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

    public function handle(): void
    {
        $s = Source::find($this->sourceId);
        if (! $s || $s->private_path !== $this->path || ($s->metadata['upload_sha256'] ?? '') !== $this->hash) {
            return;
        }
        try {
            $bytes = Storage::disk('local')->get($this->path);
            if (! hash_equals($this->hash, hash('sha256', $bytes))) {
                throw new \RuntimeException('checksum_mismatch');
            }$s->update(['metadata' => array_merge($s->metadata, ['parse_status' => 'running'])]);
            $out = app(Worker::class)->call('extract', ['data' => base64_encode($bytes)]);
            $text = $out['text'] ?? null;
            if (! is_string($text) || strlen($text) > 2000000) {
                throw new \RuntimeException('invalid_pdf_text');
            }$s->refresh();
            if ($s->private_path !== $this->path || ($s->metadata['upload_sha256'] ?? '') !== $this->hash) {
                return;
            }$s->update(['text' => $text, 'metadata' => array_merge($s->metadata, ['parse_status' => 'completed', 'pages' => $out['pages'] ?? null])]);
            Events::add($s->project_id, 'pdf.parsed', ['source_id' => $s->id, 'status' => 'completed']);
        } catch (\Throwable) {
            $s->refresh();
            if ($s->private_path === $this->path && ($s->metadata['upload_sha256'] ?? '') === $this->hash) {
                $s->update(['metadata' => array_merge($s->metadata, ['parse_status' => 'failed'])]);
                Events::add($s->project_id, 'pdf.failed', ['source_id' => $s->id, 'error_code' => 'pdf_processing_failed', 'status' => 'failed']);
            }
        }
    }
}
