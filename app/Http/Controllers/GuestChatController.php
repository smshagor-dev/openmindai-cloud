<?php

namespace App\Http\Controllers;

use App\Services\AdminSettings;
use App\Services\HostedCodeAgents;
use App\Services\MultilingualPrompt;
use App\Services\NvidiaRequestRate;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

class GuestChatController
{
    public function send(Request $request, AdminSettings $settings, HostedCodeAgents $agents)
    {
        $data = $request->validate([
            'message' => 'required|string|max:4000',
            'history' => 'sometimes|array|max:10',
            'history.*.role' => 'required|in:user,assistant',
            'history.*.content' => 'required|string|max:8000',
        ]);
        $limit = $settings->limit('guest_chat_daily_limit');
        abort_if($limit === 0, 403, 'Please log in to chat.');
        $ip = $request->ip();
        $forwarded = (string) $request->header('X-Guest-IP');
        $bridge = (string) config('models.frontend_chat_key');
        if (strlen($bridge) >= 32 && filter_var($forwarded, FILTER_VALIDATE_IP) &&
            hash_equals(hash_hmac('sha256', $forwarded, $bridge), (string) $request->header('X-Guest-Signature'))) {
            $ip = $forwarded;
        }
        $bucket = 'guest-chat:'.hash('sha256', $ip);
        $lock = Cache::lock($bucket.':lock', 150);
        abort_unless($lock->get(), 429, 'A guest request is already running.');
        try {
            abort_if(RateLimiter::tooManyAttempts($bucket, $limit), 429, 'Your guest chat limit has been reached. Log in to continue.');
            $model = $agents->enabled()->firstWhere('slug', 'openmindai-lite') ?? $agents->enabled()->first();
            abort_unless($model, 503, 'No guest chat model is available.');
            $key = (string) config('models.nvidia_key');
            abort_unless(strlen($key) >= 12, 503, 'Guest chat is not configured.');
            // Charge attempted inference too, so repeated provider failures cannot exhaust resources.
            RateLimiter::hit($bucket, 86400);
            NvidiaRequestRate::reserve();
            $response = Http::withToken($key)->acceptJson()->connectTimeout(10)->timeout(120)
                ->withOptions(['allow_redirects' => false])
                ->post('https://integrate.api.nvidia.com/v1/chat/completions', [
                    'model' => $model->provider_model,
                    'messages' => [
                        ['role' => 'system', 'content' => 'You are OpenMindAI. Answer clearly. Use fenced code blocks with a language name for code.'."\n".MultilingualPrompt::instructions()],
                        ...($data['history'] ?? []),
                        ['role' => 'user', 'content' => $data['message']],
                    ],
                    'max_tokens' => 2048, 'stream' => false,
                    'chat_template_kwargs' => ['enable_thinking' => false],
                ]);
            abort_unless($response->successful(), 502, 'The chat service is temporarily unavailable.');
            $content = $response->json('choices.0.message.content');
            abort_unless(is_string($content) && trim($content) !== '' && strlen($content) <= 262144, 502, 'The chat service returned an empty response.');

            return response()->json(['content' => $content, 'remaining' => max(0, $limit - RateLimiter::attempts($bucket))])->header('Cache-Control', 'no-store');
        } catch (ConnectionException $exception) {
            abort(503, 'The chat service is temporarily unavailable.');
        } finally {
            $lock->release();
        }
    }
}
