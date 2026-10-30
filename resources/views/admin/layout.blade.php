<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light">
<title>@yield('title', 'Administration') · {{ app(\App\Services\AdminSettings::class)->get('site_name') }}</title>
<link rel="icon" href="{{ asset('openmindai-logo.png') }}">
{{-- Tabler UI is MIT licensed; admin.css provides local styling if CDN is unavailable. --}}
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/core@1.6.1/dist/css/tabler.min.css">
<link rel="stylesheet" href="{{ asset('css/admin.css') }}?v=20261021-admin-forms">
</head>
<body class="admin-shell">
@php
$navigation = [
    ['Overview', '/admin', 'dashboard', request()->is('admin')],
    ['Users', '/admin/users', 'people', request()->is('admin/users*')],
    ['Administrators', '/admin/administrators/create', 'shield', request()->is('admin/administrators*')],
    ['Chat models', '/admin/models', 'chip', request()->is('admin/models*')],
    ['Code agents', '/admin/code-agents', 'chip', request()->is('admin/code-agents*')],
    ['NVIDIA models', '/admin/nvidia-models', 'layers', request()->is('admin/nvidia-models*')],
    ['Media models', '/admin/model-bundles', 'layers', request()->is('admin/model-bundles*')],
    ['API credits', '/admin/api-credits', 'credit', request()->is('admin/api-credits*')],
    ['Public assistants', '/admin/assistants', 'spark', request()->is('admin/assistants*')],
    ['Workspace settings', '/admin/settings', 'settings', request()->is('admin/settings*')],
    ['SMTP settings', '/admin/smtp-settings', 'mail', request()->is('admin/smtp-settings*')],
    ['My profile', '/admin/profile', 'profile', request()->is('admin/profile*')],
];
@endphp
<aside class="admin-sidebar" id="admin-sidebar" aria-label="Administration menu">
    <a class="admin-brand" href="/admin"><img src="{{ asset('openmindai-logo.png') }}" width="42" height="42" alt=""><span><strong>{{ app(\App\Services\AdminSettings::class)->get('site_name') }}</strong><small>MANAGEMENT CONSOLE</small></span></a>
    <nav class="admin-menu" aria-label="Main navigation">
        @foreach($navigation as $item)
            @if($loop->index === 0)<div class="nav-section">WORKSPACE</div>@endif
            @if($loop->index === 3)<div class="nav-section">AI OPERATIONS</div>@endif
            @if($loop->index === 9)<div class="nav-section">ADMINISTRATION</div>@endif
            <a href="{{ $item[1] }}" @class(['admin-nav-item', 'selected' => $item[3]]) @if($item[3]) aria-current="page" @endif>
            <svg viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" aria-hidden="true">
                @switch($item[2])
                    @case('dashboard')<rect x="3" y="3" width="8" height="8" rx="2"/><rect x="13" y="3" width="8" height="5" rx="2"/><rect x="13" y="10" width="8" height="11" rx="2"/><rect x="3" y="13" width="8" height="8" rx="2"/>@break
                    @case('people')<circle cx="9" cy="8" r="3"/><path d="M3 20v-2a6 6 0 0 1 12 0v2M17 5a3 3 0 0 1 0 6m1 4a5 5 0 0 1 3 5"/>@break
                    @case('shield')<path d="m12 2 8 4v6c0 5-3 8-8 10-5-2-8-5-8-10V6z"/><path d="m9 12 2 2 4-4"/>@break
                    @case('chip')<rect x="5" y="5" width="14" height="14" rx="3"/><path d="M9 9h6v6H9zM9 2v3m6-3v3m-6 14v3m6-3v3M2 9h3m-3 6h3m14-6h3m-3 6h3"/>@break
                    @case('credit')<circle cx="12" cy="12" r="9"/><path d="M12 3v9l7 5"/>@break
                    @case('layers')<path d="m12 3 9 5-9 5-9-5zM3 12l9 5 9-5M3 16l9 5 9-5"/>@break
                    @case('spark')<path d="m12 2 2.5 7.5L22 12l-7.5 2.5L12 22l-2.5-7.5L2 12l7.5-2.5z"/>@break
                    @case('settings')<circle cx="12" cy="12" r="3"/><path d="M4 11V9l3-1 1-3 3-1 1-2 2 2 3 1 1 3 3 1v4l-3 1-1 3-3 1-2 2-2-2-3-1-1-3-2-1z"/>@break
                    @case('mail')<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 7 9-7"/>@break
                    @case('profile')<circle cx="12" cy="8" r="4"/><path d="M4 21v-2a8 8 0 0 1 16 0v2"/>@break
                @endswitch
            </svg>
            <span>{{ $item[0] }}</span>@if($item[3])<span class="selected-dot"></span>@endif</a>
        @endforeach
    </nav>
    <div class="admin-sidebar-footer">
        <div class="sidebar-status"><span class="online-dot"></span> Admin console online</div>
        <form action="/admin/logout" method="post">@csrf<button type="submit" class="signout">Sign out <span>↗</span></button></form>
    </div>
</aside>
<button class="sidebar-overlay" type="button" data-close-sidebar aria-label="Close menu"></button>
<div class="admin-workspace">
    <header class="admin-topbar">
        <button class="mobile-menu" type="button" data-open-sidebar aria-label="Open menu" aria-controls="admin-sidebar" aria-expanded="false">☰</button>
        <div class="breadcrumb-admin">Workspace <span>/</span> <strong>@yield('section', 'Overview')</strong></div>
        <div class="topbar-right"><span class="topbar-status"><span class="online-dot"></span> Live platform</span>
            <a class="topbar-account" href="/admin/profile">@if(auth()->user()->avatar)<img src="{{ auth()->user()->avatar }}" alt="" class="admin-avatar">@else<span class="avatar-initial">{{ strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}</span>@endif <span>{{ auth()->user()->name }}</span></a>
        </div>
    </header>
    <main class="admin-main" id="main-content">
        @if(session('status'))<div class="notice" role="status">{{ session('status') }}</div>@endif
        @if($errors->any())<div class="notice error" role="alert">{{ $errors->first() }}</div>@endif
        @yield('content')
    </main>
    <footer class="admin-footer">{{ app(\App\Services\AdminSettings::class)->get('site_name') }} · Administration <span>Secure workspace</span></footer>
</div>
<script src="{{ asset('js/admin-photo.js') }}" defer></script>
<script src="{{ asset('js/admin-console.js') }}" defer></script>
<script src="{{ asset('js/password-visibility.js') }}" defer></script>
</body>
</html>
