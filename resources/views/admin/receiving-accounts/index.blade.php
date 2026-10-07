@extends('layouts.app')
@section('title','Receiving Accounts')
@section('topbar','Receiving Accounts')
@section('content')

<div class="page-head">
    <div>
        <span class="eyebrow">HARD VALIDATION</span>
        <h2>Approved Receiving Accounts</h2>
        <p>A receiving account can serve one or multiple brands. Any screenshot that does not uniquely match an approved account is rejected or requires clearer evidence.</p>
    </div>
</div>

<section class="card elevated">
    <div class="card-title-row"><div><span class="eyebrow">ADD APPROVED DESTINATION</span><h3>New Receiving Account</h3></div><span class="icon-bubble">▤</span></div>
    <form method="post" action="{{ route('admin.receiving-accounts.store') }}" class="stack">
        @csrf
        <div class="form-grid">
            <div class="field"><label>Bank / provider</label><select class="select" name="bank_id" required>@foreach($banks as $bank)<option value="{{ $bank->id }}">{{ $bank->name }}</option>@endforeach</select></div>
            <div class="field"><label>Full account / phone number</label><input class="input" name="account_number" required></div>
            <div class="field"><label>Account holder full name</label><input class="input" name="account_name" required></div>
            <div class="field"><label>Name aliases</label><input class="input" name="name_aliases" placeholder="ABC Trading PLC, ABC Trading P.L.C."></div>
        </div>
        <div class="permission-panel">
            <div class="panel-label">APPROVED BRANDS</div>
            <div class="checkbox-card-grid">
                @foreach($brands as $brand)
                    <label class="checkbox-card compact"><input type="checkbox" name="brand_ids[]" value="{{ $brand->id }}"><span class="checkbox-mark">✓</span><span><b>{{ $brand->name }}</b></span></label>
                @endforeach
            </div>
        </div>
        <button class="btn btn-primary">Add Approved Account</button>
    </form>
</section>

<section class="card" style="margin-top:16px">
    <div class="card-title-row"><div><span class="eyebrow">REGISTERED DESTINATIONS</span><h3>Accounts</h3></div><span class="count-pill">{{ $accounts->total() }}</span></div>

    <div class="table-wrap">
        <table class="table modern-table">
            <thead><tr><th>Provider</th><th>Account</th><th>Holder</th><th>Brands</th><th>Status</th><th></th></tr></thead>
            <tbody>
            @forelse($accounts as $account)
                <tr>
                    <td><b>{{ $account->bank->name }}</b></td>
                    <td class="mono">{{ $account->account_number }}</td>
                    <td>{{ $account->account_name }}</td>
                    <td><div class="chip-wrap">@foreach($account->brands as $brand)<span class="brand-chip">{{ $brand->name }}</span>@endforeach</div></td>
                    <td><span class="status-pill {{ $account->is_active?'success':'danger' }}">{{ $account->is_active?'Active':'Disabled' }}</span></td>
                    <td>
                        <details class="inline-edit-details">
                            <summary class="btn btn-sm btn-outline">Edit</summary>
                            <div class="floating-edit-panel">
                                <form method="post" action="{{ route('admin.receiving-accounts.update',$account) }}" class="stack">
                                    @csrf @method('PUT')
                                    <div class="field"><label>Bank</label><select class="select" name="bank_id">@foreach($banks as $bank)<option value="{{ $bank->id }}" @selected($account->bank_id==$bank->id)>{{ $bank->name }}</option>@endforeach</select></div>
                                    <div class="field"><label>Account / phone</label><input class="input" name="account_number" value="{{ $account->account_number }}"></div>
                                    <div class="field"><label>Account name</label><input class="input" name="account_name" value="{{ $account->account_name }}"></div>
                                    <div class="field"><label>Name aliases</label><input class="input" name="name_aliases" value="{{ implode(', ',$account->name_aliases??[]) }}"></div>
                                    <div class="checkbox-card-grid">@foreach($brands as $brand)<label class="checkbox-card compact"><input type="checkbox" name="brand_ids[]" value="{{ $brand->id }}" @checked($account->brands->contains($brand))><span class="checkbox-mark">✓</span><span><b>{{ $brand->name }}</b></span></label>@endforeach</div>
                                    <label class="setting-row"><span><b>Active</b></span><span class="modern-switch compact"><input type="checkbox" name="is_active" value="1" @checked($account->is_active)><span class="modern-switch-ui"></span></span></label>
                                    <button class="btn btn-primary">Save Account</button>
                                </form>
                            </div>
                        </details>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="empty">No approved receiving accounts.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    {{ $accounts->links() }}
</section>
@endsection
