<?php

namespace App\Services\Research;

use Illuminate\Support\Facades\File;

/** Short-lived private transfers keep large PDFs/results out of function HTTP bodies. */
class WorkerTransfers
{
    private function directory(): string
    {
        $path = storage_path('app/private/research-worker-transfers');
        File::ensureDirectoryExists($path, 0700, true);

        return $path;
    }

    private function path(string $id, string $suffix): string
    {
        abort_unless(preg_match('/^[a-f0-9]{64}$/', $id), 404);

        return $this->directory().'/'.$id.'.'.$suffix;
    }

    public function create(string $operation, array $payload): array
    {
        $raw = json_encode(['operation' => $operation, 'payload' => $payload], JSON_THROW_ON_ERROR);
        abort_if(strlen($raw) > 24000000, 413);
        $id = bin2hex(random_bytes(32));
        $ticket = bin2hex(random_bytes(32));
        $meta = ['ticket_hash' => hash('sha256', $ticket), 'expires' => time() + 240, 'state' => 'created', 'execution_id' => $operation === 'experiment' ? ($payload['execution_id'] ?? null) : null];
        foreach (['input' => $raw, 'meta' => json_encode($meta, JSON_THROW_ON_ERROR)] as $suffix => $text) {
            File::put($this->path($id, $suffix), $text, true);
            chmod($this->path($id, $suffix), 0600);
        }

        return ['id' => $id, 'ticket' => $ticket];
    }

    public function access(string $id, string $ticket, string $action, ?string $body = null): string
    {
        $path = $this->path($id, 'meta');
        abort_unless(is_file($path), 404);
        $handle = fopen($path, 'r+');
        abort_unless($handle && flock($handle, LOCK_EX), 503);
        try {
            $meta = json_decode(stream_get_contents($handle), true, 16, JSON_THROW_ON_ERROR);
            abort_unless(strlen($ticket) === 64 && hash_equals($meta['ticket_hash'], hash('sha256', $ticket)), 401);
            abort_if($meta['expires'] < time(), 410);
            $execution = $meta['execution_id'];
            abort_if($execution && $this->cancelled($execution), 409, 'Execution cancelled.');
            if ($action === 'input') {
                abort_unless($meta['state'] === 'created', 409);
                $meta['state'] = 'claimed';
                $result = File::get($this->path($id, 'input'));
            } elseif ($action === 'authorize') {
                abort_unless($meta['state'] === 'claimed', 409);
                $meta['state'] = 'authorized';
                $result = '{"authorized":true}';
            } else {
                abort_unless($action === 'result' && $meta['state'] === 'authorized', 409);
                abort_if($body === null || strlen($body) > 28000000, 413);
                $decoded = json_decode($body, true, 128, JSON_THROW_ON_ERROR);
                abort_unless(is_array($decoded), 422);
                File::put($this->path($id, 'result'), $body, true);
                chmod($this->path($id, 'result'), 0600);
                $meta['state'] = 'completed';
                $result = '{"stored":true}';
            }
            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, json_encode($meta, JSON_THROW_ON_ERROR));
            fflush($handle);

            return $result;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function result(string $id): array
    {
        $meta = json_decode(File::get($this->path($id, 'meta')), true, 16, JSON_THROW_ON_ERROR);
        if ($meta['state'] !== 'completed') {
            throw new \RuntimeException('research_worker_result_missing');
        }

        return json_decode(File::get($this->path($id, 'result')), true, 128, JSON_THROW_ON_ERROR);
    }

    public function remove(string $id): void
    {
        foreach (['input', 'meta', 'result'] as $suffix) {
            File::delete($this->path($id, $suffix));
        }
    }

    public function cancel(string $execution): void
    {
        abort_unless(preg_match('/^research-[a-f0-9]{32}$/', $execution), 422);
        File::put($this->directory().'/cancel-'.$execution, (string) time(), true);
    }

    private function cancelled(string $execution): bool
    {
        return preg_match('/^research-[a-f0-9]{32}$/', $execution) !== 1 || is_file($this->directory().'/cancel-'.$execution);
    }

    public function prune(): void
    {
        foreach (File::files($this->directory()) as $file) {
            $name = $file->getFilename();
            if (preg_match('/^[a-f0-9]{64}\.(input|meta|result)$/', $name) && $file->getMTime() < time() - 900) {
                File::delete($file->getPathname());
            }
        }
    }
}
