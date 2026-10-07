@extends('layouts.app')
@section('title','Banks')
@section('topbar','Banks & Providers')
@section('content')

<div class="page-head">
    <div>
        <span class="eyebrow">PAYMENT MASTER DATA</span>
        <h2>Banks & Wallets</h2>
        <p>The catalog powers AI normalization and optional Check.et routing. Check.et remains secondary to our own receiving-account and duplicate validation.</p>
    </div>
</div>

<div class="grid grid-2">
    <section class="card elevated">
        <div class="card-title-row"><div><span class="eyebrow">CATALOG</span><h3>Add Provider</h3></div><span class="icon-bubble">▣</span></div>
        <form method="post" action="{{ route('admin.banks.store') }}" class="stack">
            @csrf
            <div class="form-grid">
                <div class="field"><label>Name</label><input class="input" name="name" required></div>
                <div class="field"><label>Internal code</label><input class="input" name="code" placeholder="CBE" required></div>
                <div class="field"><label>Aliases</label><input class="input" name="aliases" placeholder="CBE, Commercial Bank of Ethiopia"></div>
                <div class="field"><label>Check.et code</label><input class="input" name="check_et_code" placeholder="cbe"></div>
                <div class="field">
                    <label>Account source for Check.et</label>
                    <select class="select" name="check_et_account_source">
                        <option value="none">Not required</option>
                        <option value="receiving_account">Receiving account</option>
                        <option value="sender_account">Sender account / phone</option>
                    </select>
                </div>
            </div>
            <div class="toggle-grid">
                <label class="setting-row"><span><b>Check.et enabled</b><small>Used only when global Check.et is ON.</small></span><span class="modern-switch compact"><input type="checkbox" name="check_et_enabled" value="1" checked><span class="modern-switch-ui"></span></span></label>
                <label class="setting-row"><span><b>Account number required</b><small>Provider requires account_number in verification.</small></span><span class="modern-switch compact"><input type="checkbox" name="check_et_requires_account" value="1"><span class="modern-switch-ui"></span></span></label>
            </div>
            <button class="btn btn-primary btn-lg">Add Provider</button>
        </form>
    </section>

    <section class="card">
        <div class="card-title-row"><div><span class="eyebrow">CONFIGURED</span><h3>Provider Catalog</h3></div><span class="count-pill">{{ $banks->count() }}</span></div>
        <div class="provider-list">
            @forelse($banks as $bank)
                <details class="provider-card">
                    <summary>
                        <span class="provider-icon">{{ strtoupper(substr($bank->code,0,2)) }}</span>
                        <span class="provider-copy"><b>{{ $bank->name }}</b><small>{{ $bank->code }} · Check.et {{ $bank->check_et_code ?: 'not configured' }}</small></span>
                        <span class="status-pill {{ $bank->check_et_enabled?'success':'neutral' }}">{{ $bank->check_et_enabled?'Check.et ON':'Check.et OFF' }}</span>
                        <span class="count-pill">{{ $bank->receiving_accounts_count }} acct</span>
                        <span class="details-chevron">⌄</span>
                    </summary>

                    <form method="post" action="{{ route('admin.banks.update',$bank) }}" class="stack provider-edit">
                        @csrf @method('PUT')
                        <div class="form-grid">
                            <div class="field"><label>Name</label><input class="input" name="name" value="{{ $bank->name }}"></div>
                            <div class="field"><label>Code</label><input class="input" name="code" value="{{ $bank->code }}"></div>
                            <div class="field"><label>Aliases</label><input class="input" name="aliases" value="{{ implode(', ',$bank->aliases??[]) }}"></div>
                            <div class="field"><label>Check.et code</label><input class="input" name="check_et_code" value="{{ $bank->check_et_code }}"></div>
                            <div class="field"><label>Check.et account source</label><select class="select" name="check_et_account_source"><option value="none" @selected(($bank->check_et_account_source??'none')==='none')>No account</option><option value="receiving_account" @selected($bank->check_et_account_source==='receiving_account')>Receiving account</option><option value="sender_account" @selected($bank->check_et_account_source==='sender_account')>Sender account / phone</option></select></div>
                        </div>
                        <div class="toggle-grid">
                            <label class="setting-row"><span><b>Check.et</b></span><span class="modern-switch compact"><input type="checkbox" name="check_et_enabled" value="1" @checked($bank->check_et_enabled)><span class="modern-switch-ui"></span></span></label>
                            <label class="setting-row"><span><b>Requires account</b></span><span class="modern-switch compact"><input type="checkbox" name="check_et_requires_account" value="1" @checked($bank->check_et_requires_account)><span class="modern-switch-ui"></span></span></label>
                            <label class="setting-row"><span><b>Provider active</b></span><span class="modern-switch compact"><input type="checkbox" name="is_active" value="1" @checked($bank->is_active)><span class="modern-switch-ui"></span></span></label>
                        </div>
                        <button class="btn btn-primary">Save Provider</button>
                    </form>
                </details>
            @empty
                <div class="empty">No providers configured.</div>
            @endforelse
        </div>
    </section>
</div>
@endsection
