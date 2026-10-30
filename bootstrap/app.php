<?php

use App\Http\Middleware\ActiveUser;
use App\Http\Middleware\AdminOnly;
use App\Http\Middleware\InteractiveSessionToken;
use App\Http\Middleware\VerifiedMobileGatewayIp;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php', api: __DIR__.'/../routes/api.php', commands: __DIR__.'/../routes/console.php', health: '/up')
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(headers: Request::HEADER_X_FORWARDED_FOR);
        $middleware->alias(['active' => ActiveUser::class, 'admin' => AdminOnly::class, 'interactive-token' => InteractiveSessionToken::class, 'verified-mobile-ip' => VerifiedMobileGatewayIp::class]);
        $middleware->redirectGuestsTo('/admin/login');
    })->withExceptions(function (Exceptions $exceptions): void {
        // API consumers must receive machine-readable errors even when the
        // caller sends */* or omits Accept (for example a CLI or desktop IDE).
        // Admin pages continue to use the existing sign-in redirects.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson()
        );
    })->create();
