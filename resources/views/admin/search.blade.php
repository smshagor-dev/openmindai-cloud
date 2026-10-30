@extends('admin.layout')
@section('title', 'Find models')
@section('section', 'Chat models')
@section('content')
<div class="admin-page-heading"><div><span class="eyebrow">CHAT MODELS / DISCOVERY</span><h1>Search results</h1><p>GGUF repositories matching <strong>{{ $query }}</strong>, sorted by downloads. Review model compatibility and license before importing.</p></div><div class="heading-actions"><a class="subtle-label" href="/admin/models">← Model library</a></div></div>
<form class="admin-card admin-filter-form" method="get" action="/admin/models/search">
    <div class="admin-filter-fields"><div class="field"><label for="model_search_again">Search Hugging Face</label><input id="model_search_again" name="q" value="{{ $query }}" required maxlength="100"></div></div>
    <div class="admin-filter-actions"><button type="submit" class="btn-primary-admin">Search again</button><a class="admin-reset-filter" href="/admin/models">Back to models</a></div>
</form>
<div class="entity-list">
    @forelse($results as $repo)
    <article class="admin-card entity-card">
        <div class="entity-card-header"><div><h2>{{ $repo['id'] }}</h2><p class="entity-meta">GGUF repository on Hugging Face</p></div><div class="badge-set"><span class="badge">{{ number_format($repo['downloads'] ?? 0) }} downloads</span><span class="badge">{{ number_format($repo['likes'] ?? 0) }} likes</span></div></div>
        <div><a class="pill-link" href="/admin/models/inspect?repository={{ urlencode($repo['id']) }}">Browse model files →</a></div>
    </article>
    @empty<div class="empty-block">No matching GGUF repositories. Try a different family or model name.</div>@endforelse
</div>
@endsection
