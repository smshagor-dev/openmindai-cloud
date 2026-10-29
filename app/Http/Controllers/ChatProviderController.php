<?php

namespace App\Http\Controllers;

use App\Services\HostedCodeAgents;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class ChatProviderController
{
    public function send(Request $request, HostedCodeAgents $agents)
    {
        $bridge = (string) config('models.frontend_chat_key');
        abort_unless(strlen($bridge) >= 32 && hash_equals($bridge, (string) $request->header('X-Chat-Bridge-Key')), 403);
        abort_if(strlen($request->getContent()) > 6000000, 413);
        $data = $request->validate([
            'model' => 'required|string|max:190', 'messages' => 'required|array|min:1|max:200',
            'messages.*.role' => 'required|in:system,user,assistant,tool',
            'messages.*.content' => 'present',
            'messages.*.tool_call_id' => 'sometimes|string|max:190',
            'messages.*.tool_calls' => 'sometimes|array|max:8',
            'messages.*.tool_calls.*.id' => 'required|string|max:190',
            'messages.*.tool_calls.*.type' => 'required|in:function',
            'messages.*.tool_calls.*.function.name' => 'required|string|max:80',
            'messages.*.tool_calls.*.function.arguments' => 'required|string|max:20000',
            'tools' => 'sometimes|array|max:8',
            'tools.*.type' => 'required|in:function',
            'tools.*.function.name' => 'required|in:web_search,read_page,ask_specialist,create_artifact,decline_web_search',
            'tools.*.function.description' => 'sometimes|string|max:10000',
            'tools.*.function.parameters' => 'required|array',
            'tool_choice' => 'sometimes|in:auto,none,required', 'stream' => 'sometimes|boolean',
            'max_tokens' => 'sometimes|integer|min:1|max:32768',
            'temperature' => 'sometimes|numeric|min:0|max:2',
            'chat_template_kwargs' => 'sometimes|array:enable_thinking',
            'chat_template_kwargs.enable_thinking' => 'sometimes|boolean',
        ]);
        abort_unless($agents->enabled()->contains('provider_model', $data['model']), 403, 'This model is not enabled.');
        $key = (string) config('models.nvidia_key');
        abort_unless(strlen($key) >= 12, 503, 'Backend inference is not configured.');
        $stream = (bool) ($data['stream'] ?? false);
        \App\Services\NvidiaRequestRate::reserve();
            $upstream = Http::withToken($key)->accept($stream ? 'text/event-stream' : 'application/json')
            ->connectTimeout(10)->timeout(240)->withOptions(['allow_redirects' => false, 'stream' => $stream])
            ->post('https://integrate.api.nvidia.com/v1/chat/completions', $data);
        abort_unless($upstream->successful(), $upstream->status() === 429 ? 429 : 502, 'The chat provider is temporarily unavailable.');
        if (! $stream) {
            return response()->json($upstream->json())->header('Cache-Control', 'no-store');
        }

        return response()->stream(function () use ($upstream) {
            $body = $upstream->toPsrResponse()->getBody();
            try {
                while (! $body->eof() && ! connection_aborted()) {
                    echo $body->read(8192);
                    if (ob_get_level() > 0) {
                        ob_flush();
                    }
                    flush();
                }
            } finally {
                $body->close();
            }
        }, 200, ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-store', 'X-Accel-Buffering' => 'no']);
    }
}
