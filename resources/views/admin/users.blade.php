@extends('admin.layout')
@section('title', 'Users')
@section('section', 'Users')
@section('content')
<div class="admin-page-heading">
    <div><span class="eyebrow">USER MANAGEMENT</span><h1>Users</h1><p>Find, review and manage registered accounts and their access.</p></div>
    <div class="heading-actions"><a href="/admin/administrators/create" class="btn-primary-admin">+ Create administrator</a></div>
</div>
<form method="get" action="/admin/users" class="admin-card admin-filter-form">
        <div class="admin-filter-fields">
            <div class="field"><label for="user_search">Search users</label><input id="user_search" name="q" value="{{ request('q') }}" placeholder="Search by name or email" autocomplete="off"></div>
        </div>
        <div class="admin-filter-actions"><button type="submit" class="btn-primary-admin">Search accounts</button>
            @if(request()->filled('q'))<a class="admin-reset-filter" href="/admin/users">Clear search</a>@endif
        </div>
    </form>
<section class="admin-card table-card">
    <div class="table-heading panel-heading"><div><h2>Registered accounts</h2><p>Manage users or review administrator accounts.</p></div><a href="/admin/profile" class="pill-link">My profile →</a></div>
    <div class="table-wrap"><table class="admin-table"><thead><tr><th>Account</th><th>Email</th><th>Status</th><th>Role</th><th>Security</th><th></th></tr></thead>
        <tbody>@forelse($users as $u)
        <tr><td><div class="inline-controls">@if($u->avatar)<img class="admin-avatar" src="{{ $u->avatar }}" alt="">@else<span class="avatar-initial">{{ strtoupper(mb_substr($u->name, 0, 1)) }}</span>@endif<strong>{{ $u->name }}</strong></div></td><td>{{ $u->email }}</td>
            <td><span class="status-pill {{ $u->is_active ? 'success' : 'warning' }}">{{ $u->is_active ? 'Active' : 'Suspended' }}</span></td>
            <td>@if($u->is_admin)<span class="status-pill">Administrator</span>@else<span>Member</span>@endif</td>
            <td>{{ $u->two_factor_confirmed_at ? '2FA enabled' : '2FA off' }} · {{ $u->email_verified_at ? 'Verified' : 'Unverified' }}</td><td><a href="/admin/users/{{ $u->id }}">View →</a></td></tr>
        @empty<tr><td class="empty-state" colspan="6">No matching accounts found.</td></tr>@endforelse</tbody></table></div>
    <div class="pagination-wrap">{{ $users->links() }}</div>
</section>
@endsection
