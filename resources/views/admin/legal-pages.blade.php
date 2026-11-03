@extends('admin.layout')
@section('title', 'Privacy and terms')
@section('section', 'Settings')
@if(config('admin.ckeditor_license_key'))
    @push('styles')
        <link rel="stylesheet" href="https://cdn.ckeditor.com/ckeditor5/49.0.1/ckeditor5.css" integrity="sha384-3n5XQO/oZlZCecxCSjLWbOTs85qieOb432CEA4p5sAeuTdgJYuLu7OZKqS5B8Ms2" crossorigin="anonymous">
    @endpush
    @push('scripts')
        <script src="https://cdn.ckeditor.com/ckeditor5/49.0.1/ckeditor5.umd.js" integrity="sha384-F+rL02GCKIGyPPIPxR157lnU3k9VpQLa9LNdHkzBRuRB8tbfw0bqO45S5xWoYvcb" crossorigin="anonymous" defer></script>
        <script src="{{ asset('js/admin-legal-editor.js') }}" defer></script>
    @endpush
@endif
@section('content')
<div class="admin-page-heading"><div><span class="eyebrow">ADMINISTRATION</span><h1>Privacy and terms</h1><p>Review and edit the public legal pages. Saving publishes the changes immediately.</p></div></div>
@unless(config('admin.ckeditor_license_key'))
    <p class="field-help">Markdown editing is available. Configure the CKEditor license key to enable the visual toolbar.</p>
@endunless
@foreach($pages as $page)
    <form method="post" action="/admin/legal-pages/{{ $page->slug }}" class="card settings-layout" data-legal-editor="{{ config('admin.ckeditor_license_key') }}">
        @csrf @method('PATCH')
        <div class="section-separator"><h2>{{ $page->title }}</h2><p>Public path /{{ $page->slug }}. Use the formatting toolbar or edit the Markdown source.</p></div>
        <div class="field"><label for="{{ $page->slug }}-title">Page title</label><input id="{{ $page->slug }}-title" name="title" maxlength="120" required value="{{ old('_page') === $page->slug ? old('title') : $page->title }}"></div>
        <div class="field"><label for="{{ $page->slug }}-body">Page content</label><textarea id="{{ $page->slug }}-body" name="body" data-legal-body rows="16" maxlength="100000" required>{{ old('_page') === $page->slug ? old('body') : $page->body }}</textarea></div>
        <p class="field-help" data-legal-editor-fallback hidden>Visual editing is unavailable. You can still edit the Markdown below the page title.</p>
        <input type="hidden" name="_page" value="{{ $page->slug }}">
        <div class="field"><label for="{{ $page->slug }}-password">Current password</label><input id="{{ $page->slug }}-password" type="password" name="current_password" required autocomplete="current-password"></div>
        @if(auth()->user()->two_factor_confirmed_at)
            <div class="field"><label for="{{ $page->slug }}-code">Authenticator or recovery code</label><input id="{{ $page->slug }}-code" name="code" maxlength="64" required autocomplete="one-time-code"></div>
        @endif
        <div class="form-actions"><button class="btn-primary-admin" type="submit">Publish page</button></div>
    </form>
@endforeach
@endsection
