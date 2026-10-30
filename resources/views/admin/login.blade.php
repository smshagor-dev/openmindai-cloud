<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>{{ $challenging ? 'Verify your identity' : 'Admin sign in' }} · OpenMindAI</title>
    <link rel="icon" href="{{ asset('openmindai-logo.png') }}">
    <link rel="stylesheet" href="{{ asset('css/admin-auth.css') }}?v=20261020-2">
</head>
<body>
<div class="auth-layout">
    <aside class="auth-intro">
        <a class="auth-brand" href="/admin/login"><img src="{{ asset('openmindai-logo.png') }}" alt="">OpenMindAI</a>
        <div class="auth-intro-content">
            <span class="auth-eyebrow">Management console</span>
            <h2>One workspace. Complete control.</h2>
            <p>Secure administration for people, AI models, usage policies and platform operations.</p>
            <div class="auth-benefits">
                <div class="auth-benefit"><span class="auth-check">✓</span>Team and account administration</div>
                <div class="auth-benefit"><span class="auth-check">✓</span>Managed models and deployments</div>
                <div class="auth-benefit"><span class="auth-check">✓</span>Database-managed workspace settings</div>
            </div>
        </div>
        <div class="auth-footer">OpenMindAI · Authorized access only</div>
    </aside>
    <main class="auth-content">
        <div class="auth-box">
            <div class="auth-overline">Secure administration</div>
            <h1>{{ $challenging ? 'Verify your identity' : 'Welcome back' }}</h1>
            <p class="auth-description">{{ $challenging ? 'Two-factor authentication is enabled. Enter your authenticator or recovery code to continue.' : 'Sign in to your administration workspace to manage the platform.' }}</p>
            <div class="auth-card">
                @if($errors->any())<div class="auth-alert" role="alert">{{ $errors->first() }}</div>@endif
                <form method="post" action="/admin/login">
                    @csrf
                    @if($challenging)
                        <div class="auth-field"><label for="code">Authenticator or recovery code</label><input id="code" name="code" type="text" autocomplete="one-time-code" maxlength="64" required autofocus spellcheck="false" autocapitalize="off"></div>
                    @else
                        <div class="auth-field"><label for="email">Email address</label><input id="email" name="email" type="email" value="{{ old('email') }}" placeholder="you@company.com" required autocomplete="username" autofocus></div>
                        <div class="auth-field"><label for="password">Password</label><input id="password" name="password" type="password" required autocomplete="current-password"></div>
                    @endif
                    <button type="submit" class="auth-button">{{ $challenging ? 'Verify and sign in' : 'Sign in to console' }}</button>
                </form>
                @if($challenging)<a class="auth-back" href="/admin/login?restart=1">← Back to sign in</a>@endif
                <p class="auth-help">For authorized workspace administrators only.</p>
            </div>
            <p class="auth-content-footer">OpenMindAI Administration</p>
        </div>
    </main>
</div>
<script src="{{ asset('js/password-visibility.js') }}" defer></script>
</body>
</html>
