@extends('admin.layout')
@section('title', 'Workspace settings')
@section('section', 'Settings')
@section('content')
<div class="admin-page-heading"><div><span class="eyebrow">ADMINISTRATION</span><h1>Workspace settings</h1><p>Manage branding, registration and usage policies stored in the application database.</p></div></div>
<form method="post" action="/admin/settings" class="settings-layout">
    @csrf @method('PATCH')
    <section class="card"><div class="section-separator"><h2>Company profile</h2><p>Branding and support information displayed in the administration workspace.</p></div>
        <div class="field"><label for="site_name">Workspace name</label><input id="site_name" name="site_name" maxlength="60" required value="{{ old('site_name', $settings['site_name']) }}"></div>
        <div class="field"><label for="support_email">Support email (optional)</label><input id="support_email" name="support_email" type="email" maxlength="254" value="{{ old('support_email', $settings['support_email']) }}" placeholder="support@example.com"></div>
        <div class="field"><label for="dashboard_note">Dashboard message</label><textarea id="dashboard_note" name="dashboard_note" maxlength="220" rows="3">{{ old('dashboard_note', $settings['dashboard_note']) }}</textarea></div>
    </section>
    <section class="card"><div class="section-separator"><h2>Access and usage</h2><p>Business policies controlled by the database.</p></div>
        <div class="field"><label for="registration_enabled">New account registration</label><select id="registration_enabled" name="registration_enabled"><option value="1" @selected(old('registration_enabled', $settings['registration_enabled']) == '1')>Enabled</option><option value="0" @selected(old('registration_enabled', $settings['registration_enabled']) == '0')>Disabled</option></select></div>
        <div class="field"><label for="registration_email_verification_enabled">Email verification for new registrations</label><select id="registration_email_verification_enabled" name="registration_email_verification_enabled">
            <option value="1" @selected(old('registration_email_verification_enabled', $settings['registration_email_verification_enabled']) == '1')>Required — send a verification link before allowing login</option>
            <option value="0" @selected(old('registration_email_verification_enabled', $settings['registration_email_verification_enabled']) == '0')>Not required — activate new accounts immediately</option>
        </select><p class="field-help">Verification messages use the configured SMTP delivery service, including Gmail SMTP if configured. This is email ownership verification, not Google account sign-in.</p></div>
        <div class="field"><label for="unusual_ip_verification_enabled">Unfamiliar IP login verification</label><select id="unusual_ip_verification_enabled" name="unusual_ip_verification_enabled">
            <option value="1" @selected(old('unusual_ip_verification_enabled', $settings['unusual_ip_verification_enabled']) == '1')>Enabled — require an email security code from a new IP</option>
            <option value="0" @selected(old('unusual_ip_verification_enabled', $settings['unusual_ip_verification_enabled']) == '0')>Disabled</option>
        </select><p class="field-help">The first completed login establishes a trusted IP. Subsequent logins from a new IP need a one-time code. A working email provider and correctly trusted proxy/IP setup are required.</p></div>
        <div class="field"><label for="guest_chat_daily_limit">Guest chat requests per day per IP</label><input id="guest_chat_daily_limit" name="guest_chat_daily_limit" type="number" min="0" max="100" value="{{ old('guest_chat_daily_limit', $settings['guest_chat_daily_limit']) }}"><p class="field-help">Set to 0 to require login. Guest conversations stay in the visitor's browser.</p></div>
        <div class="field"><label for="frontend_inference_route">Frontend chat inference</label><select id="frontend_inference_route" name="frontend_inference_route"><option value="backend" @selected(old('frontend_inference_route', $settings['frontend_inference_route']) === 'backend')>Through Laravel backend</option><option value="direct" @selected(old('frontend_inference_route', $settings['frontend_inference_route']) === 'direct')>Direct from Next.js server to provider</option></select><p class="field-help">Both modes keep API keys on the server. Authentication, conversation storage and usage tracking remain in Laravel. Guest chat always uses Laravel.</p></div>
        <div class="settings-fields"><div class="field"><label for="chat_daily_limit">Chat requests per day</label><input id="chat_daily_limit" type="number" min="1" max="100000" name="chat_daily_limit" required value="{{ old('chat_daily_limit', $settings['chat_daily_limit']) }}"></div><div class="field"><label for="task_daily_limit">Task runs per day</label><input id="task_daily_limit" type="number" min="1" max="10000" name="task_daily_limit" required value="{{ old('task_daily_limit', $settings['task_daily_limit']) }}"></div></div>
        <p class="field-help">Updates apply to new requests, not jobs already running.</p>
    </section>
    <section class="card settings-confirm"><div class="section-separator"><h2>Confirm workspace changes</h2><p>Verify your administrator credentials before saving these settings.</p></div>
        <div class="form-fields"><div class="field"><label for="settings_password">Current password</label><input id="settings_password" type="password" name="current_password" required autocomplete="current-password"></div>
        @if(auth()->user()->two_factor_confirmed_at)<div class="field"><label for="settings_code">Authenticator or recovery code</label><input id="settings_code" name="code" maxlength="64" required autocomplete="one-time-code"></div>@endif</div>
        <div class="form-actions"><button class="btn-primary-admin" type="submit">Save workspace settings</button></div>
    </section>
</form>
<div class="muted-note"><strong>Infrastructure credentials stay private.</strong><div>Database passwords, APP_KEY, API secrets and runtime hosting settings remain in protected deployment configuration and are not exposed in these business controls.</div></div>
@endsection
