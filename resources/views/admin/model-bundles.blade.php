@extends('admin.layout')
@section('title', 'Media model snapshots')
@section('section', 'Media models')
@section('content')
<div class="admin-page-heading"><div><span class="eyebrow">AI OPERATIONS</span><h1>Media model snapshots</h1><p>Import revision-pinned model files for image, vision, transcription, speech and video runtimes.</p></div><div class="heading-actions"><a href="/admin/models" class="subtle-label">← Chat model library</a></div></div>
<div class="muted-note" style="margin-bottom:21px"><strong>Runtime compatibility matters.</strong> Verified safetensors and configuration files can be downloaded. Python and pickle weights are excluded. A downloaded snapshot does not install an inference engine or guarantee suitable hardware.</div>
<form class="admin-card admin-discovery-form" method="get" action="/admin/model-bundles/inspect">
    <div class="section-separator"><h2>Inspect Hugging Face snapshot</h2><p>Choose a repository and the intended runtime purpose.</p></div>
    <div class="form-fields"><div class="field"><label for="bundle_repo">Hugging Face repository</label><input id="bundle_repo" name="repository" placeholder="owner/repository" required maxlength="160"></div>
    <div class="field"><label for="bundle_purpose">Runtime purpose</label><select id="bundle_purpose" name="purpose">@foreach(['image','vision','transcription','speech','video'] as $kind)<option value="{{ $kind }}">{{ ucfirst($kind) }}</option>@endforeach</select></div></div>
    <div class="form-actions"><button class="btn-primary-admin" type="submit">Inspect snapshot →</button></div>
</form>
<div class="section-separator"><h2>Imported snapshots</h2><p>Stored models and their verified download status.</p></div>
<div class="entity-list">
    @forelse($bundles as $bundle)
    <section class="admin-card entity-card">
        <div class="entity-card-header"><div><h2>{{ $bundle->name }}</h2><p class="entity-meta">{{ $bundle->repository }}</p></div><div class="badge-set"><span class="badge">{{ ucfirst($bundle->purpose) }}</span><span class="status-pill {{ $bundle->status === 'ready' ? 'success' : ($bundle->status === 'failed' ? 'danger' : 'warning') }}">{{ ucfirst($bundle->status) }}</span></div></div>
        <p class="entity-meta">{{ count($bundle->files) }} verified files · {{ number_format($bundle->bytes / 1073741824, 2) }} GiB · {{ $bundle->license }} · Revision <code>{{ $bundle->revision }}</code></p>
        @if($bundle->download_error)<p class="error-message" role="alert">{{ $bundle->download_error }}</p>@endif
        @if(in_array($bundle->status, ['available', 'failed']))<form method="post" action="/admin/model-bundles/{{ $bundle->id }}/download">@csrf<button type="submit" class="btn-primary-admin">Download snapshot</button></form>@endif
        @if($bundle->status === 'ready')<div class="muted-note"><strong>Private runtime setting</strong><div><code>MEDIA_{{ strtoupper($bundle->purpose) }}_PATH={{ storage_path('app/models/bundles/'.$bundle->id) }}</code></div><div>If the runtime is on another machine, transfer the complete folder and configure that machine's local path.</div></div>@endif
    </section>
    @empty<div class="empty-block">No media model snapshots imported yet.</div>@endforelse
</div>
<div class="pagination-wrap">{{ $bundles->links() }}</div>
@endsection
