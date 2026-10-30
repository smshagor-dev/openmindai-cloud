<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\ApiCreditUsage;
use App\Services\FirstPartySignature;
use App\Services\HostedCodeAgents;
use App\Services\MobileVisionPayload;
use App\Services\MultilingualPrompt;
use App\Services\NvidiaModelProbe;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\PersonalAccessToken;

class OpenMindAiGatewayController
{
    private function actor(Request $request, bool $firstParty, bool $mobile = false): User
    {
        if ($firstParty) {
            return app(FirstPartySignature::class)->user($request);
        }
        $user = $request->user();
        abort_unless($user && $user->is_active, 401);
        $token = $user->currentAccessToken();
        abort_unless($token instanceof PersonalAccessToken && ($mobile ? $token->can('user') : $token->can('openmindai:invoke')), 403, 'Model access is not permitted for this token.');

        return $user;
    }

    /** Mobile uses an ordinary authenticated user session, never a developer API key. */
    public function mobileModels(Request $request, HostedCodeAgents $agents)
    {
        $this->actor($request, false, true);

        return response()->json(['object' => 'list', 'data' => $agents->publicModels()])
            ->header('Cache-Control', 'no-store');
    }

    public function mobileChat(Request $request, HostedCodeAgents $agents, ApiCreditUsage $credits)
    {
        return $this->completion($request, false, $agents, $credits, true);
    }

    public function models(Request $request, HostedCodeAgents $agents)
    {
        $this->actor($request, false);

        return ['object' => 'list', 'data' => $agents->publicDeveloperModels()];
    }

    public function internalModels(Request $request, HostedCodeAgents $agents)
    {
        $this->actor($request, true);

        return ['object' => 'list', 'data' => $agents->publicModels()];
    }

    public function chat(Request $request, HostedCodeAgents $agents, ApiCreditUsage $credits)
    {
        return $this->completion($request, false, $agents, $credits);
    }

    public function internalChat(Request $request, HostedCodeAgents $agents, ApiCreditUsage $credits)
    {
        return $this->completion($request, true, $agents, $credits);
    }

    public function catalog(Request $request, HostedCodeAgents $agents)
    {
        $this->actor($request, false);

        return response()->json(['object' => 'list', 'data' => $agents->publicDeveloperCatalog()])
            ->header('Cache-Control', 'no-store');
    }

    public function internalCatalog(Request $request, HostedCodeAgents $agents)
    {
        $this->actor($request, true);

        return response()->json(['object' => 'list', 'data' => $agents->publicCatalog()])
            ->header('Cache-Control', 'no-store');
    }

    public function invoke(Request $request, string $slug, HostedCodeAgents $agents, ApiCreditUsage $credits)
    {
        return $this->invokeModel($request, $slug, false, $agents, $credits);
    }

    public function internalInvoke(Request $request, string $slug, HostedCodeAgents $agents, ApiCreditUsage $credits)
    {
        return $this->invokeModel($request, $slug, true, $agents, $credits);
    }

    /**
     * A constrained JSON gateway for non-chat NIM endpoints. Each model's path
     * is selected by an administrator, never by the client. This is not a binary
     * upload, websocket or asynchronous media job API.
     */
    private function invokeModel(Request $request, string $slug, bool $firstParty, HostedCodeAgents $agents, ApiCreditUsage $credits)
    {
        $user = $this->actor($request, $firstParty);
        abort_if($slug === 'nvidia-nemotron-voicechat', 422,
            'Use /api/v1/openmindai/voicechat/sessions for WebSocket realtime audio.');
        abort_if($slug === 'nvidia-magpie-tts-zeroshot', 422,
            'Use /api/v1/openmindai/tts/generations for queued Magpie speech synthesis.');
        abort_if($slug === 'nvidia-cosmos-transfer2.5-2b', 422,
            'Use /api/v1/openmindai/transfer/generations for controlled video NIM inference.');
        abort_if(isset(config('nvidia_av.routes', [])[$slug]), 422,
            'Use /api/v1/openmindai/av-nim/{model}/jobs for queued NIM scene inference.');
        abort_if($slug === 'nvidia-streampetr', 422,
            'Use /api/v1/openmindai/av/streampetr/jobs for queued scene inference.');
        abort_if(isset(config('maxine.models', [])[$slug]), 422,
            'Use /api/v1/openmindai/maxine/{model}/jobs for queued NVIDIA gRPC inference.');
        abort_if($slug === 'nvidia-cosmos3-nano', 422,
            'Use /api/v1/openmindai/cosmos/generations for queued Cosmos image/video generation.');
        $model = $agents->find($slug);
        abort_unless($model, 404, 'This model is unavailable.');
        abort_if($model->api_protocol === 'chat', 422, 'Use /chat/completions for chat models.');
        abort_unless($model->api_path && preg_match('~^/v1/[a-zA-Z0-9][a-zA-Z0-9/_.-]*$~D', $model->api_path) && ! str_contains($model->api_path, '..'),
            503, 'The NVIDIA API path has not been configured.');

        abort_if(strlen($request->getContent()) > 262144, 413, 'Request is too large for the JSON gateway.');
        abort_unless($request->isJson(), 415, 'JSON request required.');
        $data = $request->validate(['input' => 'required|array']);
        $payload = $data['input'];
        abort_if(count($payload) > 64, 422, 'Too many input fields.');
        // Prevent callers from selecting arbitrary paid or unauthorized models.
        unset($payload['model'], $payload['stream']);

        // NVIDIA's specialized inference services do not share one payload
        // schema. Normalize only confirmed protocols; retain a JSON envelope
        // for other explicitly administrator-configured services.
        if ($model->api_path === '/v1/embeddings') {
            abort_unless(isset($payload['input']) && (is_string($payload['input']) || is_array($payload['input'])), 422, 'Embeddings require text or image input.');
            abort_if(is_string($payload['input']) && trim($payload['input']) === '', 422, 'Embedding input is empty.');
            abort_if(isset($payload['input_type']) && ! in_array($payload['input_type'], ['query', 'passage'], true), 422, 'Invalid embedding input_type.');
            $payload['input_type'] ??= 'query';
        } elseif ($model->slug === 'nvidia-nemotron-3.5-rerank-8b') {
            abort_unless(isset($payload['query'], $payload['passages']) &&
                is_array($payload['query']) && is_array($payload['passages']) &&
                count($payload['passages']) >= 1 && count($payload['passages']) <= 512,
                422, 'Reranking requires a query object and 1–512 passages.');
        } elseif (in_array($model->slug, ['nvidia-kumo-tabular', 'nvidia-kumo-relational'], true)) {
            foreach (['task', 'schema', 'context', 'predict', 'output'] as $requiredField) {
                abort_unless(isset($payload[$requiredField]) && is_array($payload[$requiredField]),
                    422, 'Kumo requires task, schema, context, predict and output objects.');
            }
            // Kumo expects the model family instead of the NVIDIA catalog ID.
            $payload['model'] = $model->slug === 'nvidia-kumo-tabular' ? 'kumo-tabular' : 'kumo-relational';
        } elseif ($model->slug === 'nvidia-rfd4-proteina') {
            abort_unless(isset($payload['input']) && isset($payload['task']) &&
                in_array($payload['task'], ['generation', 'motif_scaffolding', 'enzyme_design',
                    'small_molecule_binder', 'antibody_design', 'partial_diffusion', 'inverse_folding'], true),
                422, 'Provide a valid RFD4 design task and input.');
        }
        if ($model->include_model) {
            $payload['model'] = $model->provider_model;
        }
        // Exact HTTPS hosts only: no client-supplied URLs or redirect following.
        $origin = $model->api_origin;
        abort_unless(in_array($origin, [
            'integrate.api.nvidia.com',
            'ai.api.nvidia.com',
            'health.api.nvidia.com',
        ], true), 503, 'This NVIDIA endpoint origin is unsupported.');
        $key = (string) config('models.nvidia_key');
        abort_unless(strlen($key) >= 12, 503, 'NVIDIA_API_KEY is not configured on the Laravel server.');

        $lock = Cache::lock('openmindai:gateway:'.$user->id, 240);
        abort_unless($lock->get(), 429, 'Another model request is already in progress.');
        try {
            $connection = $credits->connection('nvidia', $model->provider_model, strtolower($model->category));
            $from = $credits->monthStart();
            $used = (int) DB::table('api_credit_usage')->where('user_id', $user->id)
                ->where('created_at', '>=', $from)->sum('credits');
            $usedModel = (int) DB::table('api_credit_usage')->where('connection_id', $connection)
                ->where('created_at', '>=', $from)->sum('credits');
            $limit = DB::table('api_model_connections')->where('id', $connection)->value('monthly_credit_limit');
            abort_if($user->monthly_credit_limit !== null && $used >= $user->monthly_credit_limit,
                429, 'Your monthly API allowance has been reached.');
            abort_if($limit !== null && $usedModel >= $limit, 429, 'This model has reached its monthly allowance.');

            $upstream = Http::withToken($key)->acceptJson()->connectTimeout(10)->timeout(120)
                ->withOptions(['allow_redirects' => false])
                ->post('https://'.$origin.$model->api_path, $payload);
            if ($upstream->status() === 202) {
                return response()->json([
                    'model' => $model->slug,
                    'status' => 'pending',
                    'output' => $upstream->json(),
                ], 202)->header('Cache-Control', 'no-store');
            }
            if (! $upstream->successful()) {
                return response()->json(['error' => [
                    'type' => 'upstream_error',
                    'message' => $upstream->status() === 429
                        ? 'Provider is rate limited.'
                        : 'Model provider is temporarily unavailable.',
                ]], $upstream->status() === 429 ? 429 : 502);
            }
            abort_if(strlen($upstream->body()) > 2097152, 502, 'Provider JSON response is too large.');
            $result = $upstream->json();
            abort_unless(is_array($result), 502, 'Invalid JSON response from NVIDIA.');
            if ($model->api_path === '/v1/embeddings' || $model->slug === 'nvidia-nemotron-3.5-rerank-8b') {
                abort_unless(app(NvidiaModelProbe::class)->validResponse($model, $result),
                    502, 'Invalid model output. No completed request credit was recorded.');
            }
            $credits->completed($user->id, $connection, 'gateway:'.bin2hex(random_bytes(16)));

            return response()->json(['model' => $model->slug, 'output' => $result])
                ->header('Cache-Control', 'no-store');
        } finally {
            $lock->release();
        }
    }

    private function completion(Request $request, bool $firstParty, HostedCodeAgents $agents, ApiCreditUsage $credits, bool $mobile = false)
    {
        $user = $this->actor($request, $firstParty, $mobile);
        // Accept the full desktop tool registry (19 tools today) while
        // keeping total request size and individual tool-call counts bounded.
        abort_if(strlen($request->getContent()) > 1048576, 413, 'Chat request exceeds the 1 MiB gateway limit.');
        $data = $request->validate([
            'model' => 'required|string|max:60',
            'messages' => 'required|array|min:1|max:40',
            'messages.*.role' => 'required|in:system,user,assistant,tool',
            'messages.*.content' => $mobile
                ? ['sometimes', 'nullable', function ($attribute, $value, $fail) {
                    if (! is_array($value) && (! is_string($value) || mb_strlen($value) > 16000)) {
                        $fail('Message content must be text or image content parts.');
                    }
                }]
                : 'sometimes|nullable|string|max:16000',
            'messages.*.tool_call_id' => 'sometimes|string|max:120',
            'messages.*.tool_calls' => 'sometimes|array|max:64',
            'messages.*.tool_calls.*.id' => 'required|string|max:120',
            'messages.*.tool_calls.*.type' => 'required|in:function',
            'messages.*.tool_calls.*.function.name' => 'required|string|max:120',
            'messages.*.tool_calls.*.function.arguments' => 'required|string|max:32000',
            'tools' => 'sometimes|array|max:64',
            'tools.*.type' => 'required|in:function',
            'tools.*.function.name' => 'required|string|max:120',
            'tools.*.function.description' => 'sometimes|string|max:1000',
            'tools.*.function.parameters' => 'required|array',
            'stream' => 'sometimes|boolean',
            'max_tokens' => 'sometimes|integer|min:1|max:8192',
            'temperature' => 'sometimes|numeric|min:0|max:1',
            'chat_template_kwargs' => 'sometimes|array:enable_thinking',
            'chat_template_kwargs.enable_thinking' => 'sometimes|boolean',
        ]);
        $hasImages = $mobile && MobileVisionPayload::validate($data['messages']);
        foreach ($data['messages'] as $message) {
            $hasText = is_string($message['content'] ?? null) && trim($message['content']) !== '';
            if ($mobile && $message['role'] === 'user' && is_array($message['content'] ?? null)) {
                $hasText = count($message['content']) > 0;
            }
            if (in_array($message['role'], ['system', 'user'], true) && ! $hasText) {
                abort(422, 'User and system messages require content.');
            }
            if ($message['role'] === 'assistant' && ! $hasText && empty($message['tool_calls'])) {
                abort(422, 'Assistant messages require content or tool calls.');
            }
            if ($message['role'] === 'tool' && (! $hasText || empty($message['tool_call_id']))) {
                abort(422, 'Tool responses require a tool call ID and content.');
            }
        }
        $agent = $agents->find($data['model']);
        abort_unless($agent && $agent->api_protocol === 'chat', 404, 'This chat model is not enabled.');
        abort_if($hasImages && ! MobileVisionPayload::supports($agent), 422, 'Select a vision-capable model to read images.');
        $key = (string) config('models.nvidia_key');
        abort_unless(strlen($key) >= 12, 503, 'NVIDIA_API_KEY is not configured on the Laravel server.');
        $lock = Cache::lock('openmindai:gateway:'.$user->id, 240);
        abort_unless($lock->get(), 429, 'Another model request is already in progress.');
        $held = true;
        try {
            $connection = $credits->connection('nvidia', $agent->provider_model, 'code');
            $from = $credits->monthStart();
            $userUsed = (int) DB::table('api_credit_usage')->where('user_id', $user->id)->where('created_at', '>=', $from)->sum('credits');
            $modelUsed = (int) DB::table('api_credit_usage')->where('connection_id', $connection)->where('created_at', '>=', $from)->sum('credits');
            $userLimit = $user->monthly_credit_limit;
            $modelLimit = DB::table('api_model_connections')->where('id', $connection)->value('monthly_credit_limit');
            abort_if($userLimit !== null && $userUsed >= $userLimit, 429, 'Your monthly API allowance has been reached.');
            abort_if($modelLimit !== null && $modelUsed >= $modelLimit, 429, 'This model has reached its monthly allowance.');

            $stream = (bool) ($data['stream'] ?? false);
            $payload = [
                'model' => $agent->provider_model,
                'messages' => $hasImages ? MobileVisionPayload::providerMessages($data['messages'])
                    : (($mobile || $firstParty) ? MultilingualPrompt::apply($data['messages']) : $data['messages']),
                'max_tokens' => $data['max_tokens'] ?? 2048,
                'stream' => $stream,
            ];
            if (isset($data['tools'])) {
                $payload['tools'] = $data['tools'];
            }
            if (isset($data['temperature'])) {
                $payload['temperature'] = $data['temperature'];
            }
            // Quick mobile chat turns off hidden reasoning on hybrid models,
            // as guest chat already does; otherwise answers can wait minutes.
            if (isset($data['chat_template_kwargs']['enable_thinking'])) {
                $payload['chat_template_kwargs'] = [
                    'enable_thinking' => (bool) $data['chat_template_kwargs']['enable_thinking'],
                ];
            }
            $upstream = Http::withToken($key)->accept($stream ? 'text/event-stream' : 'application/json')
                ->connectTimeout(10)->timeout(120)->withOptions(['allow_redirects' => false, 'stream' => $stream])
                ->post('https://integrate.api.nvidia.com/v1/chat/completions', $payload);
            if (! $upstream->successful()) {
                $code = $upstream->status();
                // A provider-level 400/422 is different from our own former
                // 12-tool validation cap. Return a safe, actionable error
                // without echoing upstream data or private NVIDIA credentials.
                $validationError = in_array($code, [400, 422], true);

                return response()->json([
                    'error' => [
                        'message' => $code === 429
                            ? 'Provider is rate limited.'
                            : ($validationError
                                ? 'NVIDIA rejected the request format or tool definitions for this model.'
                                : 'Model provider is temporarily unavailable.'),
                        'type' => 'upstream_error',
                        'upstream_status' => $code,
                    ],
                ], $validationError ? 422 : ($code === 429 ? 429 : 502));
            }

            $usageKey = 'gateway:'.bin2hex(random_bytes(16));
            if (! $stream) {
                $result = $upstream->json();
                abort_unless(is_array($result) && isset($result['choices'][0]['message']), 502, 'Invalid model response.');
                $credits->completed($user->id, $connection, $usageKey);

                return response()->json([
                    'id' => $result['id'] ?? $usageKey,
                    'object' => 'chat.completion',
                    'created' => $result['created'] ?? time(),
                    'model' => $agent->slug,
                    'choices' => $result['choices'],
                    'usage' => $result['usage'] ?? null,
                ])->header('Cache-Control', 'no-store');
            }

            $held = false;

            return response()->stream(function () use ($upstream, $credits, $user, $connection, $usageKey, $lock, $agent) {
                $body = $upstream->toPsrResponse()->getBody();
                $buffer = '';
                $done = false;
                try {
                    while (! $body->eof()) {
                        if (connection_aborted()) {
                            break;
                        }
                        $chunk = $body->read(8192);
                        if ($chunk === '') {
                            break;
                        }
                        $buffer .= str_replace("\r\n", "\n", $chunk);
                        if (strlen($buffer) > 1048576) {
                            break; // Reject unexpectedly large or malformed SSE frames.
                        }
                        while (($end = strpos($buffer, "\n\n")) !== false) {
                            $frame = substr($buffer, 0, $end);
                            $buffer = substr($buffer, $end + 2);
                            foreach (explode("\n", $frame) as $line) {
                                if (! str_starts_with($line, 'data:')) {
                                    continue;
                                }
                                $json = trim(substr($line, 5));
                                if ($json === '[DONE]') {
                                    $done = true;
                                    echo "data: [DONE]\n\n";
                                    break 2;
                                }
                                $decoded = json_decode($json, true);
                                if (! is_array($decoded)) {
                                    continue;
                                }
                                $decoded['model'] = $agent->slug;
                                echo 'data: '.json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)."\n\n";
                            }
                            if (ob_get_level() > 0) {
                                @ob_flush();
                            }
                            flush();
                        }
                    }
                    if ($done) {
                        $credits->completed($user->id, $connection, $usageKey);
                    }
                } finally {
                    $body->close();
                    $lock->release();
                }
            }, 200, ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache, no-transform', 'X-Accel-Buffering' => 'no']);
        } finally {
            if ($held) {
                $lock->release();
            }
        }
    }
}
