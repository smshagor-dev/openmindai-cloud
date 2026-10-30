<?php

namespace Tests\Feature;

use App\Models\SmtpSetting;
use App\Models\User;
use App\Services\SmtpSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SmtpSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::create([
            'name' => 'Owner',
            'email' => 'owner@example.com',
            'password' => 'StrongPassword123',
        ]);
        $admin->forceFill(['is_admin' => true, 'email_verified_at' => now()])->save();
        $this->actingAs($admin)->withSession(['admin_verified' => true]);

        return $admin;
    }

    private function settings(array $changes = []): array
    {
        return array_replace([
            'enabled' => '1',
            'host' => 'smtp.example.com',
            'port' => 587,
            'encryption' => 'tls',
            'username' => 'mailbox@example.com',
            'password' => 'MailAppPassword123',
            'from_address' => 'noreply@example.com',
            'from_name' => 'OpenMindAI',
            'reply_to' => 'support@example.com',
            'current_password' => 'StrongPassword123',
        ], $changes);
    }

    public function test_only_administrators_can_open_and_update_smtp_settings(): void
    {
        $this->get('/admin/smtp-settings')->assertRedirect('/admin/login');

        $member = User::create(['name' => 'Member', 'email' => 'member@example.com', 'password' => 'StrongPassword123']);
        $this->actingAs($member)->withSession(['admin_verified' => true]);
        $this->get('/admin/smtp-settings')->assertForbidden();
        $this->patch('/admin/smtp-settings', $this->settings())->assertForbidden();
        $this->assertDatabaseCount('smtp_settings', 0);

        $this->admin();
        $this->get('/admin/smtp-settings')->assertOk()->assertSee('SMTP settings');
    }

    public function test_smtp_password_is_encrypted_and_never_rendered(): void
    {
        $this->admin();
        $this->patch('/admin/smtp-settings', $this->settings())->assertRedirect('/admin/smtp-settings');

        $saved = SmtpSetting::findOrFail(1);
        $this->assertSame('MailAppPassword123', $saved->password);
        $this->assertNotSame('MailAppPassword123', DB::table('smtp_settings')->value('password'));
        $this->assertStringNotContainsString('MailAppPassword123', $this->get('/admin/smtp-settings')->assertOk()->getContent());
    }

    public function test_mail_manager_configuration_uses_database_values_when_enabled(): void
    {
        $this->admin();
        $this->patch('/admin/smtp-settings', $this->settings())->assertRedirect();

        app(SmtpSettings::class)->apply();

        $this->assertSame('smtp', config('mail.default'));
        $this->assertSame('smtp.example.com', config('mail.mailers.smtp.host'));
        $this->assertSame(587, config('mail.mailers.smtp.port'));
        $this->assertSame('MailAppPassword123', config('mail.mailers.smtp.password'));
        $this->assertSame('smtp', config('mail.mailers.smtp.scheme'));
        $this->assertTrue(config('mail.mailers.smtp.require_tls'));
        $this->assertSame('noreply@example.com', config('mail.from.address'));
        $this->assertSame('OpenMindAI', config('mail.from.name'));
        $this->assertSame('support@example.com', config('mail.reply_to.address'));
    }

    public function test_credentials_can_be_retained_but_not_reused_for_another_username(): void
    {
        $this->admin();
        $this->patch('/admin/smtp-settings', $this->settings())->assertRedirect();

        $this->patch('/admin/smtp-settings', $this->settings(['password' => '', 'host' => 'new.smtp.example.com']))->assertRedirect();
        $this->assertSame('MailAppPassword123', SmtpSetting::findOrFail(1)->password);
        $this->patch('/admin/smtp-settings', $this->settings(['username' => 'other@example.com', 'password' => '']))
            ->assertSessionHasErrors('password');
        $this->assertSame('mailbox@example.com', SmtpSetting::findOrFail(1)->username);
        $this->patch('/admin/smtp-settings', $this->settings(['username' => '', 'password' => '']))
            ->assertRedirect();
        $this->assertNull(SmtpSetting::findOrFail(1)->password);
    }

    public function test_smtp_settings_require_a_verified_session_and_valid_password(): void
    {
        $this->admin();
        $this->withSession(['admin_verified' => false])
            ->patch('/admin/smtp-settings', $this->settings())->assertForbidden();
        $this->withSession(['admin_verified' => true])
            ->patch('/admin/smtp-settings', $this->settings(['current_password' => 'invalid']))
            ->assertSessionHasErrors('current_password');
        $this->patch('/admin/smtp-settings', $this->settings(['port' => 99999]))
            ->assertSessionHasErrors('port');
        $this->assertDatabaseCount('smtp_settings', 0);
    }

    public function test_test_email_is_sent_only_to_the_current_admin(): void
    {
        $admin = $this->admin();
        $this->patch('/admin/smtp-settings', $this->settings())->assertRedirect();
        Mail::fake();
        $this->post('/admin/smtp-settings/test', [
            'current_password' => 'StrongPassword123',
            'recipient' => 'outside@example.com',
        ])->assertRedirect()->assertSessionHasNoErrors();
        Mail::assertSentCount(1);
        $this->assertNotNull(SmtpSetting::findOrFail(1)->last_tested_at);
        $this->assertSame('owner@example.com', $admin->email);
    }

    public function test_testing_requires_saved_and_enabled_smtp(): void
    {
        $this->admin();
        $this->post('/admin/smtp-settings/test', ['current_password' => 'StrongPassword123'])->assertStatus(422);
        $this->patch('/admin/smtp-settings', $this->settings(['enabled' => '0']))->assertRedirect();
        $this->post('/admin/smtp-settings/test', ['current_password' => 'StrongPassword123'])->assertStatus(422);
    }
}
