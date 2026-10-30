<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifiedMobileGatewayIp
{
    /**
     * Only a server-signed IP can influence Laravel login throttling and
     * unfamiliar-network OTP checks. Unsigned web/direct requests behave
     * exactly as before.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $ip = (string) $request->header('X-OpenMind-Mobile-IP', '');
        $time = (string) $request->header('X-OpenMind-Mobile-Time', '');
        $signature = (string) $request->header('X-OpenMind-Mobile-Signature', '');

        if ($ip === '' && $time === '' && $signature === '') {
            return $next($request);
        }
        abort_unless(
            $ip !== '' && $time !== '' && $signature !== '' &&
            filter_var($ip, FILTER_VALIDATE_IP) &&
            ctype_digit($time) && strlen($time) <= 12 &&
            abs(time() - (int) $time) <= 60 &&
            preg_match('/^[a-f0-9]{64}$/D', $signature) === 1,
            403,
            'Invalid mobile gateway identification.'
        );

        $key = (string) config('mobile_gateway.bridge_key', '');
        abort_if(strlen($key) < 32, 503, 'Mobile gateway identity is not configured.');

        $message = implode("\n", [
            $time,
            $ip,
            strtoupper($request->method()),
            '/'.$request->path(),
            hash('sha256', $request->getContent()),
        ]);
        abort_unless(
            hash_equals(hash_hmac('sha256', $message, $key), $signature),
            403,
            'Invalid mobile gateway identification.'
        );

        // The proxy's signed client IP now represents the actual requester.
        // Drop all untrusted forwarded-IP headers before the auth limiter runs.
        $request->headers->remove('x-forwarded-for');
        $request->headers->remove('x-vercel-forwarded-for');
        $request->headers->remove('x-real-ip');
        $request->headers->remove('forwarded');
        $request->server->set('REMOTE_ADDR', $ip);
        $request->attributes->set('mobile_gateway_verified', true);

        return $next($request);
    }
}
