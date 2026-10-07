@extends('layouts.app')
@section('title','Users & Permissions')
@section('topbar','Users & Permissions')
@section('content')

<div class="page-head">
    <div>
        <span class="eyebrow">ACCESS CONTROL</span>
        <h2>Users & Permissions</h2>
        <p>Employees can be assigned to one or multiple brands. Admins remain platform-wide. Correction permissions are request-only and individually controlled.</p>
    </div>
</div>

<div class="grid grid-2">
    <section class="card elevated">
        <div class="card-title-row">
            <div><span class="eyebrow">CREATE ACCESS</span><h3>New User</h3></div>
            <span class="icon-bubble">＋</span>
        </div>

        <form method="post" action="{{ route('admin.employees.store') }}" class="stack">
            @csrf

            <div class="form-grid">
                <div class="field"><label>Full name</label><input class="input" name="name" value="{{ old('name') }}" required></div>
                <div class="field"><label>Username</label><input class="input" name="username" value="{{ old('username') }}" required></div>
                <div class="field"><label>Password</label><input class="input" type="password" name="password" required></div>
                <div class="field">
                    <label>Role</label>
                    <select class="select" name="role">
                        <option value="employee" @selected(old('role','employee')==='employee')>Employee</option>
                        <option value="admin" @selected(old('role')==='admin')>Admin</option>
                    </select>
                </div>
            </div>

            <div class="permission-panel">
                <div class="panel-label">BRAND ACCESS</div>
                <div class="checkbox-card-grid">
                    @foreach($brands as $brand)
                        <label class="checkbox-card">
                            <input type="checkbox" name="brand_ids[]" value="{{ $brand->id }}" @checked(in_array($brand->id,old('brand_ids',[])))>
                            <span class="checkbox-mark">✓</span>
                            <span><b>{{ $brand->name }}</b><small>Allow Employee transactions for this brand</small></span>
                        </label>
                    @endforeach
                </div>
                <div class="tiny muted" style="margin-top:8px">Employees require at least one active brand. Admin users automatically have access to all brands.</div>
            </div>

            <div class="permission-panel">
                <div class="panel-label">CORRECTION REQUEST PERMISSIONS</div>
                <div class="checkbox-card-grid">
                    @foreach($correctionFields as $field)
                        <label class="checkbox-card compact">
                            <input type="checkbox" name="correction_fields[]" value="{{ $field }}">
                            <span class="checkbox-mark">✓</span>
                            <span><b>{{ str($field)->replace('_',' ')->title() }}</b></span>
                        </label>
                    @endforeach
                </div>
                <div class="alert info" style="margin-top:10px;margin-bottom:0">These permissions only allow an Employee to <b>request</b> a correction. Admin approval is still required. Receiver bank/account/name and duplicate protection remain non-correctable.</div>
            </div>

            <button class="btn btn-primary btn-lg">Create User</button>
        </form>
    </section>

    <section class="card">
        <div class="card-title-row">
            <div><span class="eyebrow">DIRECTORY</span><h3>Existing Users</h3></div>
            <span class="count-pill">{{ $users->total() }}</span>
        </div>

        <div class="user-admin-list">
            @forelse($users as $user)
                <details class="user-admin-card">
                    <summary>
                        <span class="entity-avatar">{{ strtoupper(substr($user->name,0,1)) }}</span>
                        <span class="user-summary-copy">
                            <b>{{ $user->name }}</b>
                            <small>{{ $user->username }} · {{ ucfirst($user->role->value) }}</small>
                            <span class="user-brand-line">
                                @if($user->isAdmin())
                                    All brands
                                @else
                                    {{ $user->brands->pluck('name')->join(', ') ?: 'No brand assigned' }}
                                @endif
                            </span>
                        </span>
                        <span class="status-pill {{ $user->is_active?'success':'danger' }}">{{ $user->is_active?'Active':'Disabled' }}</span>
                        <span class="details-chevron">⌄</span>
                    </summary>

                    <form method="post" action="{{ route('admin.employees.update',$user) }}" class="stack user-edit-form">
                        @csrf
                        @method('PUT')

                        <div class="form-grid">
                            <div class="field"><label>Name</label><input class="input" name="name" value="{{ $user->name }}"></div>
                            <div class="field"><label>Username</label><input class="input" name="username" value="{{ $user->username }}"></div>
                            <div class="field">
                                <label>Role</label>
                                <select class="select" name="role">
                                    <option value="employee" @selected($user->role->value==='employee')>Employee</option>
                                    <option value="admin" @selected($user->role->value==='admin')>Admin</option>
                                </select>
                            </div>
                            <div class="field"><label>New password</label><input class="input" type="password" name="password" placeholder="Leave blank to keep current"></div>
                        </div>

                        <div class="permission-panel">
                            <div class="panel-label">ASSIGNED BRANDS</div>
                            <div class="checkbox-card-grid">
                                @foreach($brands as $brand)
                                    <label class="checkbox-card compact">
                                        <input type="checkbox" name="brand_ids[]" value="{{ $brand->id }}" @checked($user->brands->contains('id',$brand->id))>
                                        <span class="checkbox-mark">✓</span>
                                        <span><b>{{ $brand->name }}</b></span>
                                    </label>
                                @endforeach
                            </div>
                        </div>

                        <div class="permission-panel">
                            <div class="panel-label">CORRECTION REQUESTS</div>
                            <div class="checkbox-card-grid">
                                @foreach($correctionFields as $field)
                                    <label class="checkbox-card compact">
                                        <input type="checkbox" name="correction_fields[]" value="{{ $field }}" @checked(in_array($field,$user->permissions?->correction_fields??[],true))>
                                        <span class="checkbox-mark">✓</span>
                                        <span><b>{{ str($field)->replace('_',' ')->title() }}</b></span>
                                    </label>
                                @endforeach
                            </div>
                        </div>

                        <label class="setting-row">
                            <span><b>User active</b><small>Disabled users cannot sign in or use remembered-device sessions.</small></span>
                            <span class="modern-switch compact"><input type="checkbox" name="is_active" value="1" @checked($user->is_active)><span class="modern-switch-ui"></span></span>
                        </label>

                        <button class="btn btn-primary">Save User</button>
                    </form>
                </details>
            @empty
                <div class="empty">No users found.</div>
            @endforelse
        </div>

        {{ $users->links() }}
    </section>
</div>
@endsection
