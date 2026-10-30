<?php

namespace App\Services\Studio;

use Illuminate\Support\Facades\Http;

class Worker
{
    public function request(string $method, string $path, array $data = []): array
    {
        $base = rtrim((string) config('studio.worker_url'), '/');
        $key = (string) config('studio.worker_key');
        $origin = parse_url($base);
        abort_unless(is_array($origin) && ($origin['scheme'] ?? '') === 'https' && ! empty($origin['host'])
            && ! isset($origin['user']) && ! isset($origin['pass']) && empty($origin['query']) && empty($origin['fragment'])
            && empty($origin['path']) && strlen($key) >= 32, 503, 'The isolated 3D worker is not configured.');
        abort_unless(preg_match('#^/(health|jobs(?:/[a-f0-9-]{36}(?:/cancel|/artifact)?)?)$#D', $path), 422);
        if ($path === '/jobs' && isset($data['params']) && $data['params'] === []) {
            $data['params'] = new \stdClass;
        }
        $body = $method === 'GET' ? '' : json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $time = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $signature = hash_hmac('sha256', implode("\n", [$method, $path, $time, $nonce, hash('sha256', $body)]), $key);
        $response = Http::acceptJson()->connectTimeout(5)->timeout(config('studio.worker_timeout'))
            ->withOptions(['allow_redirects' => false])->withHeaders(['X-Studio-Time' => $time, 'X-Studio-Nonce' => $nonce, 'X-Studio-Signature' => $signature])
            ->withBody($body, 'application/json')->send($method, $base.$path);
        if (in_array($response->status(), [404, 409, 422], true)) {
            abort($response->status(), match ($response->status()) {
                404 => 'worker_job_not_found', 409 => 'worker_idempotency_conflict', default => 'worker_input_rejected'
            });
        }
        abort_unless($response->successful() && strlen($response->body()) <= 4500000, 503, 'The isolated 3D worker is unavailable.');
        $result = $response->json();
        abort_unless(is_array($result), 502, 'Invalid 3D worker response.');

        return $result;
    }

    public function health(): array
    {
        try {
            $result = $this->request('GET', '/health');

            return ($result['protocol'] ?? null) === 1 ? $result : ['ready' => false, 'reason' => 'worker_protocol_mismatch'];
        } catch (\Throwable) {
            return ['ready' => false, 'reason' => 'worker_not_verified', 'engines' => []];
        }
    }
}
