<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\EmailOtpCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SocialAuthAndLegalPagesTest extends TestCase
{
    use RefreshDatabase;

    private const EXCHANGE = ['code' => 'authorization-code', 'verifier' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'link' => false];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.frontend_url' => 'https://chat.example.test',
            'mobile_gateway.bridge_key' => str_repeat('k', 32),
            'social.google.id' => 'google-client',
            'social.google.secret' => 'google-secret',
            'social.facebook.id' => 'facebook-client',
            'social.facebook.secret' => 'facebook-secret',
        ]);
        Http::preventStrayRequests();
    }

    private function bridge(): static
    {
        return $this->withHeader('X-Chat-Bridge-Key', str_repeat('k', 32));
    }

    public function test_google_exchanges_server_side_and_never_links_by_matching_email(): void
    {
        $this->postJson('/api/v1/social/google/exchange', self::EXCHANGE)->assertForbidden();
        $this->getJson('/api/v1/public-config')->assertJsonPath('social_providers', ['google', 'facebook'])->assertDontSee('google-secret');
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'google-token']),
            'https://openidconnect.googleapis.com/v1/userinfo' => Http::response([
                'sub' => 'google-subject', 'name' => 'Google User', 'email' => 'user@gmail.com', 'email_verified' => true,
            ]),
        ]);
        $account = User::create(['name' => 'Existing User', 'email' => 'user@gmail.com', 'password' => 'StrongPassword123']);
        $account->forceFill(['email_verified_at' => now()])->save();
        $this->bridge()->postJson('/api/v1/social/google/exchange', self::EXCHANGE)->assertConflict();
        $this->assertDatabaseCount('social_identities', 0);

        $token = $account->createToken('Openmindai Web', ['user'], now()->addDay())->plainTextToken;
        $this->withToken($token)->postJson('/api/v1/social/google/exchange', array_replace(self::EXCHANGE, ['link' => true]))->assertOk()->assertJsonPath('linked', true);
        $this->postJson('/api/v1/social/google/exchange', self::EXCHANGE)->assertOk()->assertJsonPath('user.id', $account->id)->assertJsonStructure(['token']);
        $this->assertDatabaseCount('social_identities', 1);
        Http::assertSent(fn ($request) => $request->url() === 'https://oauth2.googleapis.com/token' && $request['code_verifier'] === self::EXCHANGE['verifier']);
    }

    public function test_verified_new_google_user_gets_a_session_without_an_extra_code(): void
    {
        Notification::fake();
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'google-token']),
            'https://openidconnect.googleapis.com/v1/userinfo' => Http::response([
                'sub' => 'new-google-subject', 'name' => 'Google User', 'email' => 'newuser@gmail.com', 'email_verified' => true,
            ]),
        ]);
        $response = $this->bridge()->postJson('/api/v1/social/google/exchange', self::EXCHANGE)
            ->assertOk()->assertJsonPath('user.email', 'newuser@gmail.com')->assertJsonStructure(['token']);
        $this->assertNotNull($response->json('user.email_verified_at'));
        $this->assertDatabaseCount('social_identities', 1);
        Notification::assertNothingSent();
    }

    public function test_facebook_requires_email_code_before_new_account_and_consumes_ticket_once(): void
    {
        Notification::fake();
        Http::fake([
            'https://graph.facebook.com/*/oauth/access_token*' => Http::response(['access_token' => 'facebook-token']),
            'https://graph.facebook.com/*/me*' => Http::response(['id' => 'facebook-subject', 'name' => 'Facebook User', 'email' => 'user@example.test']),
        ]);
        $result = $this->bridge()->postJson('/api/v1/social/facebook/exchange', self::EXCHANGE)->assertAccepted()->assertJsonPath('step', 'email');
        $this->assertDatabaseMissing('users', ['email' => 'user@example.test']);
        $code = null;
        Notification::assertSentOnDemand(EmailOtpCode::class, function (EmailOtpCode $notification) use (&$code) {
            $code = $notification->code;

            return true;
        });
        $input = ['ticket' => $result->json('ticket'), 'code' => $code];
        $this->postJson('/api/v1/social/facebook/complete', $input)->assertOk()->assertJsonPath('user.email', 'user@example.test')->assertJsonStructure(['token']);
        $this->postJson('/api/v1/social/facebook/complete', $input)->assertUnprocessable();
        $this->assertDatabaseCount('social_identities', 1);
    }

    public function test_only_verified_admin_can_publish_legal_page_and_public_api_reflects_it(): void
    {
        $this->getJson('/api/v1/legal/privacy-policy')->assertOk()->assertJsonPath('title', 'Privacy Policy');
        $this->getJson('/api/v1/legal/unknown')->assertNotFound();
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'StrongPassword123']);
        $admin->forceFill(['is_admin' => true, 'email_verified_at' => now()])->save();
        $input = ['title' => 'Updated Privacy Policy', 'body' => '## Data controls', 'current_password' => 'StrongPassword123'];
        $url = '/admin/legal-pages/privacy-policy';
        $this->actingAs($admin)->patch($url, $input)->assertForbidden();
        $this->withSession(['admin_verified' => true])->patch($url, $input)->assertRedirect('/admin/legal-pages');
        $this->getJson('/api/v1/legal/privacy-policy')->assertOk()->assertJsonPath('title', $input['title'])->assertJsonPath('body', $input['body']);
    }

    public function test_backend_policy_renders_markdown_without_raw_html_or_unsafe_links(): void
    {
        DB::table('legal_pages')->where('slug', 'privacy-policy')->update([
            'body' => "## Data controls\n\nUse **account settings**.\n\n[Safe link](https://example.org)\n\n<script>alert(1)</script>\n\n[Unsafe](javascript:alert%281%29)",
        ]);
        $this->get('/privacy-policy')->assertOk()
            ->assertSee('<h2>Data controls</h2>', false)
            ->assertSee('<strong>account settings</strong>', false)
            ->assertSee('href="https://example.org"', false)
            ->assertDontSee('<script>', false)
            ->assertDontSee('href="javascript:', false);
        $this->get('/terms-and-conditions')->assertOk()->assertSee('Terms and Conditions');
    }
}
