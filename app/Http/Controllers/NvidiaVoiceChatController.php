<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\ApiCreditUsage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

class NvidiaVoiceChatController
{
    private function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user && $user->is_active, 401);
        $token = $user->currentAccessToken();
        abort_unless($token instanceof PersonalAccessToken && $token->can('openmindai:invoke'),
            403, 'A personal Developer API Key with model access is required.');

        return $user;
    }

    private function worker(Request $request): void
    {
        $secret = (string) config('voicechat.worker_key');
        $provided = (string) $request->header('X-OpenMindAI-Voicechat-Worker', '');
        abort_unless(strlen($secret) >= 32 && strlen($provided) === strlen($secret)
            && hash_equals($secret, $provided), 401, 'Worker authentication required.');
    }

    public function start(Request $request, ApiCreditUsage $credits)
    {
        $user = $this->actor($request);
        $url = (string) config('voicechat.ws_url');
        abort_unless(str_starts_with($url, 'wss://')
            && ! str_contains($url, '?') && ! str_contains($url, '#')
            && strlen((string) config('voicechat.worker_key')) >= 32,
            503, 'A secure VoiceChat relay must be configured.');
        $model = DB::table('hosted_code_agents')->where('slug', 'nvidia-nemotron-voicechat')
            ->where('api_ready', true)->first();
        abort_unless($model, 503, 'VoiceChat model route is not configured.');

        return Cache::lock('voicechat:start:'.$user->id, 12)->block(3, function () use ($user, $credits, $model, $url) {
            // Old, unused tickets cannot prevent the user starting again.
            DB::table('voicechat_sessions')->where('user_id', $user->id)
                ->where('status', 'issued')->where('expires_at', '<', now())
                ->update(['status' => 'expired', 'ended_at' => now(), 'updated_at' => now()]);
            $running = DB::table('voicechat_sessions')->where('user_id', $user->id)
                ->whereIn('status', ['issued', 'claimed'])
                ->where('created_at', '>=', now()->subMinutes(20))->exists();
            abort_if($running, 429, 'An active VoiceChat session already exists.');

            $from = $credits->monthStart();
            $used = (int) DB::table('api_credit_usage')->where('user_id', $user->id)
                ->where('created_at', '>=', $from)->sum('credits');
            $connection = $credits->connection('nvidia', $model->provider_model, 'audio');
            $modelUsed = (int) DB::table('api_credit_usage')->where('connection_id', $connection)
                ->where('created_at', '>=', $from)->sum('credits');
            $limit = DB::table('api_model_connections')->where('id', $connection)->value('monthly_credit_limit');
            abort_if($user->monthly_credit_limit !== null && $used >= $user->monthly_credit_limit,
                429, 'Monthly API allowance reached.');
            abort_if($limit !== null && $modelUsed >= $limit,
                429, 'Model monthly allowance reached.');

            $sessionId = (string) Str::uuid();
            $ticket = bin2hex(random_bytes(32));
            DB::table('voicechat_sessions')->insert([
                'id' => $sessionId, 'user_id' => $user->id,
                'ticket_hash' => hash('sha256', $ticket),
                'status' => 'issued', 'produced_audio' => false,
                'expires_at' => now()->addSeconds((int) config('voicechat.ticket_seconds', 60)),
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return response()->json([
                'id' => $sessionId, 'model' => 'nvidia-nemotron-voicechat',
                'status' => 'issued', 'protocol' => 'openai-realtime',
                'sample_rate' => 24000, 'format' => 'pcm16',
                'ws_url' => $url.'?ticket='.$ticket,
                'expires_in_seconds' => (int) config('voicechat.ticket_seconds', 60),
            ], 201)->header('Cache-Control', 'no-store');
        });
    }

    public function status(Request $request, string $id)
    {
        $user = $this->actor($request);
        $row = DB::table('voicechat_sessions')->where('id', $id)
            ->where('user_id', $user->id)->first();
        abort_unless($row, 404);

        return response()->json([
            'id' => $id, 'model' => 'nvidia-nemotron-voicechat',
            'status' => $row->status,
            'produced_audio' => (bool) $row->produced_audio,
        ])->header('Cache-Control', 'no-store');
    }

    public function close(Request $request, string $id)
    {
        $user = $this->actor($request);
        $changed = DB::table('voicechat_sessions')->where('id', $id)
            ->where('user_id', $user->id)->whereIn('status', ['issued', 'claimed'])
            ->update(['status' => 'closed', 'ended_at' => now(), 'updated_at' => now()]);
        abort_unless($changed, 404);

        return response()->json(['id' => $id, 'status' => 'closed'])
            ->header('Cache-Control', 'no-store');
    }

    public function claim(Request $request)
    {
        $this->worker($request);
        $data = $request->validate(['ticket' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D']]);
        $hash = hash('sha256', $data['ticket']);
        $row = DB::table('voicechat_sessions')->where('ticket_hash', $hash)
            ->where('status', 'issued')->where('expires_at', '>=', now())->first();
        abort_unless($row, 401, 'Session ticket expired or previously claimed.');
        $user = User::find($row->user_id);
        abort_unless($user && $user->is_active, 403);
        $updated = DB::table('voicechat_sessions')->where('id', $row->id)
            ->where('status', 'issued')->where('expires_at', '>=', now())
            ->update(['status' => 'claimed', 'claimed_at' => now(), 'updated_at' => now()]);
        abort_unless($updated === 1, 401, 'Session ticket already used.');

        return response()->json(['id' => $row->id, 'model' => 'nvidia-nemotron-voicechat',
            'max_seconds' => (int) config('voicechat.max_session_seconds', 900)])
            ->header('Cache-Control', 'no-store');
    }

    public function finish(Request $request, ApiCreditUsage $credits)
    {
        $this->worker($request);
        $data = $request->validate([
            'id' => 'required|uuid',
            'produced_audio' => 'required|boolean',
        ]);
        $row = DB::table('voicechat_sessions')->where('id', $data['id'])->first();
        abort_unless($row, 404);
        $model = DB::table('hosted_code_agents')->where('slug', 'nvidia-nemotron-voicechat')->first();
        abort_unless($model, 503);
        DB::transaction(function () use ($row, $data, $credits, $model) {
            $changed = DB::table('voicechat_sessions')->where('id', $row->id)
                ->where('status', 'claimed')->update([
                    'status' => 'closed', 'produced_audio' => (bool) $data['produced_audio'],
                    'ended_at' => now(), 'updated_at' => now(),
                ]);
            if ($changed === 1 && $data['produced_audio']) {
                $connection = $credits->connection('nvidia', $model->provider_model, 'audio');
                $credits->completed($row->user_id, $connection, 'voicechat:'.$row->id);
            }
        });

        return response()->json(['id' => $row->id, 'status' => 'closed'])
            ->header('Cache-Control', 'no-store');
    }
}
