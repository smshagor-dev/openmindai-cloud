@extends('admin.layout')
@section('title', 'NVIDIA free models')
@section('section', 'NVIDIA models')
@section('content')
<div class="admin-page-heading"><div><span class="eyebrow">NVIDIA BUILD</span><h1>Free endpoint model catalog</h1><p>Configure the hosted model registry before exposing it to the frontend. Published model availability is not a guarantee of account access.</p></div></div>
<div class="card-grid" style="margin-bottom:22px">
 <div class="admin-card"><h2>{{ $total }}</h2><p class="entity-meta">Catalog models</p></div>
 <div class="admin-card"><h2>{{ $enabled }}</h2><p class="entity-meta">Active catalog models</p></div>
 <div class="admin-card"><h2>{{ $configured }}</h2><p class="entity-meta">Inference integrations configured</p></div>
</div>
<div class="muted-note" style="margin-bottom:22px"><strong>NVIDIA server credentials:</strong> {{ $nvidiaReady ? 'Configured' : 'Missing NVIDIA_API_KEY in Laravel .env' }}. All 42 models are permanently listed in the developer model catalog. Chat models use the shared chat completion route. Specialized models require their own NVIDIA API paths and schemas. Use the live test where supported to verify entitlement and server configuration. Free endpoints can still have rate limits, access restrictions and non-commercial trial conditions.</div>
<section class="admin-card" style="margin-bottom:22px">
 <h2>Frontend model API</h2>
 <div class="field"><label>Base URL</label><input readonly value="{{ $apiBase }}"></div>
 <p class="entity-meta"><code>GET /catalog</code> — all catalog models, categories and integration readiness. <code>GET /models</code> — every registered model with availability metadata. <code>POST /chat/completions</code> — chat inference. <code>POST /models/{slug}/invoke</code> — configured JSON-based specialized models.</p>
 <p class="entity-meta">These APIs require a developer Bearer token. Every registered model remains active and listed. The availability field only reflects whether an inference route is implemented; provider access is checked separately. Audio, video and long-running jobs have dedicated routes outside this JSON gateway.</p>
</section>
<form method="get" action="/admin/nvidia-models" class="admin-card admin-filter-form">
 <div class="admin-filter-fields">
  <div class="field"><label for="nvidia-q">Search models</label><input id="nvidia-q" name="q" value="{{ $search }}" placeholder="Model name or NVIDIA ID"></div>
  <div class="field"><label for="nvidia-category">Category</label><select id="nvidia-category" name="category"><option value="">All categories</option>@foreach($categories as $category)<option value="{{ $category }}" @selected($selectedCategory === $category)>{{ $category }}</option>@endforeach</select></div>
 </div>
 <div class="admin-filter-actions"><button type="submit" class="btn-primary-admin">Filter models</button><a class="admin-reset-filter" href="/admin/nvidia-models">Reset filters</a></div>
</form>
@forelse($grouped as $category => $models)
<div class="section-separator"><h2>{{ $category }}</h2><p>{{ $models->count() }} model(s)</p></div>
<div class="card-grid" style="margin-bottom:26px">
@foreach($models as $model)
<section class="admin-card">
 <div class="entity-card-header"><div><h2 style="word-break:break-word">{{ $model->name }}</h2><p class="entity-meta">{{ $model->slug === 'nvidia-nemotron-voicechat' ? 'Realtime VoiceChat WebSocket' : ($model->slug === 'nvidia-magpie-tts-zeroshot' ? 'Queued Magpie speech synthesis' : ($model->slug === 'nvidia-cosmos-transfer2.5-2b' ? 'Controlled video NIM generation' : (isset(config('nvidia_av.routes', [])[$model->slug]) ? 'Self-hosted AV NIM scene processing' : ($model->slug === 'nvidia-streampetr' ? 'Queued AV scene inference' : (isset(config('maxine.models', [])[$model->slug]) ? 'Queued gRPC media processing' : ($model->slug === 'nvidia-cosmos3-nano' ? 'Queued image/video generation' : ($model->api_protocol === 'chat' ? 'Chat completions' : 'Specialized JSON request'))))))) }}</p></div><span class="status-pill {{ $model->enabled ? 'success' : 'warning' }}">{{ $model->enabled ? 'Active' : 'Inactive' }}</span></div>
 <p class="entity-meta">{{ $model->summary }}</p>
 <p class="entity-meta">Inference: <strong>{{ $model->api_ready ? 'Route configured' : 'Provider access or additional service required' }}</strong> · NVIDIA live test: <strong>{{ $model->last_test_status === 'success' ? 'Passed' : ($model->last_test_status ? ucfirst(str_replace('_', ' ', $model->last_test_status)) : 'Not yet run') }}</strong></p>
 @php($runtime = app(\App\Services\HostedModelRuntime::class)->inspect($model->slug))
 @if(!$runtime['configured'])
 <p class="entity-meta">Runtime setup required: {{ implode(', ', $runtime['missing_settings']) }}. The API route exists; inference cannot run until these services are configured.</p>
 @endif
 <p class="entity-meta"><a href="{{ $model->catalog_url }}" target="_blank" rel="noopener noreferrer">NVIDIA model card</a> · Free Endpoint (catalog)</p>
 @if(isset($specialistModels[$model->slug]))
 <div class="muted-note" style="margin:12px 0">
   <strong>Integration needed:</strong> {{ $specialistModels[$model->slug]['requirement'] }}
   <p class="entity-meta">{{ $specialistModels[$model->slug]['note'] }}</p>
   <p class="entity-meta">Protocol: <code>{{ $specialistModels[$model->slug]['transport'] }}</code> · <a href="{{ $specialistModels[$model->slug]['reference'] }}" target="_blank" rel="noopener noreferrer">Official NVIDIA reference</a></p>
   <p class="entity-meta">Active in catalog. This model's dedicated transport and provider entitlement are checked independently.</p>
 </div>
 @endif
 <form method="post" action="/admin/nvidia-models/{{ $model->slug }}">
  @csrf @method('PATCH')
  <div class="field"><label for="name-{{ $model->id }}">Display name</label><input id="name-{{ $model->id }}" name="name" value="{{ $model->name }}" required maxlength="120"></div>
  <div class="field"><label for="provider-{{ $model->id }}">NVIDIA API model ID</label><input id="provider-{{ $model->id }}" name="provider_model" value="{{ $model->provider_model }}" required maxlength="190"></div>
  <div class="field"><label for="origin-{{ $model->id }}">Official NVIDIA API host</label><select id="origin-{{ $model->id }}" name="api_origin">
    @foreach(['integrate.api.nvidia.com', 'ai.api.nvidia.com', 'health.api.nvidia.com'] as $origin)
      <option value="{{ $origin }}" @selected($model->api_origin === $origin)>{{ $origin }}</option>
    @endforeach
  </select></div>
  <div class="field"><label for="path-{{ $model->id }}">Verified NVIDIA API path</label><input id="path-{{ $model->id }}" name="api_path" value="{{ $model->api_path }}" placeholder="/v1/..." maxlength="190"><small>Only NVIDIA-owned HTTPS hosts are permitted.</small></div>
  <div class="field"><label for="protocol-{{ $model->id }}">API protocol</label><select id="protocol-{{ $model->id }}" name="api_protocol"><option value="chat" @selected($model->api_protocol === 'chat')>Chat completions</option><option value="json" @selected($model->api_protocol === 'json')>Specialized JSON</option></select></div>
  <div class="field"><label for="ready-{{ $model->id }}">Backend inference route</label><select id="ready-{{ $model->id }}" name="api_ready"><option value="0" @selected(!$model->api_ready)>Route not configured</option><option value="1" @selected($model->api_ready)>Route configured</option></select><small>This setting never marks an NVIDIA live test as passed.</small></div>
  <div class="field"><label for="model-field-{{ $model->id }}">Add model field to requests</label><select id="model-field-{{ $model->id }}" name="include_model"><option value="1" @selected($model->include_model)>Yes</option><option value="0" @selected(!$model->include_model)>No</option></select></div>
  <p class="entity-meta">Always visible to authenticated API clients. Provider-side access is verified separately.</p>
  @if($model->slug === 'nvidia-nemotron-voicechat')
    <p class="entity-meta">Secure realtime audio: <code>POST /api/v1/openmindai/voicechat/sessions</code> returns a one-time, user-scoped WSS ticket. Requires an approved GPU VoiceChat NIM and a separately deployed streaming relay; no global signing key is installed on desktops.</p>
  @endif
  @if($model->slug === 'nvidia-magpie-tts-zeroshot')
    <p class="entity-meta">Speech NIM: <code>POST /api/v1/openmindai/tts/generations</code>, then poll the status and download a private WAV. Requires an approved, operator-hosted Magpie TTS NIM; reference voices require consent.</p>
  @endif
  @if($model->slug === 'nvidia-cosmos-transfer2.5-2b')
    <p class="entity-meta">Upload a video + control_video via <code>POST /api/v1/openmindai/transfer/generations</code>. Requires a dedicated GPU-backed Cosmos Transfer NIM with a trusted private endpoint.</p>
  @endif
  @if(isset(config('nvidia_av.routes', [])[$model->slug]))
    <p class="entity-meta">Self-hosted GPU NIM route: <code>POST /api/v1/openmindai/av-nim/{{ $model->slug }}/jobs</code>, then status and private JSON download. Requires operator-managed NVIDIA_AV_NIM_URL; not a public NVIDIA Build endpoint.</p>
  @endif
  @if($model->slug === 'nvidia-streampetr')
    <p class="entity-meta">Scene API: <code>POST /api/v1/openmindai/av/streampetr/jobs</code>, then poll and download a private ZIP. Accepts `scene-0103` and other `scene-####` identifiers. Requires NVIDIA provider access and queue worker.</p>
  @endif
  @if(isset(config('maxine.models', [])[$model->slug]))
    <p class="entity-meta">Dedicated gRPC inference: <code>POST /api/v1/openmindai/maxine/{{ $model->slug }}/jobs</code> (multipart file) → status → private download. Requires the separately deployed Maxine worker and NVIDIA account access.</p>
  @endif
  @if($model->slug === 'nvidia-cosmos3-nano')
    <p class="entity-meta">Use <code>POST /api/v1/openmindai/cosmos/generations</code>, then poll the returned status path. Requires database queue worker and private storage. No automatic paid inference test is run.</p>
  @endif
  <div class="form-actions"><button type="submit" class="btn-primary-admin">Save model</button></div>
 </form>
 @if($model->last_test_status)
 <p class="entity-meta">Last NVIDIA test: {{ $model->last_test_status }} · HTTP {{ $model->last_test_http_status ?? 'unreachable' }} · {{ $model->last_tested_at }}</p>
 @endif
 @if($model->api_ready && ($model->api_protocol === 'chat' || $model->api_path === '/v1/embeddings' || $model->slug === 'nvidia-nemotron-3.5-rerank-8b'))
 <details style="margin-top:10px">
 <summary>Test live NVIDIA inference</summary>
 <form action="/admin/nvidia-models/{{ $model->slug }}/test" method="post">
 @csrf
 <div class="field"><label>Admin password</label><input name="current_password" type="password" required autocomplete="current-password"></div>
 @if(auth()->user()->two_factor_confirmed_at)
 <div class="field"><label>2FA code</label><input name="code" required autocomplete="one-time-code"></div>
 @endif
 <div class="form-actions"><button type="submit" class="btn-primary-admin">Run NVIDIA test</button></div>
 </form><p class="entity-meta">Small test requests may count toward NVIDIA usage limits.</p>
 </details>
 @endif
</section>
@endforeach
</div>
@empty
<section class="admin-card"><p>No models match your search.</p></section>
@endforelse
@endsection
