@extends('layouts.app')
@section('title','Manage Agent')
@section('topbar','Manage Agent')
@section('content')

<div class="page-head">
    <div>
        <span class="eyebrow">{{ $agent->brand->name }}</span>
        <h2>{{ $agent->agent_id }} · {{ $agent->username }}</h2>
        <p>Agent controls, credit history, commission availability and brand history.</p>
    </div>
    <div class="page-actions">
        <a href="{{ route('admin.commissions.index',['q'=>$agent->agent_id]) }}" class="btn btn-outline">Commission Control</a>
        <a href="{{ route('admin.agents.index') }}" class="btn btn-ghost">Back</a>
    </div>
</div>

<div class="metric-grid metric-grid-4">
    <section class="metric-card accent-amber">
        <span>Outstanding credit</span>
        <strong>{{ number_format($outstanding,2) }} <small>ETB</small></strong>
    </section>
    <section class="metric-card">
        <span>Credit limit</span>
        <strong>{{ $agent->credit_limit!==null?number_format((float)$agent->credit_limit,0):'∞' }}</strong>
        <small>{{ $agent->credit_enabled?'Enabled':'Disabled' }}</small>
    </section>
    <section class="metric-card {{ $agent->commission_enabled?'accent-green':'' }}">
        <span>Commission this month</span>
        <strong>{{ $commissionUsedThisMonth }} / {{ $agent->commission_monthly_limit }}</strong>
        <small>{{ max(0,$agent->commission_monthly_limit-$commissionUsedThisMonth) }} remaining</small>
    </section>
    <section class="metric-card {{ $agent->is_active?'accent-blue':'accent-red' }}">
        <span>Status</span>
        <strong style="font-size:24px">{{ $agent->is_active?'ACTIVE':'DISABLED' }}</strong>
        <small>{{ $agent->brand->name }}</small>
    </section>
</div>

<div class="grid grid-2" style="margin-top:16px">
    <section class="card">
        <div class="card-title-row"><div><span class="eyebrow">ACCESS & LIMITS</span><h3>Agent Controls</h3></div></div>
        <form method="post" action="{{ route('admin.agents.update',$agent) }}" class="stack">
            @csrf @method('PUT')

            <div class="field">
                <label>Brand</label>
                <select class="select" name="brand_id">
                    @foreach($brands as $brand)<option value="{{ $brand->id }}" @selected($agent->brand_id==$brand->id)>{{ $brand->name }}</option>@endforeach
                </select>
            </div>

            <div class="form-grid">
                <div class="field"><label>Agent ID</label><input class="input" name="agent_id" value="{{ $agent->agent_id }}"></div>
                <div class="field"><label>Username</label><input class="input" name="username" value="{{ $agent->username }}"></div>
                <div class="field"><label>Credit limit</label><input class="input" type="number" step=".01" name="credit_limit" value="{{ $agent->credit_limit }}"></div>
                <div class="field"><label>Credit due days</label><input class="input" type="number" min="0" max="365" name="credit_due_days" value="{{ $agent->credit_due_days }}" placeholder="Platform default"></div>
                <div class="field"><label>Commission uses / month</label><input class="input" type="number" min="1" max="31" name="commission_monthly_limit" value="{{ $agent->commission_monthly_limit }}"></div>
            </div>

            <div class="field">
                <label>Brand move reason</label>
                <input class="input" name="move_reason" placeholder="Required context when changing this agent's brand">
            </div>

            <div class="toggle-grid">
                <label class="setting-row">
                    <span><b>Credit enabled</b><small>Allow new credit to be issued.</small></span>
                    <span class="modern-switch compact"><input type="checkbox" name="credit_enabled" value="1" @checked($agent->credit_enabled)><span class="modern-switch-ui"></span></span>
                </label>
                <label class="setting-row commission-highlight">
                    <span><b>Commission Deposit</b><small>This is the Admin ON/OFF switch controlling Employee availability.</small></span>
                    <span class="modern-switch compact"><input type="checkbox" name="commission_enabled" value="1" @checked($agent->commission_enabled)><span class="modern-switch-ui"></span></span>
                </label>
                <label class="setting-row">
                    <span><b>Agent active</b><small>Inactive agents cannot be transacted against.</small></span>
                    <span class="modern-switch compact"><input type="checkbox" name="is_active" value="1" @checked($agent->is_active)><span class="modern-switch-ui"></span></span>
                </label>
            </div>

            <button class="btn btn-primary">Save Agent</button>
        </form>
    </section>

    <section class="card">
        <div class="card-title-row"><div><span class="eyebrow">MOVEMENT HISTORY</span><h3>Brand History</h3></div></div>
        <div class="timeline">
        @forelse($agent->brandHistories->sortByDesc('changed_at') as $history)
            <div class="event">
                <b>{{ $history->fromBrand?->name ?? '—' }} → {{ $history->toBrand?->name }}</b>
                <div class="small">{{ $history->reason }}</div>
                <div class="time">{{ $history->changed_at->format('d M Y H:i') }}</div>
            </div>
        @empty
            <div class="empty">No brand moves.</div>
        @endforelse
        </div>
    </section>
</div>

<section class="card" style="margin-top:16px">
    <div class="card-title-row">
        <div><span class="eyebrow">INDIVIDUAL CREDIT RECORDS</span><h3>Credit Status & Aging</h3></div>
        <a class="btn btn-sm btn-outline" href="{{ route('admin.credits.index',['q'=>$agent->agent_id]) }}">Open Credit Management</a>
    </div>

    <div class="table-wrap">
        <table class="table modern-table">
            <thead><tr><th>Issued</th><th>Original</th><th>Repaid</th><th>Outstanding</th><th>Due</th><th>Status</th><th>Aging</th><th></th></tr></thead>
            <tbody>
            @forelse($creditRecords as $record)
                <tr>
                    <td>{{ $record->issued_at?->format('d M Y H:i') }}</td>
                    <td>{{ number_format((float)$record->original_amount,2) }}</td>
                    <td>{{ number_format((float)$record->repaid_amount,2) }}</td>
                    <td><b>{{ number_format((float)$record->outstanding_amount,2) }}</b></td>
                    <td>{{ $record->due_at?->format('d M Y') ?? '—' }}</td>
                    <td><span class="status-pill {{ $record->status==='paid'?'success':($record->status==='partial'?'warning':'neutral') }}">{{ str($record->status)->title() }}</span></td>
                    <td><span class="status-pill {{ match($record->aging_status){'critical','serious'=>'danger','warning'=>'warning','paid'=>'success',default=>'neutral'} }}">{{ str($record->aging_status)->title() }}</span></td>
                    <td><a class="btn btn-sm btn-ghost" href="{{ route('admin.transactions.show',$record->issueTransaction) }}">View</a></td>
                </tr>
            @empty
                <tr><td colspan="8" class="empty">No individual credit records yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</section>

<div class="grid grid-2" style="margin-top:16px">
    <section class="card">
        <div class="card-title-row"><div><span class="eyebrow">LEDGER</span><h3>Credit Entries</h3></div></div>
        <div class="table-wrap">
            <table class="table modern-table">
                <thead><tr><th>Date</th><th>Type</th><th>Amount</th><th>Before</th><th>After</th></tr></thead>
                <tbody>
                @forelse($agent->creditLedgerEntries->sortByDesc('occurred_at') as $entry)
                    <tr><td>{{ $entry->occurred_at->format('d M H:i') }}</td><td><span class="status-pill {{ $entry->entry_type->value==='repayment'?'success':'warning' }}">{{ $entry->entry_type->value }}</span></td><td>{{ number_format((float)$entry->amount,2) }}</td><td>{{ number_format((float)$entry->balance_before,2) }}</td><td><b>{{ number_format((float)$entry->balance_after,2) }}</b></td></tr>
                @empty
                    <tr><td colspan="5" class="empty">No credit activity.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="card">
        <div class="card-title-row"><div><span class="eyebrow">ACTIVITY</span><h3>Recent Transactions</h3></div></div>
        <div class="table-wrap">
            <table class="table modern-table">
                <thead><tr><th>Date</th><th>Type</th><th>Amount</th><th>Status</th></tr></thead>
                <tbody>
                @forelse($agent->transactions->sortByDesc('created_at')->take(30) as $transaction)
                    <tr><td>{{ $transaction->created_at->format('d M H:i') }}</td><td><a href="{{ route('admin.transactions.show',$transaction) }}">{{ $transaction->type->label() }}</a></td><td>{{ $transaction->amount!==null?number_format((float)$transaction->amount,2):'—' }}</td><td><span class="status-pill {{ $transaction->status->value==='completed'?'success':($transaction->status->value==='rejected'?'danger':'warning') }}">{{ str($transaction->status->value)->replace('_',' ')->title() }}</span></td></tr>
                @empty
                    <tr><td colspan="4" class="empty">No transactions.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
@endsection
