<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\PersonalAccessToken;

class MobileSocialSessionController
{
    public function approve(Request $request)
    {
        $key = (string) config('mobile_gateway.bridge_key');
        abort_unless(strlen($key) >= 32 && hash_equals($key, (string) $request->header('X-Chat-Bridge-Key')), 403);
        $data = $request->validate(['challenge' => ['required', 'regex:/^[a-f0-9]{64}$/D']]);
        $token = $request->user()->currentAccessToken();
        abort_unless($token instanceof PersonalAccessToken && $token->can('user'), 403);
        $cacheKey = 'mobile-social:'.$data['challenge'];

        Cache::lock($cacheKey.':lock', 10)->block(3, function () use ($cacheKey, $token) {
            $existing = Cache::get($cacheKey);
            abort_if($existing !== null && $existing !== $token->id, 409);
            Cache::add($cacheKey, $token->id, now()->addMinutes(5));
        });

        return response()->noContent()->header('Cache-Control', 'no-store');
    }

    public function exchange(Request $request)
    {
        $data = $request->validate(['verifier' => ['required', 'regex:/^[a-f0-9]{64}$/D']]);
        $cacheKey = 'mobile-social:'.hash('sha256', $data['verifier']);

        return Cache::lock($cacheKey.':lock', 10)->block(3, function () use ($cacheKey) {
            $id = Cache::get($cacheKey);
            if ($id === null) {
                return response()->json(['pending' => true], 202)->header('Cache-Control', 'no-store');
            }
            $source = is_int($id) ? PersonalAccessToken::find($id) : null;
            $user = $source?->tokenable;
            abort_unless($source && $source->can('user') && $user instanceof User &&
                $user->is_active && $user->email_verified_at && ! $source->expires_at?->isPast(), 403);
            Cache::put($cacheKey, 'redeemed', now()->addMinutes(5));
            $token = $user->createToken('OpenMindAI Mobile', ['user'], now()->addDays(30))->plainTextToken;

            return response()->json(['token' => $token, 'user' => $user])->header('Cache-Control', 'no-store');
        });
    }
}
