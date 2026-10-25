<?php

namespace App\Services\Research;

use Illuminate\Support\Facades\Http;

class Worker
{
    public function call(string $operation, array $payload): array
    {
        abort_unless(in_array($operation, ['extract', 'export', 'experiment', 'cancel'], true), 422);
        $url = (string) config('research.worker_url');
        $key = (string) config('research.worker_key');
        $p = parse_url($url);
        $host = $p['host'] ?? '';
        abort_unless(strlen($key) >= 32 && isset($p['scheme']) && ! isset($p['user'],$p['pass']) && empty($p['query']) && empty($p['fragment']) && ($p['scheme'] === 'https' || ($p['scheme'] === 'http' && in_array($host, ['127.0.0.1', 'localhost', '[::1]'], true))), 503, 'Private research worker is not configured.');
        $r = Http::withToken($key)->acceptJson()->connectTimeout(8)->timeout(160)->withOptions(['allow_redirects' => false, 'stream' => true])->post(rtrim($url, '/').'/'.$operation, $payload);
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
