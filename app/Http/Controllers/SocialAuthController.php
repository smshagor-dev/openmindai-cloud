<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Notifications\EmailOtpCode;
use App\Services\AdminSettings;
use App\Services\SocialProvider;
use App\Services\TwoFactor;
use App\Services\UnfamiliarLogin;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SocialAuthController
{
    private const DEVICE = 'Openmindai Web';

    private function authorize(Request $request, string $provider): void
    {
        $key = (string) config('mobile_gateway.bridge_key');
        abort_unless(strlen($key) >= 32 && hash_equals($key, (string) $request->header('X-Chat-Bridge-Key')), 403);
        abort_unless(in_array($provider, SocialProvider::enabled(), true), 404, 'This sign-in provider is disabled.');
    }

    public function start(Request $request, string $provider, SocialProvider $social)
    {
        $this->authorize($request, $provider);
        $data = $request->validate([
            'state' => ['required', 'regex:/^[a-f0-9]{64}$/D'],
            'challenge' => ['required', 'regex:/^[a-zA-Z0-9_-]{43}$/D'],
        ]);

        return ['url' => $social->authorizationUrl($provider, $data)];
    }

    public function exchange(Request $request, string $provider, SocialProvider $social)
    {
        $this->authorize($request, $provider);
        $data = $request->validate([
            'code' => 'required|string|max:4096',
            'verifier' => ['required', 'regex:/^[a-f0-9]{64}$/D'],
            'link' => 'required|boolean',
        ]);
        $profile = $social->profile($provider, $data['code'], $data['verifier']);
        $identity = DB::table('social_identities')->where('provider', $provider)->where('subject_hash', $profile['subject_hash']);
        $userId = $identity->value('user_id');

        if ($data['link']) {
            $user = $request->user('sanctum');
            abort_unless($user?->is_active && $user->email_verified_at && $user->tokenCan('user'), 403, 'Sign in to link this provider.');
            $this->link($user, $provider, $profile['subject_hash']);

            return ['linked' => true];
        }

        if ($userId) {
            return $this->signIn($request, User::findOrFail($userId), $provider);
        }

        abort_unless($profile['email'], 422, 'The provider did not share an email address.');
        abort_if(User::whereRaw('LOWER(email) = ?', [$profile['email']])->exists(), 409, 'Sign in to your existing OpenMindAI account, then link this provider in Settings → Security.');
        $settings = app(AdminSettings::class);
        abort_unless($settings->enabled('registration_enabled'), 403, 'Registration is currently closed.');
        if ($profile['email_verified'] && ! $settings->enabled('registration_email_verification_enabled')) {
            return $this->signIn($request, $this->create($provider, $profile), $provider);
        }

        $limit = 'social-email:'.hash('sha256', $profile['email']);
        abort_if(RateLimiter::tooManyAttempts($limit, 3), 429, 'Too many email verification requests. Try again later.');
        RateLimiter::hit($limit, 3600);
        $code = (string) random_int(100000, 999999);
        try {
            Notification::route('mail', $profile['email'])->notify(new EmailOtpCode($code, 'registration'));
        } catch (\Throwable) {
            abort(503, 'Could not send the verification email. Try again later.');
        }

        return $this->pending($provider, 'email', ['profile' => $profile, 'hash' => Hash::make($code)]);
    }

    public function complete(Request $request, string $provider)
    {
        $this->authorize($request, $provider);
        $data = $request->validate([
            'ticket' => ['required', 'regex:/^[a-f0-9]{64}$/D'],
            'code' => 'required|string|max:64',
        ]);
        $key = 'social-login:'.hash('sha256', $data['ticket']);

        return Cache::lock($key.':lock', 30)->block(3, function () use ($request, $provider, $data, $key) {
            $pending = Cache::get($key);
            abort_unless($pending && $pending['provider'] === $provider && $pending['expires'] > time(), 422, 'Sign-in expired. Start again.');
            if ($pending['step'] === 'ip') {
                $request->merge(['challenge_id' => $pending['challenge']]);
                $response = app(UnfamiliarLogin::class)->verify($request);
                Cache::forget($key);

                return $response;
            }
            if ($pending['step'] === 'email') {
                if (! Hash::check($data['code'], $pending['hash'])) {
                    $this->rejectCode($key, $pending);
                }
                Cache::forget($key);

                return $this->signIn($request, $this->create($provider, $pending['profile']), $provider);
            }
            $user = User::findOrFail($pending['user_id']);
            abort_unless(hash_equals($pending['password'], hash('sha256', $user->password)), 422, 'Sign-in expired. Start again.');
            try {
                $response = app(AuthController::class)->signIn($request, $user, self::DEVICE, $data['code'], app(TwoFactor::class), app(UnfamiliarLogin::class));
            } catch (HttpException $exception) {
                if ($exception->getStatusCode() === 422 && str_contains($exception->getMessage(), 'two-factor')) {
                    $this->rejectCode($key, $pending);
                }

                throw $exception;
            }
            Cache::forget($key);

            return $this->formatSignIn($provider, $response);
        });
    }

    private function signIn(Request $request, User $user, string $provider)
    {
        abort_unless($user->is_active && $user->email_verified_at, 403, 'Account unavailable.');
        if ($user->two_factor_confirmed_at) {
            return $this->pending($provider, 'two-factor', ['user_id' => $user->id, 'password' => hash('sha256', $user->password)]);
        }

        return $this->formatSignIn($provider, app(AuthController::class)->signIn($request, $user, self::DEVICE, '', app(TwoFactor::class), app(UnfamiliarLogin::class)));
    }

    private function formatSignIn(string $provider, mixed $response)
    {
        if ($response instanceof JsonResponse && $response->getStatusCode() === 202) {
            return $this->pending($provider, 'ip', ['challenge' => $response->getData(true)['challenge_id']]);
        }

        return $response;
    }

    private function pending(string $provider, string $step, array $data)
    {
        $ticket = bin2hex(random_bytes(32));
        Cache::put('social-login:'.hash('sha256', $ticket), $data + [
            'provider' => $provider, 'step' => $step, 'attempts' => 0, 'expires' => time() + 600,
        ], 600);

        return response()->json(['ticket' => $ticket, 'step' => $step], 202);
    }

    private function rejectCode(string $key, array $pending): never
    {
        if (++$pending['attempts'] >= 5) {
            Cache::forget($key);
        } else {
            Cache::put($key, $pending, max(1, $pending['expires'] - time()));
        }

        throw ValidationException::withMessages(['code' => 'Invalid or expired verification code.']);
    }

    private function create(string $provider, array $profile): User
    {
        abort_unless(app(AdminSettings::class)->enabled('registration_enabled'), 403, 'Registration is currently closed.');

        return DB::transaction(function () use ($provider, $profile) {
            abort_if(User::whereRaw('LOWER(email) = ?', [$profile['email']])->exists(), 409, 'Sign in to your existing account and link this provider.');
            try {
                $user = User::create(['name' => $profile['name'], 'email' => $profile['email'], 'password' => Str::random(64)]);
                $user->forceFill(['email_verified_at' => now()])->save();
                $this->link($user, $provider, $profile['subject_hash']);

                return $user;
            } catch (UniqueConstraintViolationException) {
                abort(409, 'This account is already registered. Sign in to link the provider.');
            }
        });
    }

    private function link(User $user, string $provider, string $subject): void
    {
        try {
            DB::table('social_identities')->insert([
                'user_id' => $user->id, 'provider' => $provider, 'subject_hash' => $subject,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            abort_unless(DB::table('social_identities')->where([
                'user_id' => $user->id, 'provider' => $provider, 'subject_hash' => $subject,
            ])->exists(), 409, 'This provider is already linked to another account or identity.');
        }
    }
}
