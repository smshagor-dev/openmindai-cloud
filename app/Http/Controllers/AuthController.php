<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AdminSettings;
use App\Services\EmailOtp;
use App\Services\TwoFactor;
use App\Services\UnfamiliarLogin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

class AuthController
{
    public function register(Request $r, UnfamiliarLogin $login, EmailOtp $otp)
    {
        $settings = app(AdminSettings::class);
        abort_unless($settings->enabled('registration_enabled'), 403, 'Registration is currently closed.');
        $data = $r->validate([
            'name' => 'required|string|max:120',
            'email' => 'required|email|max:254|unique:users',
            'password' => ['required', 'confirmed', PasswordRule::min(6)],
        ]);
        $u = User::create($data);

        if ($settings->enabled('registration_email_verification_enabled')) {
            try {
                $otp->send($u, 'registration');
            } catch (\Throwable $exception) {
                // Never leave an inaccessible new account if the first verification email cannot be sent.
                $u->delete();
                abort(503, 'Verification email could not be sent. Please try again later.');
            }

            return response()->json([
                'verification_required' => true,
                'message' => 'Enter the six-digit code sent to your email.',
            ], 201);
        }

        $u->forceFill(['email_verified_at' => now()])->save();
        $token = $u->createToken('Openmindai Web', ['user'], now()->addDays(30))->plainTextToken;
        if ($settings->enabled('unusual_ip_verification_enabled')) {
            $login->trust($u, (string) $r->ip());
        }

        return response()->json([
            'user' => $u,
            'token' => $token,
            'token_type' => 'Bearer',
            'message' => 'Account created successfully.',
        ], 201);
    }

    public function login(Request $r, TwoFactor $two, UnfamiliarLogin $login)
    {
        $d = $r->validate([
            'email' => 'required|email', 'password' => 'required|string|max:1024',
            'code' => 'nullable|string|max:64', 'device_name' => 'required|string|max:80',
        ]);
        $u = User::where('email', $d['email'])->first();
        abort_unless($u && Hash::check($d['password'], $u->password), 422, 'Invalid credentials.');
        abort_unless($u->is_active, 403, 'Account disabled.');
        abort_unless($u->email_verified_at, 403, 'Verify your email first.');

        if ($u->two_factor_confirmed_at) {
            abort_unless($two->verify($u, $d['code'] ?? ''), 422, 'A valid two-factor or recovery code is required.');
        }
        abort_if($u->tokens()->where('name', 'not like', 'developer:%')->count() >= 10, 422, 'Revoke an existing device first.');

        $challenge = $login->challenge($u, $r, $d['device_name']);
        if ($challenge) {
            return response()->json($challenge, 202);
        }

        $token = $u->createToken($d['device_name'], ['user'], now()->addDays(30))->plainTextToken;
        if (app(AdminSettings::class)->enabled('unusual_ip_verification_enabled')) {
            $login->trust($u, (string) $r->ip());
        }

        return ['token' => $token, 'token_type' => 'Bearer', 'user' => $u];
    }

    public function verifyLoginIp(Request $request, UnfamiliarLogin $login)
    {
        return $login->verify($request);
    }

    public function logout(Request $r)
    {
        $r->user()->currentAccessToken()?->delete();

        return response()->noContent();
    }

    public function forgot(Request $r, EmailOtp $otp)
    {
        $data = $r->validate(['email' => 'required|email']);
        $user = User::where('email', $data['email'])->first();
        if ($user && $user->is_active) {
            try {
                $otp->send($user, 'password');
            } catch (ValidationException $e) {
                throw $e;
            } catch (\Throwable $e) {
                abort(503, 'Could not send the reset code. Please try again later.');
            }
        }

        return ['message' => 'If the account exists, a six-digit reset code has been sent.'];
    }

    public function reset(Request $r, EmailOtp $otp)
    {
        $data = $r->validate([
            'email' => 'required|email',
            'code' => 'required|digits:6',
            'password' => ['required', 'confirmed', PasswordRule::min(6)],
        ]);
        $ok = $otp->consume($data['email'], 'password', $data['code'], function () use ($data) {
            $user = User::where('email', $data['email'])->firstOrFail();
            $user->forceFill([
                'password' => $data['password'],
                'pending_email' => null,
                'email_change_token' => null,
                'email_change_expires_at' => null,
                'remember_token' => bin2hex(random_bytes(30)),
            ])->save();
            $user->tokens()->delete();
            DB::table('sessions')->where('user_id', $user->id)->delete();
        });
        abort_unless($ok, 422, 'Invalid or expired reset code.');

        return ['message' => 'Password reset. All devices signed out.'];
    }

    public function verifyCode(Request $r, EmailOtp $otp)
    {
        $data = $r->validate(['email' => 'required|email', 'code' => 'required|digits:6']);
        $ok = $otp->consume($data['email'], 'registration', $data['code'], function () use ($data) {
            $user = User::where('email', $data['email'])->whereNull('email_verified_at')->firstOrFail();
            $user->forceFill(['email_verified_at' => now()])->save();
        });
        abort_unless($ok, 422, 'Invalid or expired verification code.');

        return ['message' => 'Email verified. You can now sign in.'];
    }

    public function resend(Request $r, EmailOtp $otp)
    {
        $r->validate(['email' => 'required|email']);
        $user = User::where('email', $r->email)->whereNull('email_verified_at')->first();
        if ($user) {
            try {
                $otp->send($user, 'registration', true);
            } catch (ValidationException $e) {
                throw $e;
            } catch (\Throwable $e) {
                abort(503, 'Could not send the verification code. Please try again later.');
            }
        }

        return ['message' => 'If eligible, a six-digit verification code has been sent.'];
    }
}
