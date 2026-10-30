<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>Reset password · OpenMindAI</title>
    <link rel="icon" href="{{ asset('openmindai-logo.png') }}">
    <link rel="stylesheet" href="{{ asset('css/admin-auth.css') }}?v=20261020-2">
</head>
<body>
<div class="auth-layout">
    <aside class="auth-intro">
        <a href="/admin/login" class="auth-brand"><img src="{{ asset('openmindai-logo.png') }}" alt="">OpenMindAI</a>
        <div class="auth-intro-content"><span class="auth-eyebrow">Account security</span><h2>Keep your account protected.</h2><p>Create a new password using the recovery link sent to your email address.</p></div>
        <div class="auth-footer">OpenMindAI · Secure account recovery</div>
    </aside>
    <main class="auth-content">
        <div class="auth-box">
            <div class="auth-overline">Password recovery</div>
            <h1>Reset your password</h1>
            <p class="auth-description">Enter your account email and choose a new password.</p>
            <div class="auth-card">
                <form id="reset-password-form" method="post" action="/api/v1/reset-password">
                    <input type="hidden" name="token" value="{{ request('token') }}">
                    <div class="auth-field"><label for="reset_email">Email address</label><input id="reset_email" name="email" type="email" value="{{ request('email') }}" required autocomplete="email"></div>
                    <div class="auth-field"><label for="reset_password">New password</label><input id="reset_password" name="password" type="password" minlength="6" maxlength="1024" required autocomplete="new-password"></div>
                    <div class="auth-field"><label for="reset_confirmation">Confirm new password</label><input id="reset_confirmation" name="password_confirmation" type="password" minlength="6" maxlength="1024" required autocomplete="new-password"></div>
                    <button type="submit" class="auth-button">Reset password</button>
                    <p id="reset-result" role="status" aria-live="polite" class="auth-alert" style="margin-top:16px" hidden></p>
                </form>
                <a class="auth-back" href="/admin/login">← Back to admin sign in</a>
            </div>
            <p class="auth-content-footer">OpenMindAI Account Security</p>
        </div>
    </main>
</div>
<script>
document.getElementById('reset-password-form').addEventListener('submit', async (event) => {
    event.preventDefault();
    const form = event.currentTarget;
    const output = document.getElementById('reset-result');
    const button = form.querySelector('button[type="submit"]');
    output.hidden = true;
    button.disabled = true;
    try {
        const response = await fetch(form.action, {
            method: 'POST',
            headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
            body: JSON.stringify(Object.fromEntries(new FormData(form))),
        });
        const result = await response.json();
        output.textContent = result.message || (response.ok ? 'Your password has been reset.' : 'Unable to reset password. Please check the information provided.');
        output.classList.toggle('success', response.ok);
        output.hidden = false;
        if (response.ok) form.querySelectorAll('input[type="password"]').forEach(field => field.value = '');
    } catch {
        output.textContent = 'Connection failed. Please try again.';
        output.classList.remove('success');
        output.hidden = false;
    } finally {
        button.disabled = false;
    }
});
</script>
<script src="{{ asset('js/password-visibility.js') }}" defer></script>
</body>
</html>
