@extends('admin.layout')
@section('title', 'Model library')
@section('section', 'Chat models')
@section('content')
<div class="muted-note" style="margin-bottom:18px">Looking for NVIDIA-backed coding models? <a href="/admin/code-agents"><strong>Manage OpenMindAI Super, Ultra and Lite →</strong></a></div>
<div class="admin-page-heading"><div><span class="eyebrow">AI OPERATIONS</span><h1>Chat model library</h1><p>Discover, import and configure verified GGUF weights for the chat runtime.</p></div><div class="heading-actions"><a class="subtle-label" href="/admin/model-bundles">Media model snapshots →</a></div></div>
<div class="muted-note" style="margin-bottom:21px"><strong>Downloads and inference are separate.</strong> Downloading stores model weights privately; a compatible inference server must load them. Review the model card and license before importing.</div>
<div class="card-grid" style="margin-bottom:23px">
    <form class="admin-card admin-discovery-form" method="get" action="/admin/models/search">
        <div class="section-separator"><h2>Discover models</h2><p>Search GGUF repositories on Hugging Face.</p></div>
        <div class="field"><label for="model_query">Search Hugging Face</label><input id="model_query" name="q" placeholder="Nemotron or Qwen3" required maxlength="100"></div>
        <div class="form-actions"><button class="btn-primary-admin" type="submit">Search GGUF models →</button></div>
        <div class="status-group" style="margin-top:17px">@foreach(['Nemotron','Qwen3','Llama','Mistral','Gemma','DeepSeek'] as $family)<a class="badge" href="/admin/models/search?q={{ urlencode($family) }}">{{ $family }}</a>@endforeach</div>
    </form>
    <form class="admin-card admin-discovery-form" method="get" action="/admin/models/inspect">
        <div class="section-separator"><h2>Browse a repository</h2><p>Already have a model repository? Inspect its GGUF files.</p></div>
        <div class="field"><label for="model_repository">Repository ID</label><input id="model_repository" name="repository" placeholder="owner/model-GGUF" required maxlength="160" pattern="[A-Za-z0-9_-]+/[A-Za-z0-9_.-]+"></div>
        <div class="form-actions"><button class="btn-primary-admin" type="submit">Browse files →</button></div>
    </form>
</div>
<div class="section-separator"><h2>Imported models</h2><p>Configure runtime access after downloads are complete.</p></div>
<div class="entity-list">
    @forelse($models as $m)
    <section class="admin-card entity-card">
        <div class="entity-card-header"><div><h2>{{ $m->name }}</h2><p class="entity-meta"><code>{{ $m->repository }} / {{ $m->filename }}</code></p></div>
        <div class="badge-set"><span class="badge">{{ $m->family }}</span><span class="status-pill {{ $m->status === 'ready' ? 'success' : ($m->status === 'failed' ? 'danger' : 'warning') }}">{{ ucfirst($m->status) }}</span></div></div>
        <p class="entity-meta">{{ number_format($m->bytes / 1073741824, 2) }} GiB · {{ $m->license }} · Revision <code>{{ $m->revision }}</code></p>
        @if($m->download_error)<p class="error-message" role="alert">{{ $m->download_error }}</p>@endif
        @if(in_array($m->status, ['available', 'failed']))
        <form method="post" action="/admin/models/{{ $m->id }}/download">@csrf<button class="btn-primary-admin" type="submit">Download model</button></form>
        @endif
        @if($m->status === 'ready')
        <form method="post" action="/admin/models/{{ $m->id }}">
            @csrf @method('PATCH')
            <div class="form-fields"><div class="field"><label for="runtime_{{ $m->id }}">Inference server model ID</label><input id="runtime_{{ $m->id }}" name="runtime_model" value="{{ $m->runtime_model }}" maxlength="160" placeholder="Model ID exposed by your runtime"></div>
            <div class="field"><label for="enabled_{{ $m->id }}">Chat API availability</label><select id="enabled_{{ $m->id }}" name="enabled"><option value="0" @selected(! $m->enabled)>Disabled</option><option value="1" @selected($m->enabled)>Available in chat API</option></select></div></div>
            <div class="form-actions"><button class="btn-primary-admin" type="submit">Save runtime mapping</button><span class="subtle-info">Stored privately under storage/app/models/{{ $m->id }}.gguf</span></div>
        </form>
        @endif
    </section>
    @empty<div class="empty-block">No models imported yet. Search for a GGUF model or browse a repository to begin.</div>@endforelse
</div>
<div class="pagination-wrap">{{ $models->links() }}</div>
@endsection
