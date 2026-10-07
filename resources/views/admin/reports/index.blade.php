@extends('layouts.app')
@section('title','Reports')
@section('topbar','Reports')
@section('content')

<div class="page-head">
    <div>
        <span class="eyebrow">FINANCIAL ANALYTICS</span>
        <h2>Reports</h2>
        <p>{{ $range->label }} · {{ str($kind)->replace('_',' ')->title() }}</p>
    </div>
    <a class="btn btn-outline" href="{{ route('admin.reports.export',request()->query()) }}">↓ Export CSV</a>
</div>

<div class="card filter-card">
    <form class="dashboard-filter" method="get">
        <select class="select" name="date" id="reportDatePreset">
            @foreach([
                'today'=>'Today','last_24_hours'=>'Last 24 hours','yesterday'=>'Yesterday',
                'this_week'=>'This week','last_week'=>'Last week','this_month'=>'This month',
                'last_month'=>'Last month','last_3_months'=>'Last 3 months','custom'=>'Custom range'
            ] as $key=>$label)
                <option value="{{ $key }}" @selected($preset===$key)>{{ $label }}</option>
            @endforeach
        </select>

        <select class="select" name="brand">
            <option value="total">Total · All Brands</option>
            @foreach($brands as $brand)<option value="{{ $brand->id }}" @selected($brandId==$brand->id)>{{ $brand->name }}</option>@endforeach
        </select>

        <select class="select" name="kind">
            <option value="all" @selected($kind==='all')>All transactions</option>
            <option value="deposit" @selected($kind==='deposit')>Deposit report</option>
            <option value="credit" @selected($kind==='credit')>Credit issued</option>
            <option value="credit_repayment" @selected($kind==='credit_repayment')>Credit repayment</option>
            <option value="withdrawal" @selected($kind==='withdrawal')>Balance removal</option>
            <option value="commission" @selected($kind==='commission')>Commission only</option>
        </select>

        <div class="custom-date-fields {{ $preset==='custom'?'':'is-hidden' }}" id="reportCustomDates">
            <input class="input" type="date" name="from" value="{{ $from }}">
            <span>→</span>
            <input class="input" type="date" name="to" value="{{ $to }}">
        </div>

        <button class="btn btn-primary">Apply</button>
    </form>
</div>

<div class="metric-grid metric-grid-4" style="margin-top:16px">
    <section class="metric-card accent-blue"><span>Deposit</span><strong>{{ number_format($summary['deposit'],2) }} <small>ETB</small></strong></section>
    <section class="metric-card accent-amber"><span>Credit issued</span><strong>{{ number_format($summary['credit'],2) }} <small>ETB</small></strong><small>Repayments {{ number_format($summary['credit_repayment'],2) }} ETB</small></section>
    <section class="metric-card accent-red"><span>Withdrawal</span><strong>{{ number_format($summary['withdrawal'],2) }} <small>ETB</small></strong></section>
    <section class="metric-card accent-purple"><span>Commission</span><strong>{{ number_format($summary['commission'],2) }} <small>ETB</small></strong></section>
</div>

@if($kind==='credit')
    <section class="card" style="margin-top:16px">
        <div class="card-title-row">
            <div><span class="eyebrow">CURRENT EXPOSURE</span><h3>Outstanding Agent Credit</h3></div>
            <a class="btn btn-sm btn-outline" href="{{ route('admin.credits.index') }}">Open Credit Management</a>
        </div>
        <div class="table-wrap">
            <table class="table modern-table">
                <thead><tr><th>Agent</th><th>Brand</th><th>Outstanding</th><th></th></tr></thead>
                <tbody>
                @forelse($outstandingCredits as $agent)
                    <tr>
                        <td><b>{{ $agent->agent_id }}</b><div class="tiny muted">{{ $agent->username }}</div></td>
                        <td>{{ $agent->brand->name }}</td>
                        <td><b>{{ number_format($agent->outstanding_credit,2) }} ETB</b></td>
                        <td><a class="btn btn-sm btn-outline" href="{{ route('admin.agents.edit',$agent) }}">Manage</a></td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="empty">No outstanding credit.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>
@endif

@if($kind==='commission')
    <div class="alert info" style="margin-top:16px">
        Commission Deposit is intentionally separate from paid top-ups. It requires only agent-system evidence and is available only when Admin has enabled Commission for that agent.
    </div>
@endif

<section class="card" style="margin-top:16px">
    <div class="card-title-row"><div><span class="eyebrow">TRANSACTION DATA</span><h3>{{ str($kind)->replace('_',' ')->title() }} Report</h3></div><span class="count-pill">{{ $transactions->total() }}</span></div>
    <div class="table-wrap">
        <table class="table modern-table">
            <thead><tr><th>Reference</th><th>Type</th><th>Brand</th><th>Agent</th><th>Employee</th><th>Amount</th><th>Risk</th><th>Completed</th></tr></thead>
            <tbody>
            @forelse($transactions as $transaction)
                <tr>
                    <td><a href="{{ route('admin.transactions.show',$transaction) }}"><b class="mono">{{ str($transaction->reference)->limit(14) }}</b></a></td>
                    <td>{{ $transaction->type->label() }}</td>
                    <td>{{ $transaction->brand?->name }}</td>
                    <td>{{ $transaction->agent?->agent_id }}</td>
                    <td>{{ $transaction->employee?->name }}</td>
                    <td><b>{{ number_format((float)$transaction->amount,2) }}</b></td>
                    <td>{{ str($transaction->risk_level?->value ?? '—')->title() }}</td>
                    <td>{{ $transaction->completed_at?->format('d M Y H:i') }}</td>
                </tr>
            @empty
                <tr><td colspan="8" class="empty">No completed transactions for this report.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    {{ $transactions->links() }}
</section>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const preset = document.getElementById('reportDatePreset');
    const dates = document.getElementById('reportCustomDates');
    if (!preset || !dates) return;
    preset.addEventListener('change', () => dates.classList.toggle('is-hidden', preset.value !== 'custom'));
});
</script>
@endsection
