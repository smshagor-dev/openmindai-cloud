<?php

namespace App\Services\Studio;

use Illuminate\Support\Facades\Http;

/** Fixed public Space; no paid provider, arbitrary endpoint or local GPU. */
class FreeMeshClient
{
    public const ORIGIN = 'https://microsoft-trellis-2.hf.space';

    private float $deadline;

    public function generate(string $image, callable $stage, callable $check): string
    {
        $this->deadline = microtime(true) + 240;
        $response = $this->client()->get(self::ORIGIN.'/config');
        $this->accepted($response);
        $functions = [];
        foreach ($response->json('dependencies', []) as $dependency) {
            if (isset($dependency['api_name'], $dependency['id'], $dependency['inputs'])) {
                $functions[$dependency['api_name']] = $dependency;
            }
        }
        foreach (['preprocess_image' => 1, 'image_to_3d' => 16, 'extract_glb' => 3] as $name => $count) {
            if (! isset($functions[$name]) || count($functions[$name]['inputs']) !== $count || ! is_int($functions[$name]['id'])) {
                throw new FreeMeshException('free_provider_contract_changed');
            }
        }
        $session = bin2hex(random_bytes(16));
        $check();
        $response = $this->client()->attach('files', $image, 'reference.png')->post(self::ORIGIN.'/gradio_api/upload');
        $this->accepted($response);
        $path = $response->json('0');
        if (! is_string($path) || ! str_starts_with($path, '/tmp/gradio/') || strlen($path) > 1000) {
            throw new FreeMeshException('free_provider_contract_changed');
        }
        $reference = $this->call($functions['preprocess_image']['id'], [['path' => $path, 'meta' => ['_type' => 'gradio.FileData']]], $session, 'preprocessing', $stage, $check)[0] ?? null;
        if (! is_array($reference) || ! is_string($reference['path'] ?? null) || ! str_starts_with($reference['path'], '/tmp/gradio/')) {
            throw new FreeMeshException('free_provider_contract_changed');
        }
        // The Space stores the latent state server-side for this session. State
        // input is null in the wire protocol, never an invented GLB/relief.
        $this->call($functions['image_to_3d']['id'], [$reference, 0, '512', 7.5, 0.7, 12, 5.0, 7.5, 0.5, 12, 3.0, 1.0, 0.0, 12, 3.0], $session, 'generating', $stage, $check);
        $output = $this->call($functions['extract_glb']['id'], [null, 100000, 1024], $session, 'exporting', $stage, $check);
        $url = $output[0]['url'] ?? null;
        if (! is_string($url)) {
            throw new FreeMeshException('free_provider_contract_changed');
        }

        return $url;
    }

    private function call(int $function, array $data, string $session, string $phase, callable $stage, callable $check): array
    {
        $check();
        // Save the attempt BEFORE POST. Interrupted/unknown submissions are not
        // replayed by the cron consumer; a new user request consumes a new slot.
        $stage($phase, ['session' => $session, 'function' => $function]);
        $response = $this->client()->post(self::ORIGIN.'/gradio_api/queue/join', ['fn_index' => $function, 'data' => $data, 'session_hash' => $session]);
        $this->accepted($response);
        $event = $response->json('event_id');
        if (! is_string($event) || ! preg_match('/^[a-zA-Z0-9_-]{1,100}$/D', $event)) {
            throw new FreeMeshException('free_submission_unknown');
        }
        $stage($phase, ['session' => $session, 'function' => $function, 'event' => $event]);
        $response = $this->client($this->remaining())->withOptions(['stream' => true, 'read_timeout' => 20])->get(self::ORIGIN.'/gradio_api/queue/data', ['session_hash' => $session]);
        $this->accepted($response);
        $stream = $response->toPsrResponse()->getBody();
        $buffer = '';
        try {
            while (! $stream->eof()) {
                $check();
                $this->remaining();
                $buffer .= $stream->read(65536);
                if (strlen($buffer) > 32000000) {
                    throw new FreeMeshException('free_provider_response_too_large');
                }
                while (($end = strpos($buffer, "\n\n")) !== false) {
                    $frame = substr($buffer, 0, $end);
                    $buffer = substr($buffer, $end + 2);
                    if (! preg_match('/^data: (.+)$/ms', $frame, $matches)) {
                        continue;
                    }
                    $message = json_decode($matches[1], true);
                    if (! is_array($message)) {
                        throw new FreeMeshException('free_provider_contract_changed');
                    }
                    if (($message['msg'] ?? null) === 'process_completed' && ($message['event_id'] ?? null) === $event) {
                        if (($message['success'] ?? false) !== true) {
                            $this->providerError($message['output']['error'] ?? '');
                        }

                        return is_array($message['output']['data'] ?? null) ? $message['output']['data'] : [];
                    }
                    if (in_array($message['msg'] ?? '', ['unexpected_error', 'queue_full'], true)) {
                        $this->providerError($message['message'] ?? '');
                    }
                }
            }
            throw new FreeMeshException('free_stream_interrupted');
        } finally {
            $stream->close();
        }
    }

    public function cancel(array $remote): bool
    {
        if (! isset($remote['session'], $remote['function'], $remote['event'])) {
            return false;
        }
        try {
            $response = $this->client()->post(self::ORIGIN.'/gradio_api/cancel', ['session_hash' => $remote['session'], 'fn_index' => $remote['function'], 'event_id' => $remote['event']]);

            return $response->successful() && $response->json('success') === true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function remaining(): int
    {
        $remaining = (int) ($this->deadline - microtime(true));
        if ($remaining < 1) {
            throw new FreeMeshException('free_queue_timeout');
        }

        return $remaining;
    }

    private function client(int $seconds = 12)
    {
        $client = Http::acceptJson()->connectTimeout(4)->timeout($seconds)->withOptions(['allow_redirects' => false]);
        $token = (string) config('studio.free_mesh.token', '');

        return $token !== '' ? $client->withToken($token) : $client;
    }

    private function accepted($response): void
    {
        if ($response->successful()) {
            return;
        }
        if ($response->status() === 429) {
            throw new FreeMeshException('free_provider_quota');
        }
        if (in_array($response->status(), [401, 403], true)) {
            throw new FreeMeshException('free_provider_auth_required');
        }
        throw new FreeMeshException('free_provider_unavailable');
    }

    private function providerError($message): never
    {
        $text = is_string($message) ? mb_strtolower($message) : '';
        // Provider output can contain private URLs. Publish only known codes.
        throw new FreeMeshException(str_contains($text, 'quota') || str_contains($text, 'gpu time') ? 'free_provider_quota' : (str_contains($text, 'sign in') || str_contains($text, 'login') ? 'free_provider_auth_required' : 'free_generation_failed'));
    }
}
