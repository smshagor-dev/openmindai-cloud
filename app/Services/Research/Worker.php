<?php

namespace App\Services\Research;

use Illuminate\Support\Facades\Http;

class Worker
{
    public function configured(): bool
    {
        return $this->configuration() !== null;
    }

    private function configuration(): ?array
    {
        $url = (string) config('research.worker_url');
        $key = (string) config('research.worker_key');
        $p = parse_url($url);
        $host = $p['host'] ?? '';
        $valid = $host !== '' && strlen($key) >= 32 && isset($p['scheme']) && ! isset($p['user']) && ! isset($p['pass']) && empty($p['query']) && empty($p['fragment']) && ($p['scheme'] === 'https' || ($p['scheme'] === 'http' && in_array($host, ['127.0.0.1', 'localhost', '[::1]'], true)));

        return $valid ? [rtrim($url, '/'), $key] : null;
    }

    public function health(): array
    {
        $configuration = $this->configuration();
        if ($configuration === null) {
            return ['status' => 'setup_required', 'configured' => false, 'runtime_checked' => false];
        }
        [$url, $key] = $configuration;
        try {
            $r = Http::withToken($key)->acceptJson()->connectTimeout(3)->timeout(config('research.worker_transport') === 'vercel' ? 30 : 6)->withOptions(['allow_redirects' => false, 'stream' => true])->get($url.'/health');
            $body = $r->toPsrResponse()->getBody();
            $raw = '';
            try {
                while (! $body->eof() && strlen($raw) <= 16384) {
                    $raw .= $body->read(4096);
                }
            } finally {
                $body->close();
            }
            if ($r->status() === 401 || $r->status() === 403) {
                return ['status' => 'authorization_failed', 'configured' => true, 'runtime_checked' => false];
            }
            $data = strlen($raw) <= 16384 ? json_decode($raw, true) : null;
            $runtime = is_array($data) && ($data['runtime_checked'] ?? false) === true;
            $isolation = config('research.worker_transport') === 'vercel' ? 'isolated_microvm' : 'isolated_container';
            $ready = $r->successful() && $runtime && ($data['status'] ?? '') === 'ready' && ($data['document_processing'] ?? '') === $isolation && ($data['experiments'] ?? '') === $isolation;
            if (config('research.worker_transport') === 'vercel') {
                $ready = $ready && ExecutionProvenance::runtime($data);
            }

            return ['status' => $ready ? 'ready' : (($data['status'] ?? '') === 'setup_required' ? 'setup_required' : ($runtime ? 'runtime_unavailable' : 'unverified_runtime')), 'configured' => true, 'runtime_checked' => $runtime];
        } catch (\Throwable) {
            return ['status' => 'unreachable', 'configured' => true, 'runtime_checked' => false];
        }
    }

    public function call(string $operation, array $payload): array
    {
        abort_unless(in_array($operation, ['extract', 'export', 'experiment', 'cancel'], true), 422);
        $configuration = $this->configuration();
        abort_unless($configuration, 503, 'Private research worker is not configured.');
        [$url, $key] = $configuration;
        if (config('research.worker_transport') === 'vercel') {
            $transfers = app(WorkerTransfers::class);
            if ($operation === 'cancel') {
                $transfers->cancel($payload['execution_id'] ?? '');
                $r = Http::withToken($key)->acceptJson()->connectTimeout(3)->timeout(8)->withOptions(['allow_redirects' => false])->post($url.'/cancel', $payload);
                if (! $r->successful()) {
                    throw new \RuntimeException('research_cancellation_unconfirmed');
                }

                return $r->json();
            }
            $ticket = $transfers->create($operation, $payload);
            try {
                $r = Http::withToken($key)->acceptJson()->connectTimeout(8)->timeout(180)->withOptions(['allow_redirects' => false])->post($url.'/'.$operation, $ticket);
                if (! $r->successful() || $r->json('stored') !== true) {
                    throw new \RuntimeException('research_worker_failed');
                }

                return $transfers->result($ticket['id']);
            } finally {
                $transfers->remove($ticket['id']);
            }
        }
        $r = Http::withToken($key)->acceptJson()->connectTimeout(8)->timeout($operation === 'cancel' ? 5 : 160)->withOptions(['allow_redirects' => false, 'stream' => true])->post(rtrim($url, '/').'/'.$operation, $payload);
        if (! $r->successful()) {
            throw new \RuntimeException('research_worker_failed');
        }$s = $r->toPsrResponse()->getBody();
        $text = '';
        try {
            while (! $s->eof()) {
                $text .= $s->read(65536);
                if (strlen($text) > 28000000) {
                    throw new \RuntimeException('worker_response_limit');
                }
            }
        } finally {
            $s->close();
        }

        return json_decode($text, true, 128, JSON_THROW_ON_ERROR);
    }
}
