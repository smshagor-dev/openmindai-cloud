<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class NvidiaModelProbe
{
    public function payload(object $model): ?array
    {
        if ($model->api_protocol === 'chat') {
            $messages = $model->slug === 'nvidia-riva-translate-4b-instruct-v2'
                ? [['role' => 'system', 'content' => 'en-ru'], ['role' => 'user', 'content' => 'Hello.']]
                : [['role' => 'user', 'content' => 'Reply OK.']];

            return ['model' => $model->provider_model, 'messages' => $messages, 'max_tokens' => 128, 'stream' => false];
        }
        if ($model->api_path === '/v1/embeddings') {
            return ['model' => $model->provider_model, 'input' => 'OpenMindAI connection test', 'input_type' => 'query'];
        }
        if ($model->slug === 'nvidia-nemotron-3.5-rerank-8b') {
            return ['model' => $model->provider_model, 'query' => ['text' => 'test'], 'passages' => [['text' => 'test document']]];
        }

        return null;
    }

    public function validResponse(object $model, mixed $json): bool
    {
        if (! is_array($json)) {
            return false;
        }
        if ($model->api_protocol === 'chat') {
            $text = $json['choices'][0]['message']['content'] ?? null;

            return is_string($text) && trim($text) !== '';
        }
        if ($model->api_path === '/v1/embeddings') {
            $vector = $json['data'][0]['embedding'] ?? null;

            return is_array($vector) && count($vector) > 0
                && count(array_filter($vector, fn ($n) => (is_int($n) || is_float($n)) && is_finite((float) $n))) === count($vector);
        }
        $rankings = $json['rankings'] ?? $json['results'] ?? null;

        return is_array($rankings) && count($rankings) > 0
            && is_array($rankings[0] ?? null)
            && is_numeric($rankings[0]['index'] ?? null)
            && is_numeric($rankings[0]['logit'] ?? $rankings[0]['relevance_score'] ?? $rankings[0]['score'] ?? null);
    }

    public function run(object $model, int $timeout = 45): array
    {
        $runtime = app(HostedModelRuntime::class)->inspect($model->slug);
        $result = ['status' => 'setup_required', 'http_status' => null, 'runtime' => $runtime];
        if (! $runtime['configured']) {
            return $result;
        }
        if (! $model->enabled || ! $model->api_ready) {
            return [...$result, 'status' => ! $model->enabled ? 'disabled' : 'setup_required'];
        }
        $payload = $this->payload($model);
        if ($payload === null) {
            return [...$result, 'status' => 'task_input_required'];
        }
        if (! in_array($model->api_origin, ['integrate.api.nvidia.com', 'ai.api.nvidia.com', 'health.api.nvidia.com'], true)
            || ! preg_match('~^/v1/[a-zA-Z0-9][a-zA-Z0-9/_.-]*$~D', $model->api_path ?? '')
            || str_contains($model->api_path, '..')) {
            return [...$result, 'status' => 'invalid_request'];
        }
        $key = (string) config('models.nvidia_key');
        if (strlen($key) < 12) {
            return [...$result, 'status' => 'setup_required'];
        }
        try {
            $response = Http::withToken($key)->acceptJson()->connectTimeout(10)->timeout(max(10, min(120, $timeout)))
                ->withOptions(['allow_redirects' => false])
                ->post('https://'.$model->api_origin.$model->api_path, $payload);
            $code = $response->status();
            $status = match ($code) {
                200 => 'invalid_response', 202 => 'pending',
                401, 402, 403 => 'access_denied', 429 => 'rate_limited',
                410 => 'retired',
                400, 404, 422 => 'invalid_request', default => 'unavailable',
            };
            if ($code === 200 && strlen($response->body()) <= 2097152) {
                $json = $response->json();
                $valid = $this->validResponse($model, $json);
                if ($valid) {
                    $status = 'success';
                }
            }

            return [...$result, 'status' => $status, 'http_status' => $code];
        } catch (\Throwable $e) {
            return [...$result, 'status' => 'unreachable'];
        }
    }
}
