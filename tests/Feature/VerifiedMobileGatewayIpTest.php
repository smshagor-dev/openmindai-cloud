<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifiedMobileGatewayIp;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class VerifiedMobileGatewayIpTest extends TestCase
{
    private const KEY = '0123456789abcdef0123456789abcdef';

    private function requestWithIdentity(
        string $ip = '203.0.113.51',
        string $timestamp = '',
        string $body = '{"email":"test@example.com"}'
    ): Request {
        $timestamp = $timestamp !== '' ? $timestamp : (string) time();
        $request = Request::create(
            '/api/v1/login',
            'POST',
            [],
            [],
            [],
            [
                'REMOTE_ADDR' => '198.51.100.10',
                'HTTP_X_FORWARDED_FOR' => '192.0.2.1',
                'CONTENT_TYPE' => 'application/json',
            ],
            $body
        );
        $message = implode("\n", [
            $timestamp,
            $ip,
            'POST',
            '/api/v1/login',
            hash('sha256', $body),
        ]);
        $request->headers->set('X-OpenMind-Mobile-IP', $ip);
        $request->headers->set('X-OpenMind-Mobile-Time', $timestamp);
        $request->headers->set(
            'X-OpenMind-Mobile-Signature',
            hash_hmac('sha256', $message, self::KEY)
        );

        return $request;
    }

    public function test_signed_gateway_identity_restores_real_user_ip(): void
    {
        config()->set('mobile_gateway.bridge_key', self::KEY);
        $request = $this->requestWithIdentity();
        $reply = (new VerifiedMobileGatewayIp)->handle(
            $request,
            fn ($r) => response()->json([
                'ip' => $r->ip(),
                'forwarded' => $r->header('x-forwarded-for'),
                'verified' => $r->attributes->get('mobile_gateway_verified'),
            ])
        );

        $payload = json_decode($reply->getContent(), true);
        $this->assertSame('203.0.113.51', $payload['ip']);
        $this->assertNull($payload['forwarded']);
        $this->assertTrue($payload['verified']);
    }

    public function test_unsigned_website_requests_remain_compatible(): void
    {
        config()->set('mobile_gateway.bridge_key', self::KEY);
        $request = Request::create('/api/v1/public-config');
        $reply = (new VerifiedMobileGatewayIp)->handle(
            $request,
            fn () => response()->json(['untouched' => true])
        );
        $this->assertSame(200, $reply->status());
        $this->assertNull($request->attributes->get('mobile_gateway_verified'));
    }

    public function test_invalid_signature_cannot_spoof_another_user_ip(): void
    {
        config()->set('mobile_gateway.bridge_key', self::KEY);
        $request = $this->requestWithIdentity();
        $request->headers->set('X-OpenMind-Mobile-IP', '192.0.2.99');
        $this->expectException(HttpException::class);
        (new VerifiedMobileGatewayIp)->handle($request, fn () => response('unreachable'));
    }

    public function test_outdated_signatures_are_rejected(): void
    {
        config()->set('mobile_gateway.bridge_key', self::KEY);
        $request = $this->requestWithIdentity(timestamp: (string) (time() - 300));
        $this->expectException(HttpException::class);
        (new VerifiedMobileGatewayIp)->handle($request, fn () => response('unreachable'));
    }

    public function test_missing_one_gateway_header_fails_closed(): void
    {
        config()->set('mobile_gateway.bridge_key', self::KEY);
        $request = $this->requestWithIdentity();
        $request->headers->remove('X-OpenMind-Mobile-Signature');
        $this->expectException(HttpException::class);
        (new VerifiedMobileGatewayIp)->handle($request, fn () => response('unreachable'));
    }

    public function test_unconfigured_bridge_key_fails_closed(): void
    {
        config()->set('mobile_gateway.bridge_key', '');
        $request = $this->requestWithIdentity();
        $this->expectException(HttpException::class);
        (new VerifiedMobileGatewayIp)->handle($request, fn () => response('unreachable'));
    }
}
