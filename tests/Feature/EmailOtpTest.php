<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\EmailOtpCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class EmailOtpTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_reset_requires_valid_unused_six_digit_code(): void
    {
        Notification::fake();
        $user = User::create(['name' => 'Tester', 'email' => 'test@example.com', 'password' => 'OldPassword123']);
        $this->postJson('/api/v1/forgot-password', ['email' => $user->email])->assertOk();
        $code = Notification::sent($user, EmailOtpCode::class)->first()->code;
        $payload = [
            'email' => $user->email, 'code' => '000000',
            'password' => 'NewPassword123', 'password_confirmation' => 'NewPassword123',
        ];
        $this->postJson('/api/v1/reset-password', $payload)->assertUnprocessable();
        $this->assertTrue(Hash::check('OldPassword123', $user->fresh()->password));
        $payload['code'] = $code;
        $this->postJson('/api/v1/reset-password', $payload)->assertOk();
        $this->assertTrue(Hash::check('NewPassword123', $user->fresh()->password));
        $this->postJson('/api/v1/reset-password', $payload)->assertUnprocessable();
    }

    public function test_expired_code_is_rejected(): void
    {
        Notification::fake();
        $user = User::create(['name' => 'Tester', 'email' => 'test@example.com', 'password' => 'OldPassword123']);
        $this->postJson('/api/v1/forgot-password', ['email' => $user->email])->assertOk();
        $code = Notification::sent($user, EmailOtpCode::class)->first()->code;
        DB::table('email_otp_challenges')->where('email', $user->email)->update(['expires_at' => now()->subMinute()]);
        $this->postJson('/api/v1/reset-password', [
            'email' => $user->email, 'code' => $code,
            'password' => 'NewPassword123', 'password_confirmation' => 'NewPassword123',
        ])->assertUnprocessable();
    }
}
