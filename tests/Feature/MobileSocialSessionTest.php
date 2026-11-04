<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MobileSocialSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_browser_approval_can_be_redeemed_only_once_by_the_verifier_holder(): void
    {
        $secret = str_repeat('x', 48);
        config(['mobile_gateway.bridge_key' => $secret]);
        $user = User::create([
            'name' => 'Mobile User',
            'email' => 'mobile@example.test',
            'password' => 'StrongPassword123',
        ]);
        $user->forceFill(['email_verified_at' => now(), 'avatar' => 'data:image/png;base64,AAEC'])->save();
        $webToken = $user->createToken('Web', ['user'], now()->addHour())->plainTextToken;
        $verifier = bin2hex(random_bytes(32));
        $challenge = hash('sha256', $verifier);
        $approve = '/api/v1/mobile/social/approve';
        $exchange = '/api/v1/mobile/social/exchange';

        $this->postJson($exchange, ['verifier' => $verifier])->assertAccepted()->assertJson(['pending' => true]);
        $this->postJson($approve, ['challenge' => $challenge], ['Authorization' => 'Bearer '.$webToken])->assertForbidden();
        $this->postJson($approve, ['challenge' => $challenge], [
            'Authorization' => 'Bearer '.$webToken,
            'X-Chat-Bridge-Key' => $secret,
        ])->assertNoContent();
        $this->postJson($exchange, ['verifier' => $challenge])->assertAccepted();

        $token = $this->postJson($exchange, ['verifier' => $verifier])
            ->assertOk()
            ->assertJsonPath('user.avatar', $user->avatar)
            ->json('token');
        $this->postJson($exchange, ['verifier' => $verifier])->assertForbidden();
        $this->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$token])
            ->assertOk()->assertJsonPath('id', $user->id);
    }
}
