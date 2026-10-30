@extends('admin.layout')
@section('title', 'OpenMindAI code agents')
@section('section', 'Code agents')
@section('content')
<div class="admin-page-heading"><div><span class="eyebrow">HOSTED INFERENCE</span><h1>OpenMindAI code agents</h1><p>Manage server-hosted coding models and secure access for OpenMindAI applications.</p></div></div>
<div class="muted-note" style="margin-bottom:20px">
    <strong>NVIDIA connection:</strong> {{ $nvidiaReady ? 'Configured on the Laravel server' : 'Not configured' }}.
    Add NVIDIA_API_KEY in the private Laravel .env file. Credentials are never rendered here. Model availability must be verified with your NVIDIA account.
</div>
<div class="section-separator"><h2>Hosted models</h2><p>Friendly names appear in OpenMindAI clients; provider IDs stay on the server.</p></div>
<div class="card-grid" style="margin-bottom:25px">
@foreach($agents as $agent)
  <section class="admin-card">
    <div class="entity-card-header"><div><h2>{{ $agent->name }}</h2><p class="entity-meta">Code agent</p></div><span class="status-pill success">Listed</span></div>
    <p class="entity-meta"><code style="word-break:break-all">{{ $agent->provider_model }}</code></p>
    <p class="entity-meta">Always listed. <a href="/admin/nvidia-models?category=Coding">Manage NVIDIA model settings</a>.</p>
  </section>
@endforeach
</div>
<div class="section-separator"><h2>Unified API</h2><p>One origin for models and completions. Use a generated developer key on your own backend.</p></div>
<section class="admin-card" style="margin-bottom:24px">
  <div class="field"><label>API base URL</label><input readonly value="{{ $developerApiBase }}"></div>
  <p class="entity-meta">GET /models · POST /chat/completions · Authorization: Bearer YOUR_DEVELOPER_KEY</p>
  <p class="entity-meta">User keys are generated in OpenMindAI Account → Developer API. Keys are shown once, expire after 90 days and can be revoked.</p>
</section>
<div class="section-separator"><h2>Developer API Keys for OpenMind Studio</h2><p>Individual user credentials for installed desktop applications.</p></div>
<section class="admin-card" style="margin-bottom:24px">
  <h2>Your Developer API Keys</h2>
  <p class="entity-meta">Generate a personal Bearer API key here for OpenMind Studio. This is <strong>not</strong> your First-party App ID or Private Signing Secret. Each key belongs only to your administrator account and is authorized for the OpenMindAI model gateway.</p>
  <p class="entity-meta">Keys expire in 90 days and can be revoked. Existing key values are hashed and cannot be retrieved. Create a replacement if you have lost one.</p>
  <form id="new-developer-key-form" action="/admin/code-agents/developer-keys" method="post" data-admin-devkey-form>
    @csrf
    <div class="field"><label for="devkey-name">Key label</label><input id="devkey-name" name="name" placeholder="OpenMind Studio Desktop" required minlength="3" maxlength="64" autocomplete="off"></div>
    <div class="field"><label for="devkey-password">Admin password</label><input id="devkey-password" type="password" name="current_password" required autocomplete="current-password"></div>
    @if(auth()->user()->two_factor_confirmed_at)
      <div class="field"><label for="devkey-code">2FA code</label><input id="devkey-code" name="code" required autocomplete="one-time-code"></div>
    @endif
    <div class="form-actions"><button class="btn-primary-admin" type="submit">Generate Developer API Key</button></div>
  </form>
  <p class="entity-meta" role="status" aria-live="polite" data-devkey-message></p>
  <div data-devkey-created hidden style="margin-top:18px">
    <div class="field">
      <label for="created-devkey">New Developer API Key — shown only once</label>
      <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <input id="created-devkey" data-devkey-secret type="password" readonly autocomplete="off" spellcheck="false" style="flex:1;min-width:200px">
        <button class="btn-primary-admin" type="button" data-devkey-copy>Copy API Key</button>
        <button class="btn-primary-admin" type="button" data-devkey-hide>Hide</button>
      </div>
      <small>Copy it into OpenMind Studio → Agent Settings → OpenMindAI Cloud. This view clears the key after 60 seconds. Do not enter it in a distributed app's source code.</small>
    </div>
  </div>
  <div class="section-separator"><h3>Existing Developer API Keys</h3></div>
  <div data-devkey-list>
    @forelse($developerKeys as $key)
    <div class="registered-app" style="padding:12px 0;border-bottom:1px solid var(--tblr-border-color, #ddd)">
      <strong>{{ preg_replace('/^developer:/', '', $key->name) }}</strong>
      <p class="entity-meta">Created {{ $key->created_at }} · Expires {{ $key->expires_at ?: 'Never' }} · Last used {{ $key->last_used_at ?: 'Never' }}</p>
      <p class="entity-meta">Stored securely — original key cannot be revealed. Revoke and generate a replacement if needed.</p>
      <details>
        <summary>Revoke this API key</summary>
        <form method="post" action="/admin/code-agents/developer-keys/{{ $key->id }}" data-devkey-revoke>
          @csrf @method('DELETE')
          <div class="field"><label>Admin password</label><input name="current_password" type="password" required autocomplete="current-password"></div>
          @if(auth()->user()->two_factor_confirmed_at)<div class="field"><label>2FA code</label><input name="code" required autocomplete="one-time-code"></div>@endif
          <div class="form-actions"><button class="btn-primary-admin" type="submit">Revoke Developer API Key</button></div>
        </form>
      </details>
    </div>
    @empty
    <p class="entity-meta">No Developer API Keys for this administrator yet.</p>
    @endforelse
  </div>
</section>
<script src="{{ asset('js/admin-developer-keys.js') }}" defer></script>
<div class="section-separator"><h2>Trusted OpenMindAI apps</h2><p>Your own server can sign requests without asking each end user for an API key. A secret is still required on each trusted app server.</p></div>
<div class="card-grid">
<section class="admin-card">
  <h2>Register first-party app</h2>
  <form action="/admin/code-agents/apps" method="post">
    @csrf
    <div class="field"><label for="app_name">App name</label><input id="app_name" name="name" required maxlength="120" placeholder="OpenMind Studio"></div>
    <div class="field"><label for="app_password">Admin password</label><input id="app_password" type="password" name="current_password" required autocomplete="current-password"></div>
    @if(auth()->user()->two_factor_confirmed_at)<div class="field"><label for="app_code">2FA code</label><input id="app_code" name="code" required autocomplete="one-time-code"></div>@endif
    <div class="form-actions"><button class="btn-primary-admin" type="submit">Register trusted app</button></div>
  </form>
</section>
<section class="admin-card">
  <h2>Registered applications</h2>
  <p class="entity-meta">Each application has two credentials: an App ID and a private signing secret. Copy the ID directly; re-enter your password (and 2FA if enabled) to reveal its encrypted secret.</p>
  @forelse($apps as $app)
  <div class="registered-app" data-registered-app style="padding:16px 0;border-bottom:1px solid var(--tblr-border-color, #ddd)">
    <div class="entity-card-header">
      <div><strong>{{ $app->name }}</strong><p class="entity-meta">{{ $app->enabled ? 'Active' : 'Revoked' }} @if($app->last_used_at) · Last used {{ $app->last_used_at }} @endif</p></div>
      <span class="status-pill {{ $app->enabled ? 'success' : 'warning' }}">{{ $app->enabled ? 'Active' : 'Revoked' }}</span>
    </div>
    <div class="field">
      <label for="app-id-{{ $app->id }}">App ID</label>
      <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <input id="app-id-{{ $app->id }}" data-app-id readonly value="{{ $app->app_id }}" style="flex:1;min-width:180px" />
        <button type="button" class="btn-primary-admin" data-copy-app-id>Copy App ID</button>
      </div>
    </div>
    <div class="field">
      <label for="app-secret-{{ $app->id }}">Private signing secret</label>
      <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <input id="app-secret-{{ $app->id }}" data-app-secret type="password" readonly value="" placeholder="{{ $app->enabled ? 'Locked — verify to reveal' : 'Revoked' }}" autocomplete="off" spellcheck="false" style="flex:1;min-width:180px" />
        <button type="button" class="btn-primary-admin" data-copy-app-secret disabled>Copy Secret</button>
        <button type="button" class="btn-primary-admin" data-hide-app-secret disabled>Hide</button>
      </div>
    </div>
    @if($app->enabled)
    <form data-reveal-app-form method="post" action="/admin/code-agents/apps/{{ $app->id }}/reveal">
      @csrf
      <div class="field"><label for="reveal-password-{{ $app->id }}">Admin password</label><input id="reveal-password-{{ $app->id }}" name="current_password" type="password" required autocomplete="current-password" /></div>
      @if(auth()->user()->two_factor_confirmed_at)
        <div class="field"><label for="reveal-code-{{ $app->id }}">2FA code</label><input id="reveal-code-{{ $app->id }}" name="code" type="text" required autocomplete="one-time-code" /></div>
      @endif
      <div class="form-actions"><button class="btn-primary-admin" type="submit">Reveal signing secret</button></div>
    </form>
    @else
      <p class="entity-meta">This application's secret is no longer usable. Delete the application to remove the stored credentials.</p>
    @endif
    <p class="entity-meta" data-app-feedback role="status" aria-live="polite"></p>
    @if($app->enabled)
    <details style="margin-top:10px">
      <summary>Revoke application access</summary>
      <form method="post" action="/admin/code-agents/apps/{{ $app->id }}">
        @csrf @method('DELETE')
        <div class="field"><label>Admin password</label><input type="password" name="current_password" required autocomplete="current-password" /></div>
        @if(auth()->user()->two_factor_confirmed_at)<div class="field"><label>2FA code</label><input name="code" required autocomplete="one-time-code" /></div>@endif
        <div class="form-actions"><button class="btn-primary-admin" type="submit">Revoke access</button></div>
      </form>
    </details>
    @endif
    <details style="margin-top:10px">
      <summary>Delete application permanently</summary>
      <form method="post" action="/admin/code-agents/apps/{{ $app->id }}/permanent" data-delete-app-form>
        @csrf @method('DELETE')
        <p class="entity-meta">Permanent deletion cannot be undone. This App ID and its signing secret will stop working immediately.</p>
        <div class="field"><label>Admin password</label><input type="password" name="current_password" required autocomplete="current-password" /></div>
        @if(auth()->user()->two_factor_confirmed_at)<div class="field"><label>2FA code</label><input name="code" required autocomplete="one-time-code" /></div>@endif
        <div class="form-actions"><button class="btn-primary-admin" type="submit">Delete permanently</button></div>
      </form>
    </details>
  </div>
  @empty <p>No first-party apps registered.</p> @endforelse
</section>
</div>
<script src="{{ asset('js/admin-first-party-apps.js') }}" defer></script>
<p class="entity-meta" style="margin-top:20px">Signed endpoints: <code>{{ $apiBase }}/first-party/models</code> and <code>{{ $apiBase }}/first-party/chat/completions</code>. Use HMAC-SHA256 over timestamp, nonce, HTTP method, full request path and SHA-256 body, joined by newlines. See docs/OPENMINDAI_GATEWAY.md.</p>
@endsection
