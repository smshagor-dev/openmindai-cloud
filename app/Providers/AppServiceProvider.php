<?php

namespace App\Providers;

use App\Services\AdminSettings;
use App\Services\SmtpSettings;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->beforeResolving('mail.manager', function () {
            app(SmtpSettings::class)->apply();
        });
    }

    public function boot(): void
    {
        RateLimiter::for('auth', fn ($r) => [Limit::perMinute(5)->by('auth:'.$r->ip()), Limit::perHour(20)->by('email:'.hash('sha256', strtolower((string) $r->input('email'))))]);
        RateLimiter::for('auth-otp', fn ($r) => [
            Limit::perMinute(10)->by('otp-ip:'.$r->ip()),
            Limit::perHour(20)->by('otp-email:'.hash('sha256', strtolower((string) $r->input('email')))),
        ]);
        RateLimiter::for('hub', fn ($r) => Limit::perMinute(10)->by('hub:'.$r->user()->id));
        RateLimiter::for('api', fn ($r) => Limit::perMinute(60)->by($r->user()?->id ?: $r->ip()));
        RateLimiter::for('research-events', fn ($r) => Limit::perMinute(30)->by('research-events:'.$r->user()->id));
        RateLimiter::for('sensitive', fn ($r) => Limit::perMinute(5)->by('sensitive:'.$r->user()->id));
        RateLimiter::for('media', fn ($r) => Limit::perMinute(20)->by('media:'.$r->user()->id));
        // Completion/rejection callbacks belong to a previously approved
        // media task. They should not exhaust the five-per-minute limiter
        // shared with key creation and account-security changes.
        RateLimiter::for('media-callback', fn ($r) => [
            Limit::perMinute(40)->by('media-callback-user:'.$r->user()->id),
            Limit::perMinute(10)->by('media-callback-task:'.$r->user()->id.':'.$r->route('id')),
        ]);
        RateLimiter::for('chat', function ($r) {
            $limits = [Limit::perMinute(10)->by('chat:'.$r->user()->id)];
            $daily = app(AdminSettings::class)->limit('chat_daily_limit');
            if ($daily > 0) {
                $limits[] = Limit::perDay($daily)->by('chat-day:'.$r->user()->id);
            }

            return $limits;
        });
        ResetPassword::createUrlUsing(fn ($user, $token) => rtrim(config('app.frontend_url') ?: config('app.url'), '/').'/reset-password?'.http_build_query(['token' => $token, 'email' => $user->email]));
    }
}
