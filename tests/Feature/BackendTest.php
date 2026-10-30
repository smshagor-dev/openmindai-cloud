<?php

namespace Tests\Feature;

use App\Models\AiModel;
use App\Models\Conversation;
use App\Models\User;
use App\Notifications\EmailOtpCode;
use App\Services\EmailOtp;
use App\Services\TwoFactor;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class BackendTest extends TestCase
{
    use RefreshDatabase;

    private function user(array $extra = []): User
    {
        $u = User::create(['name' => 'Test', 'email' => uniqid().'@example.com', 'password' => 'StrongPassword123']);
        $u->forceFill(['email_verified_at' => now(), ...$extra])->save();

        return $u;
    }

    public function test_registration_succeeds_without_email_verification_and_never_grants_admin(): void
    {
        Notification::fake();
        $r = $this->postJson('/api/v1/register', ['name' => 'User', 'email' => 'new@example.com', 'password' => '123456', 'password_confirmation' => '123456', 'is_admin' => true]);
        $r->assertCreated()->assertJsonStructure(['token', 'user'])->assertJsonPath('message', 'Account created successfully.');
        $this->assertNotNull(User::first()->email_verified_at);
        Notification::assertNothingSent();
        $this->withToken($r->json('token'))->getJson('/api/v1/me')->assertOk();
        $this->assertFalse(User::first()->is_admin);
        $this->postJson('/api/v1/login', ['email' => 'new@example.com', 'password' => '123456', 'device_name' => 'test'])->assertOk();
    }

    public function test_login_logout_and_account_access(): void
    {
        $u = $this->user();
        $r = $this->postJson('/api/v1/login', ['email' => $u->email, 'password' => 'StrongPassword123', 'device_name' => 'test']);
        $r->assertOk();
        $token = $r->json('token');
        $this->withToken($token)->getJson('/api/v1/me')->assertOk()->assertJsonMissingPath('password');
        $this->withToken($token)->postJson('/api/v1/logout')->assertNoContent();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_suspended_account_and_guests_are_blocked(): void
    {
        $this->getJson('/api/v1/models')->assertUnauthorized();
        Sanctum::actingAs($this->user(['is_active' => false]));
        $this->getJson('/api/v1/models')->assertForbidden();
    }

    public function test_conversations_are_private(): void
    {
        $owner = $this->user();
        $c = Conversation::create(['user_id' => $owner->id, 'title' => 'private']);
        Sanctum::actingAs($this->user());
        $this->getJson('/api/v1/conversations/'.$c->id)->assertNotFound();
        $this->deleteJson('/api/v1/conversations/'.$c->id)->assertNotFound();
    }

    public function test_normal_users_cannot_access_admin(): void
    {
        $this->actingAs($this->user())->get('/admin')->assertForbidden();
    }

    public function test_chat_sends_only_to_configured_runtime_and_saves_history(): void
    {
        Http::preventStrayRequests();
        Http::fake(['127.0.0.1:8080/*' => Http::response(['choices' => [['message' => ['content' => 'Hello']]]])]);
        $m = AiModel::create(['name' => 'Qwen', 'family' => 'Qwen3', 'repository' => 'owner/repo', 'revision' => str_repeat('a', 40), 'filename' => 'model.gguf', 'sha256' => str_repeat('b', 64), 'bytes' => 4, 'license' => 'apache-2.0', 'status' => 'ready', 'enabled' => true, 'runtime_model' => 'local-qwen']);
        Sanctum::actingAs($this->user());
        $this->postJson('/api/v1/chat', ['model_id' => $m->id, 'message' => 'Hi'])->assertOk()->assertJsonPath('message.content', 'Hello');
        $this->assertDatabaseCount('messages', 2);
        Http::assertSent(fn ($r) => $r['model'] === 'local-qwen' && $r['stream'] === false);
    }

    public function test_failed_inference_does_not_store_partial_turn(): void
    {
        Http::fake(['*' => Http::response([], 500)]);
        $m = AiModel::create(['name' => 'Q', 'family' => 'Qwen3', 'repository' => 'owner/repo', 'revision' => str_repeat('a', 40), 'filename' => 'm.gguf', 'sha256' => str_repeat('b', 64), 'bytes' => 4, 'license' => 'apache', 'status' => 'ready', 'enabled' => true, 'runtime_model' => 'q']);
        Sanctum::actingAs($this->user());
        $this->postJson('/api/v1/chat', ['model_id' => $m->id, 'message' => 'Hi'])->assertStatus(502);
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_auth_rate_limit(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/login', ['email' => 'none@example.com', 'password' => 'wrong', 'device_name' => 'test'])->assertStatus(422);
        }$this->postJson('/api/v1/login', ['email' => 'none@example.com', 'password' => 'wrong', 'device_name' => 'test'])->assertStatus(429);
    }

    public function test_two_factor_required_and_recovery_is_single_use(): void
    {
        $u = $this->user(['two_factor_secret' => (new Google2FA)->generateSecretKey(), 'two_factor_confirmed_at' => now(), 'two_factor_recovery_codes' => [Hash::make('recovery-code')]]);
        $body = ['email' => $u->email, 'password' => 'StrongPassword123', 'device_name' => 'test'];
        $this->postJson('/api/v1/login', $body)->assertStatus(422);
        $this->postJson('/api/v1/login', $body + ['code' => 'recovery-code'])->assertOk();
        $this->postJson('/api/v1/login', $body + ['code' => 'recovery-code'])->assertStatus(422);
    }

    public function test_model_import_rejects_missing_integrity_metadata(): void
    {
        Http::fake(['*' => Http::response(['sha' => str_repeat('a', 40), 'siblings' => [['rfilename' => 'm.gguf']]])]);
        $this->actingAs($this->user(['is_admin' => true]))->withSession(['admin_verified' => true])->post('/admin/models', ['name' => 'Q', 'family' => 'Qwen3', 'repository' => 'owner/repo', 'filename' => 'm.gguf', 'revision' => str_repeat('a', 40), 'license_accepted' => 1])->assertStatus(422);
        $this->assertDatabaseCount('ai_models', 0);
    }

    public function test_admin_views_render(): void
    {
        $u = $this->user(['is_admin' => true]);
        $this->actingAs($u)->withSession(['admin_verified' => true]);
        foreach (['/admin', '/admin/users', '/admin/users/'.$u->id, '/admin/models'] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_two_factor_setup_confirm_and_totp_replay(): void
    {
        $u = $this->user();
        Sanctum::actingAs($u);
        $setup = $this->postJson('/api/v1/2fa/setup', ['password' => 'StrongPassword123'])->assertOk();
        $code = (new Google2FA)->getCurrentOtp($setup->json('secret'));
        $this->postJson('/api/v1/2fa/confirm', ['code' => $code])->assertOk()->assertJsonCount(8, 'recovery_codes');
        $this->assertFalse(app(TwoFactor::class)->verify($u, $code));
    }

    public function test_model_import_pins_verified_revision(): void
    {
        Http::fake(['*' => Http::response(['sha' => str_repeat('a', 40), 'cardData' => ['license' => 'apache-2.0'], 'siblings' => [['rfilename' => 'm.gguf', 'lfs' => ['sha256' => str_repeat('b', 64), 'size' => 4]]]])]);
        $this->actingAs($this->user(['is_admin' => true]))->withSession(['admin_verified' => true])->post('/admin/models', ['name' => 'Q', 'family' => 'Qwen3', 'repository' => 'owner/repo', 'filename' => 'm.gguf', 'revision' => str_repeat('a', 40), 'license_accepted' => 1])->assertRedirect('/admin/models');
        $this->assertDatabaseHas('ai_models', ['revision' => str_repeat('a', 40), 'sha256' => str_repeat('b', 64), 'enabled' => false]);
    }

    public function test_admin_session_does_not_authenticate_api(): void
    {
        $this->actingAs($this->user(['is_admin' => true]))->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_email_verification_uses_a_single_use_six_digit_code(): void
    {
        $user = $this->user(['email_verified_at' => null]);
        // The former signed-link verification route must not exist.
        $this->get('/email/verify/'.$user->id.'/'.sha1($user->email))->assertNotFound();
        Notification::fake();
        app(EmailOtp::class)->send($user, 'registration');
        $code = Notification::sent($user, EmailOtpCode::class)->first()->code;

        $this->postJson('/api/v1/email/verify-code', [
            'email' => $user->email, 'code' => '000000',
        ])->assertUnprocessable();
        $this->postJson('/api/v1/email/verify-code', [
            'email' => $user->email, 'code' => $code,
        ])->assertOk();
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->postJson('/api/v1/email/verify-code', [
            'email' => $user->email, 'code' => $code,
        ])->assertUnprocessable();
    }

    public function test_password_reset_revokes_existing_tokens(): void
    {
        $user = $this->user();
        $user->createToken('old-device');
        Notification::fake();
        app(EmailOtp::class)->send($user, 'password');
        $code = Notification::sent($user, EmailOtpCode::class)->first()->code;
        $this->postJson('/api/v1/reset-password', [
            'email' => $user->email, 'code' => $code,
            'password' => 'NewStrongPassword456',
            'password_confirmation' => 'NewStrongPassword456',
        ])->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertTrue(Hash::check('NewStrongPassword456', $user->fresh()->password));
        $this->postJson('/api/v1/reset-password', [
            'email' => $user->email, 'code' => $code,
            'password' => 'AnotherPassword123',
            'password_confirmation' => 'AnotherPassword123',
        ])->assertUnprocessable();
    }

    public function test_history_is_ordered_by_latest_activity(): void
    {
        $user = $this->user();
        $older = Conversation::create(['user_id' => $user->id, 'title' => 'Older but active']);
        $newer = Conversation::create(['user_id' => $user->id, 'title' => 'Newer but idle']);
        $older->forceFill(['updated_at' => now()->addMinute()])->save();
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/conversations')->assertOk()->assertJsonPath('data.0.id', $older->id);
    }

    public function test_password_reset_link_uses_configured_frontend(): void
    {
        config(['app.frontend_url' => 'https://chat.example.com']);
        $mail = (new ResetPassword('test-token'))->toMail($this->user());
        $this->assertStringStartsWith('https://chat.example.com/reset-password?', $mail->actionUrl);
        $this->assertStringContainsString('token=test-token', $mail->actionUrl);
    }

    public function test_forwarded_ip_is_accepted_only_from_configured_proxy(): void
    {
        $request = Request::create('/api/v1/login', 'POST', server: ['REMOTE_ADDR' => '10.0.0.2', 'HTTP_X_FORWARDED_FOR' => '203.0.113.8']);
        config(['trustedproxy.proxies' => ['10.0.0.2']]);
        $response = app(TrustProxies::class)->handle($request, fn ($r) => response($r->ip()));
        $this->assertSame('203.0.113.8', $response->getContent());
        config(['trustedproxy.proxies' => []]);
        $response = app(TrustProxies::class)->handle($request, fn ($r) => response($r->ip()));
        $this->assertSame('10.0.0.2', $response->getContent());
    }
}
