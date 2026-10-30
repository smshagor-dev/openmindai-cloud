<?php

namespace App\Http\Controllers;

use App\Mail\SmtpTestMessage;
use App\Models\SmtpSetting;
use App\Services\SmtpSettings;
use App\Services\TwoFactor;
use Illuminate\Http\Request;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SmtpSettingsController
{
    public function index()
    {
        return view('admin.smtp-settings', ['smtp' => SmtpSetting::query()->find(1)]);
    }

    private function confirmIdentity(Request $request, TwoFactor $twoFactor): void
    {
        $request->validate([
            'current_password' => ['required', 'string', 'max:1024', 'current_password:web'],
            'code' => ['nullable', 'string', 'max:64'],
        ]);

        if ($request->user()->two_factor_confirmed_at) {
            abort_unless($twoFactor->verify($request->user(), (string) $request->input('code', '')), 422, 'A valid two-factor or recovery code is required.');
        }
    }

    public function update(Request $request, TwoFactor $twoFactor)
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'host' => ['required', 'string', 'max:253', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9.:-]*$/'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'encryption' => ['required', Rule::in(['tls', 'ssl', 'none'])],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:1024'],
            'from_address' => ['required', 'email', 'max:254'],
            'from_name' => ['required', 'string', 'max:120'],
            'reply_to' => ['nullable', 'email', 'max:254'],
        ]);

        $this->confirmIdentity($request, $twoFactor);

        $smtp = SmtpSetting::query()->find(1) ?? new SmtpSetting;
        $smtp->id = 1;

        // Never return the saved password to the browser. A blank input retains it.
        // Changing or removing the username must not silently reuse another account's password.
        $username = $data['username'] ?? null;
        $usernameChanged = $smtp->exists && $smtp->username !== $username;
        $newPassword = $data['password'] ?? '';
        if ($usernameChanged && $username && $newPassword === '') {
            throw ValidationException::withMessages(['password' => 'Enter the SMTP password when changing the username.']);
        }
        if ($username && $newPassword === '' && ! $smtp->password) {
            throw ValidationException::withMessages(['password' => 'Enter the SMTP password for this account.']);
        }

        unset($data['password']);
        $smtp->fill($data);
        if (! $username || $usernameChanged) {
            $smtp->password = null;
        }
        if ($newPassword !== '' && $username) {
            $smtp->password = $newPassword;
        }
        // A previous successful test is no longer valid after changing the settings.
        $smtp->last_tested_at = null;
        $smtp->save();

        return redirect('/admin/smtp-settings')->with('status', 'SMTP settings saved. New outgoing messages will use these settings when enabled.');
    }

    public function test(Request $request, TwoFactor $twoFactor)
    {
        $this->confirmIdentity($request, $twoFactor);

        $smtp = SmtpSetting::query()->find(1);
        abort_unless($smtp?->enabled, 422, 'Save and enable SMTP settings before sending a test email.');

        // A test can only be sent to the authenticated admin, not an arbitrary address.
        // Reconfigure and purge a resolved mailer to pick up the latest saved database settings.
        app(SmtpSettings::class)->apply();
        if (app()->resolved('mail.manager')) {
            $manager = app('mail.manager');
            if ($manager instanceof MailManager && Mail::getFacadeRoot() instanceof MailManager) {
                $manager->purge('smtp');
            }
        }

        try {
            Mail::to($request->user()->email)->send(new SmtpTestMessage);
        } catch (\Throwable $exception) {
            // Do not expose or log transport exceptions: they can include SMTP credentials.
            return back()->withErrors(['smtp' => 'The test email could not be sent. Check your SMTP host, credentials, encryption and hosting firewall.']);
        }

        $smtp->forceFill(['last_tested_at' => now()])->save();

        return back()->with('status', 'SMTP test email sent to your administrator address. Check your inbox.');
    }
}
