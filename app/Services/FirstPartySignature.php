<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

class FirstPartySignature
{
    public function user(Request $request): User
    {
        $appId = (string) $request->header('X-OpenMindAI-App', '');
        $timestamp = (string) $request->header('X-OpenMindAI-Timestamp', '');
        $nonce = (string) $request->header('X-OpenMindAI-Nonce', '');
        $signature = (string) $request->header('X-OpenMindAI-Signature', '');

        abort_unless(preg_match('/^[a-z0-9_-]{3,64}$/D', $appId) &&
            preg_match('/^[0-9]{10}$/D', $timestamp) &&
            abs(time() - (int) $timestamp) <= 300 &&
            preg_match('/^[a-f0-9]{32,64}$/D', $nonce) &&
            preg_match('/^[a-f0-9]{64}$/D', $signature), 401, 'Invalid app authorization.');

        $app = DB::table('first_party_apps')->where('app_id', $appId)->where('enabled', true)->first();
        abort_unless($app, 401, 'Unknown app.');
        $secret = Crypt::decryptString($app->encrypted_secret);
        $message = implode("\n", [
            $timestamp, $nonce, strtoupper($request->method()),
            '/'.$request->path(), hash('sha256', in_array(strtoupper($request->method()), ['GET', 'HEAD'], true) ? '' : $request->getContent()),
        ]);
        abort_unless(hash_equals(hash_hmac('sha256', $message, $secret), $signature), 401, 'Invalid app signature.');
        // Fail closed if replay storage is unavailable.
        abort_unless(Cache::add('first-party:nonce:'.$app->id.':'.$nonce, true, 600), 409, 'Request already used.');
        $limitKey = 'first-party:rate:'.$app->id;
        abort_if(RateLimiter::tooManyAttempts($limitKey, 60), 429, 'App request limit reached.');
        RateLimiter::hit($limitKey, 60);
        $user = User::whereKey($app->owner_user_id)->where('is_active', true)->first();
        abort_unless($user, 403, 'App owner is inactive.');
        DB::table('first_party_apps')->where('id', $app->id)->update(['last_used_at' => now()]);

        return $user;
    }
}
