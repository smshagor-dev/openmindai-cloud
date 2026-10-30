<?php

namespace App\Http\Controllers;

use App\Services\TwoFactor;
use Illuminate\Http\Request;

class AdminDeveloperKeysController
{
    public function store(Request $request, TwoFactor $two)
    {
        $data = $request->validate([
            'name' => 'required|string|min:3|max:64',
            'current_password' => 'required|current_password',
            'code' => 'nullable|string|max:64',
        ]);

        $user = $request->user();
        if ($user->two_factor_confirmed_at) {
            abort_unless(
                $two->verify($user, (string) ($data['code'] ?? '')),
                422,
                'A valid two-factor code is required.'
            );
        }

        // The same per-user cap, Sanctum abilities and expiry as the Account
        // → Developer API implementation; no global app secret is involved.
        abort_if(
            $user->tokens()->where('name', 'like', 'developer:%')
                ->where(function ($query) {
                    $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
                })->count() >= 10,
            422,
            'Maximum 10 active Developer API keys.'
        );

        $label = 'developer:'.trim($data['name']);
        $token = $user->createToken($label, ['openmindai:invoke'], now()->addDays(90));

        return response()->json([
            'id' => $token->accessToken->id,
            'name' => $label,
            'api_key' => $token->plainTextToken,
            'expires_at' => $token->accessToken->expires_at,
            'base_url' => config('developer.public_base_url'),
            'note' => 'This Developer API Key is displayed only once. Store it in OpenMind Studio secure settings.',
        ], 201)
            ->header('Cache-Control', 'no-store, private')
            ->header('Pragma', 'no-cache')
            ->header('X-Content-Type-Options', 'nosniff')
            ->header('Referrer-Policy', 'no-referrer');
    }

    public function destroy(Request $request, int $key, TwoFactor $two)
    {
        $request->validate([
            'current_password' => 'required|current_password',
            'code' => 'nullable|string|max:64',
        ]);
        if ($request->user()->two_factor_confirmed_at) {
            abort_unless(
                $two->verify($request->user(), (string) $request->input('code', '')),
                422,
                'A valid two-factor code is required.'
            );
        }

        // Only the signed-in administrator's own developer tokens can be removed.
        $request->user()->tokens()->where('name', 'like', 'developer:%')
            ->findOrFail($key)->delete();

        return back()->with('status', 'Developer API Key revoked. OpenMind Studio connections using it will stop working.');
    }
}
