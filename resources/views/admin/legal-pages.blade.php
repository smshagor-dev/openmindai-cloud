@extends('admin.layout')
@section('title', 'Privacy and terms')
@section('section', 'Settings')
@section('content')
<div class="admin-page-heading"><div><span class="eyebrow">ADMINISTRATION</span><h1>Privacy and terms</h1><p>Review and edit the public legal pages. Saving publishes the changes immediately.</p></div></div>
@foreach($pages as $page)
    <form method="post" action="/admin/legal-pages/{{ $page->slug }}" class="card settings-layout">
        @csrf @method('PATCH')
        <div class="section-separator"><h2>{{ $page->title }}</h2><p>Public path /{{ $page->slug }}. Body supports Markdown.</p></div>
        <div class="field"><label for="{{ $page->slug }}-title">Page title</label><input id="{{ $page->slug }}-title" name="title" maxlength="120" required value="{{ old('_page') === $page->slug ? old('title') : $page->title }}"></div>
        <div class="field"><label for="{{ $page->slug }}-body">Page content</label><textarea id="{{ $page->slug }}-body" name="body" rows="16" maxlength="100000" required>{{ old('_page') === $page->slug ? old('body') : $page->body }}</textarea></div>
        <input type="hidden" name="_page" value="{{ $page->slug }}">
        <div class="field"><label for="{{ $page->slug }}-password">Current password</label><input id="{{ $page->slug }}-password" type="password" name="current_password" required autocomplete="current-password"></div>
        @if(auth()->user()->two_factor_confirmed_at)
            <div class="field"><label for="{{ $page->slug }}-code">Authenticator or recovery code</label><input id="{{ $page->slug }}-code" name="code" maxlength="64" required autocomplete="one-time-code"></div>
        @endif
        <div class="form-actions"><button class="btn-primary-admin" type="submit">Publish page</button></div>
    </form>
@endforeach
@endsection
