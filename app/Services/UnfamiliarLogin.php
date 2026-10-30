<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\LoginIpVerification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UnfamiliarLogin
{
    private function ipHash(string $ip): string
    {
        $packed = @inet_pton($ip);
        abort_if($packed === false, 403, 'Login network could not be verified.');

        // Group IPv6 temporary addresses on the same /64 network.
        if (strlen($packed) === 16) {
            $packed = substr($packed, 0, 8);
        }

        return hash_hmac('sha256', $packed, (string) config('app.key'));
    }

    public function trust(User $user, string $ip): void
    {
        DB::table('trusted_login_ips')->upsert([[
            'user_id' => $user->id,
            'ip_hash' => $this->ipHash($ip),
            'created_at' => now(),
            'updated_at' => now(),
        ]], ['user_id', 'ip_hash'], ['updated_at']);
    }

    public function challenge(User $user, Request $request, string $device): ?array
    {
        if (! app(AdminSettings::class)->enabled('unusual_ip_verification_enabled')) {
            return null;
        }

        $fingerprint = $this->ipHash((string) $request->ip());
        $known = DB::table('trusted_login_ips')->where('user_id', $user->id);

        // With no previous IP baseline the first successful sign-in establishes trust.
        if (! (clone $known)->exists() || (clone $known)->where('ip_hash', $fingerprint)->exists()) {
            return null;
        }

        $recent = DB::table('login_ip_challenges')->where('user_id', $user->id)
            ->where('created_at', '>=', now()->subHour())->count();
        abort_if($recent >= 5, 429, 'Too many verification requests. Try again later.');

        $id = (string) Str::uuid();
        $code = (string) random_int(100000, 999999);
        DB::table('login_ip_challenges')->insert([
            'id' => $id, 'user_id' => $user->id, 'ip_hash' => $fingerprint,
            'password_fingerprint' => hash('sha256', $user->password),
            'email_fingerprint' => hash('sha256', mb_strtolower($user->email)),
            'device_name' => $device, 'code_hash' => Hash::make($code),
            'attempts' => 0, 'expires_at' => now()->addMinutes(10),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        try {
            $user->notify(new LoginIpVerification($code));
        } catch (\Throwable $e) {
            DB::table('login_ip_challenges')->where('id', $id)->delete();
            abort(503, 'Could not send a security email. Try again later.');
        }

        return [
            'verification_required' => true,
            'challenge_id' => $id,
            'message' => 'A security code was sent to your verified email address.',
        ];
    }

    public function verify(Request $request): array
    {
        $data = $request->validate(['challenge_id' => ['required', 'uuid'], 'code' => ['required', 'digits:6']]);
        $fingerprint = $this->ipHash((string) $request->ip());

        // Lock and consume a challenge exactly once, even across concurrent requests.
        $result = DB::transaction(function () use ($data, $fingerprint, $request) {
            $row = DB::table('login_ip_challenges')->where('id', $data['challenge_id'])->lockForUpdate()->first();
            if (! $row || ! hash_equals($row->ip_hash, $fingerprint) || now()->greaterThan($row->expires_at)) {
                return null;
            }

            $user = User::find($row->user_id);
            if (! $user || ! $user->is_active || ! $user->email_verified_at ||
                ! hash_equals($row->password_fingerprint, hash('sha256', $user->password)) ||
                ! $row->email_fingerprint ||
                ! hash_equals($row->email_fingerprint, hash('sha256', mb_strtolower($user->email)))) {
                DB::table('login_ip_challenges')->where('id', $row->id)->delete();

                return null;
            }

            if (! Hash::check($data['code'], $row->code_hash)) {
                if ($row->attempts >= 4) {
                    DB::table('login_ip_challenges')->where('id', $row->id)->delete();
                } else {
                    DB::table('login_ip_challenges')->where('id', $row->id)
                        ->update(['attempts' => $row->attempts + 1, 'updated_at' => now()]);
                }

                return null;
            }

            if ($user->tokens()->where('name', 'not like', 'developer:%')->count() >= 10) {
                return ['device_limit' => true];
            }

            DB::table('login_ip_challenges')->where('id', $row->id)->delete();
            $token = $user->createToken($row->device_name, ['user'], now()->addDays(30))->plainTextToken;
            $this->trust($user, (string) $request->ip());

            return ['token' => $token, 'token_type' => 'Bearer', 'user' => $user];
        });

        abort_if($result === null, 422, 'Invalid or expired security code.');
        abort_if(isset($result['device_limit']), 422, 'Revoke an existing device first.');

        return $result;
    }
}
