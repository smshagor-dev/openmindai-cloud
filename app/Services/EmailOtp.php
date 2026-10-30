<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\EmailOtpCode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class EmailOtp
{
    public function send(User $user, string $purpose, bool $resend = false): void
    {
        $email = mb_strtolower(trim($user->email));
        $limitKey = 'otp:resend:'.hash('sha256', $email.'|'.$purpose);
        if ($resend && RateLimiter::tooManyAttempts($limitKey, 5)) {
            $wait = RateLimiter::availableIn($limitKey);
            throw ValidationException::withMessages(['email' => 'You have reached the limit of 5 resend requests per hour. Try again in '.max(1, (int) ceil($wait / 60)).' minute(s).']);
        }
        $existing = DB::table('email_otp_challenges')->where('email', $email)->where('purpose', $purpose)->first();
        if ($existing && Carbon::parse($existing->updated_at)->greaterThan(now()->subSeconds(120))) {
            throw ValidationException::withMessages(['email' => 'Wait 2 minutes before requesting another code.']);
        }
        $code = (string) random_int(100000, 999999);
        DB::table('email_otp_challenges')->updateOrInsert(
            ['email' => $email, 'purpose' => $purpose],
            ['code_hash' => Hash::make($code), 'attempts' => 0, 'expires_at' => now()->addMinutes(10), 'created_at' => now(), 'updated_at' => now()]
        );
        try {
            $user->notify(new EmailOtpCode($code, $purpose));
            if ($resend) {
                RateLimiter::hit($limitKey, 3600);
            }
        } catch (\Throwable $exception) {
            DB::table('email_otp_challenges')->where('email', $email)->where('purpose', $purpose)->delete();
            throw $exception;
        }
    }

    public function consume(string $email, string $purpose, string $code, callable $action): bool
    {
        return DB::transaction(function () use ($email, $purpose, $code, $action) {
            $query = DB::table('email_otp_challenges')
                ->where('email', mb_strtolower(trim($email)))->where('purpose', $purpose);
            $challenge = (clone $query)->lockForUpdate()->first();
            if (! $challenge || now()->greaterThan($challenge->expires_at)) {
                return false;
            }
            if (! Hash::check($code, $challenge->code_hash)) {
                if ($challenge->attempts >= 4) {
                    (clone $query)->delete();
                } else {
                    (clone $query)->update(['attempts' => $challenge->attempts + 1]);
                }

                return false;
            }
            $action();
            (clone $query)->delete();

            return true;
        });
    }
}
