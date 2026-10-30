<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\EmailOtpCode;
use App\Notifications\LoginIpVerification;
use App\Services\AdminSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AuthenticationSecuritySettingsTest extends TestCase
{
    use RefreshDatabase;

    private function payload(string $email = 'person@example.com'): array
    {
        return [
            'email' => $email,
            'password' => 'StrongPassword123',
            'device_name' => 'Test browser',
        ];
    }

    private function user(): User
    {
        $user = User::create([
            'name' => 'Member',
            'email' => 'person@example.com',
            'password' => 'StrongPassword123',
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user;
    }

    public function test_verification_policy_blocks_login_and_does_not_issue_a_registration_token(): void
    {
        app(AdminSettings::class)->save(['registration_email_verification_enabled' => '1']);
        Notification::fake();
        $this->postJson('/api/v1/register', [
            'name' => 'Member',
            'email' => 'person@example.com',
            'password' => 'StrongPassword123',
            'password_confirmation' => 'StrongPassword123',
        ])->assertCreated()->assertJsonPath('verification_required', true)
            ->assertJsonMissingPath('token');

        $user = User::where('email', 'person@example.com')->firstOrFail();
        $this->assertNull($user->email_verified_at);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        Notification::assertSentTo($user, EmailOtpCode::class);

        $this->postJson('/api/v1/login', $this->payload())->assertForbidden();

        $code = Notification::sent($user, EmailOtpCode::class)->first()->code;
        $this->postJson('/api/v1/email/verify-code', ['email' => $user->email, 'code' => '000000'])->assertUnprocessable();
        $this->postJson('/api/v1/email/verify-code', ['email' => $user->email, 'code' => $code])->assertOk();
        $this->postJson('/api/v1/email/verify-code', ['email' => $user->email, 'code' => $code])->assertUnprocessable();
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->postJson('/api/v1/login', $this->payload())->assertOk()->assertJsonStructure(['token', 'user']);
    }

    public function test_registration_policy_and_verification_switches_are_database_driven(): void
    {
        $this->getJson('/api/v1/public-config')->assertOk()
            ->assertJsonPath('registration_enabled', true)
            ->assertJsonPath('registration_email_verification_enabled', false);

        app(AdminSettings::class)->save(['registration_enabled' => '0', 'registration_email_verification_enabled' => '1']);
        $this->getJson('/api/v1/public-config')->assertJsonPath('registration_enabled', false)
            ->assertJsonPath('registration_email_verification_enabled', true);
        $this->postJson('/api/v1/register', [
            'name' => 'Member', 'email' => 'person@example.com',
            'password' => 'StrongPassword123', 'password_confirmation' => 'StrongPassword123',
        ])->assertForbidden();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_unusual_ip_requires_code_only_after_trusted_first_login(): void
    {
        app(AdminSettings::class)->save(['unusual_ip_verification_enabled' => '1']);
        $user = $this->user();
        Notification::fake();

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10']);
        $this->postJson('/api/v1/login', $this->payload())->assertOk()->assertJsonStructure(['token']);
        $this->assertDatabaseCount('trusted_login_ips', 1);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.11']);
        $response = $this->postJson('/api/v1/login', $this->payload())
            ->assertStatus(202)
            ->assertJsonPath('verification_required', true)
            ->assertJsonStructure(['challenge_id'])
            ->assertJsonMissingPath('token');

        $this->assertDatabaseCount('personal_access_tokens', 1);
        $notification = Notification::sent($user, LoginIpVerification::class)->first();
        $this->assertNotNull($notification);
        $id = $response->json('challenge_id');

        $this->postJson('/api/v1/login/verify-ip', [
            'challenge_id' => $id, 'code' => '000000',
        ])->assertStatus(422);

        $this->postJson('/api/v1/login/verify-ip', [
            'challenge_id' => $id, 'code' => $notification->code,
        ])->assertOk()->assertJsonStructure(['token', 'user']);

        $this->assertDatabaseCount('login_ip_challenges', 0);
        $this->assertDatabaseCount('trusted_login_ips', 2);
        $this->postJson('/api/v1/login/verify-ip', [
            'challenge_id' => $id, 'code' => $notification->code,
        ])->assertStatus(422);
        $this->postJson('/api/v1/login', $this->payload())->assertOk();
    }

    public function test_wrong_network_and_expired_code_cannot_complete_login(): void
    {
        app(AdminSettings::class)->save(['unusual_ip_verification_enabled' => '1']);
        $user = $this->user();
        Notification::fake();

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10']);
        $this->postJson('/api/v1/login', $this->payload())->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.40']);
        $response = $this->postJson('/api/v1/login', $this->payload())->assertStatus(202);
        $code = Notification::sent($user, LoginIpVerification::class)->first()->code;

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.50']);
        $this->postJson('/api/v1/login/verify-ip', [
            'challenge_id' => $response->json('challenge_id'), 'code' => $code,
        ])->assertStatus(422);

        DB::table('login_ip_challenges')
            ->where('id', $response->json('challenge_id'))
            ->update(['expires_at' => now()->subMinute()]);
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.40']);
        $this->postJson('/api/v1/login/verify-ip', [
            'challenge_id' => $response->json('challenge_id'), 'code' => $code,
        ])->assertStatus(422);
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_password_change_invalidates_pending_ip_challenge(): void
    {
        app(AdminSettings::class)->save(['unusual_ip_verification_enabled' => '1']);
        $user = $this->user();
        Notification::fake();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10']);
        $this->postJson('/api/v1/login', $this->payload())->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.22']);
        $response = $this->postJson('/api/v1/login', $this->payload())->assertStatus(202);
        $code = Notification::sent($user, LoginIpVerification::class)->first()->code;
        $user->forceFill(['password' => 'AnotherStrongPassword456'])->save();
        $this->postJson('/api/v1/login/verify-ip', [
            'challenge_id' => $response->json('challenge_id'), 'code' => $code,
        ])->assertStatus(422);
    }

    public function test_disabling_unfamiliar_ip_policy_does_not_demand_email_codes(): void
    {
        $this->user();
        Notification::fake();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10']);
        $this->postJson('/api/v1/login', $this->payload())->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.20']);
        $this->postJson('/api/v1/login', $this->payload())->assertOk();
        Notification::assertNothingSent();
    }

    public function test_email_change_invalidates_pending_ip_challenge(): void
    {
        app(AdminSettings::class)->save(['unusual_ip_verification_enabled' => '1']);
        $user = $this->user();
        Notification::fake();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10']);
        $this->postJson('/api/v1/login', $this->payload())->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.20']);
        $challenge = $this->postJson('/api/v1/login', $this->payload())->assertStatus(202);
        $code = Notification::sent($user, LoginIpVerification::class)->first()->code;
        $user->forceFill(['email' => 'changed@example.com'])->save();
        $this->postJson('/api/v1/login/verify-ip', [
            'challenge_id' => $challenge->json('challenge_id'), 'code' => $code,
        ])->assertStatus(422);
    }
}
