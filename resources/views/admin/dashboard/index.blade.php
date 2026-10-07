@extends('layouts.app')
@section('title','Dashboard')
@section('topbar','Dashboard')
@section('content')

@php
    $palette = ['#2563eb','#22c55e','#f59e0b','#8b5cf6','#06b6d4','#f43f5e','#14b8a6','#6366f1','#84cc16','#fb7185'];
    $start = 0;
    $segments = [];

    foreach($data['banks'] as $index=>$bank) {
        $end = min(100, $start + (float)$bank['percentage']);
        if ($end > $start) {
            $segments[] = $palette[$index % count($palette)]." {$start}% {$end}%";
        }
        $start = $end;
    }

    if ($start < 100) {
        $segments[] = "#e8edf5 {$start}% 100%";
    }

    $donutGradient = 'conic-gradient('.implode(', ', $segments).')';
@endphp

<div class="page-head dashboard-head">
    <div>
        <span class="eyebrow">FINANCIAL COMMAND CENTER</span>
        <h2>Dashboard</h2>
        <p>{{ $data['range']->label }} · {{ $brandId ? optional($brands->firstWhere('id',$brandId))->name : 'All Brands' }}</p>
    </div>

    <form class="dashboard-filter" method="get">
        <select name="date" class="select" id="dashboardDatePreset">
            @foreach([
                'today'=>'Today',
                'last_24_hours'=>'Last 24 hours',
                'yesterday'=>'Yesterday',
                'this_week'=>'This week',
                'last_week'=>'Last week',
                'this_month'=>'This month',
                'last_month'=>'Last month',
                'last_3_months'=>'Last 3 months',
                'custom'=>'Custom range'
            ] as $key=>$label)
                <option value="{{ $key }}" @selected($preset===$key)>{{ $label }}</option>
            @endforeach
        </select>

        <select name="brand" class="select">
            <option value="total">Total · All Brands</option>
            @foreach($brands as $brand)
                <option value="{{ $brand->id }}" @selected($brandId===$brand->id)>{{ $brand->name }}</option>
            @endforeach
        </select>

        <div class="custom-date-fields {{ $preset==='custom'?'':'is-hidden' }}" id="customDateFields">
            <input class="input" type="date" name="from" value="{{ $from }}">
            <span>→</span>
            <input class="input" type="date" name="to" value="{{ $to }}">
        </div>

        <button class="btn btn-primary">Apply</button>
    </form>
</div>

<div class="dashboard-primary-grid">
    <section class="card deposit-hero">
        <div class="deposit-copy">
            <span class="eyebrow">TOTAL DEPOSIT</span>
            <div class="hero-value">{{ number_format($data['deposit'],2) }} <small>ETB</small></div>
            <p>Completed paid top-ups in the selected period.</p>

            <div class="bank-share-list">
                @forelse($data['banks']->take(6) as $index=>$bank)
                    <div class="bank-share-row">
                        <span class="bank-dot" style="background:{{ $palette[$index % count($palette)] }}"></span>
                        <b>{{ $bank['bank'] }}</b>
                        <div class="bank-share-bar"><span style="width:{{ min(100,$bank['percentage']) }}%;background:{{ $palette[$index % count($palette)] }}"></span></div>
                        <span>{{ number_format($bank['total'],0) }}</span>
                        <strong>{{ $bank['percentage'] }}%</strong>
                    </div>
                @empty
                    <div class="empty">No deposit activity in this period.</div>
                @endforelse
            </div>
        </div>

        <div class="full-donut-wrap">
            <div class="full-donut" style="background:{{ $donutGradient }}">
                <div class="donut-hole">
                    <strong>{{ $data['banks']->count() }}</strong>
                    <span>banks</span>
                </div>
            </div>
            <span class="tiny muted">Deposit distribution</span>
        </div>
    </section>

    <div class="dashboard-side-metrics">
        <section class="metric-card accent-amber">
            <span>Total Credit</span>
            <strong>{{ number_format($data['credit'],2) }} <small>ETB</small></strong>
            <small>Repayments {{ number_format($data['credit_repayment'],2) }} ETB</small>
        </section>
        <section class="metric-card accent-red">
            <span>Total Withdrawal</span>
            <strong>{{ number_format($data['withdrawal'],2) }} <small>ETB</small></strong>
            <small>Balance removed</small>
        </section>
        <section class="metric-card accent-purple">
            <span>Commission Deposit</span>
            <strong>{{ number_format($data['commission'],2) }} <small>ETB</small></strong>
            <small>Separate from paid deposits</small>
        </section>
        <section class="metric-card accent-blue">
            <span>Completed</span>
            <strong>{{ number_format($data['completed_count']) }}</strong>
            <small>Transactions</small>
        </section>
    </div>
</div>

<div class="grid grid-2 dashboard-secondary" style="margin-top:18px">
    <section class="card">
        <div class="card-title-row">
            <div><span class="eyebrow">TREND</span><h3>Daily Overview</h3></div>
            <div class="chart-legend">
                <span><i class="dot deposit"></i>Deposit</span>
                <span><i class="dot credit"></i>Credit</span>
                <span><i class="dot withdrawal"></i>Withdrawal</span>
                <span><i class="dot commission"></i>Commission</span>
            </div>
        </div>

        <div class="daily-chart-scroll">
            <div class="daily-chart" style="min-width:{{ max(640,count($data['daily'])*34) }}px">
                @foreach($data['daily'] as $day)
                    @php
                        $dh=$day['deposit']>0?max(4,round(($day['deposit']/$data['daily_max'])*100)):0;
                        $ch=$day['credit']>0?max(4,round(($day['credit']/$data['daily_max'])*100)):0;
                        $wh=$day['withdrawal']>0?max(4,round(($day['withdrawal']/$data['daily_max'])*100)):0;
                        $mh=$day['commission']>0?max(4,round(($day['commission']/$data['daily_max'])*100)):0;
                    @endphp
                    <div class="day-group" title="{{ $day['date']->format('d M Y') }}">
                        <div class="day-bars">
                            <i class="deposit" style="height:{{ $dh }}%"></i>
                            <i class="credit" style="height:{{ $ch }}%"></i>
                            <i class="withdrawal" style="height:{{ $wh }}%"></i>
                            <i class="commission" style="height:{{ $mh }}%"></i>
                        </div>
                        <span>{{ $day['date']->format(count($data['daily'])>31?'d':'d M') }}</span>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    <section class="card">
        <div class="card-title-row"><div><span class="eyebrow">MIX</span><h3>Transaction Summary</h3></div></div>
        <div class="summary-list">
            @foreach($data['transaction_summary'] as $key=>$row)
                <div class="summary-row">
                    <div><b>{{ $row['label'] }}</b><small>{{ number_format($row['count']) }} transactions</small></div>
                    <strong>{{ number_format($row['amount'],2) }} <small>ETB</small></strong>
                </div>
            @endforeach
        </div>
    </section>
</div>

<div class="grid grid-2" style="margin-top:18px">
    <section class="card">
        <div class="card-title-row"><div><span class="eyebrow">LATEST</span><h3>Recent Completed Transactions</h3></div><a class="btn btn-sm btn-ghost" href="{{ route('admin.transactions.index') }}">View all</a></div>
        <div class="table-wrap">
            <table class="table modern-table">
                <thead><tr><th>Transaction</th><th>Brand</th><th>Agent</th><th>Amount</th><th>Employee</th></tr></thead>
                <tbody>
                @forelse($data['recent'] as $transaction)
                    <tr>
                        <td><a href="{{ route('admin.transactions.show',$transaction) }}"><b>{{ $transaction->type->label() }}</b><div class="tiny muted">{{ $transaction->completed_at?->format('d M H:i') }}</div></a></td>
                        <td>{{ $transaction->brand?->name }}</td>
                        <td>{{ $transaction->agent?->agent_id }}</td>
                        <td><b>{{ number_format((float)$transaction->amount,2) }}</b></td>
                        <td>{{ $transaction->employee?->name }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="empty">No completed transactions.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="card">
        <div class="card-title-row"><div><span class="eyebrow">BANKS</span><h3>Bank Breakdown</h3></div></div>
        <div class="table-wrap">
            <table class="table modern-table">
                <thead><tr><th>Bank</th><th>Deposit Total</th><th>Share</th></tr></thead>
                <tbody>
                @forelse($data['banks'] as $index=>$bank)
                    <tr>
                        <td><span class="bank-dot" style="background:{{ $palette[$index % count($palette)] }}"></span> <b>{{ $bank['bank'] }}</b></td>
                        <td>{{ number_format($bank['total'],2) }} ETB</td>
                        <td><b>{{ $bank['percentage'] }}%</b></td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="empty">No bank activity for selected filters.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const preset = document.getElementById('dashboardDatePreset');
    const custom = document.getElementById('customDateFields');
    if (!preset || !custom) return;
    preset.addEventListener('change', () => custom.classList.toggle('is-hidden', preset.value !== 'custom'));
});
</script>
@endsection
