@extends('layouts.app')
@section('title','Credit Management')
@section('topbar','Credit Management')
@section('content')

<div class="page-head">
    <div>
        <span class="eyebrow">CREDIT CONTROL</span>
        <h2>Agent Credit Ledger</h2>
        <p>Every credit issue is tracked independently through unpaid, partially paid and paid states.</p>
    </div>
</div>

<div class="metric-grid metric-grid-4">
    <section class="metric-card accent-amber">
        <span>Outstanding</span>
        <strong>{{ number_format($summary['outstanding'],2) }} <small>ETB</small></strong>
    </section>
    <section class="metric-card">
        <span>Open credit records</span>
        <strong>{{ number_format($summary['open_count']) }}</strong>
    </section>
    <section class="metric-card accent-orange">
        <span>Overdue</span>
        <strong>{{ number_format($summary['overdue_count']) }}</strong>
    </section>
    <section class="metric-card accent-red">
        <span>Critical</span>
        <strong>{{ number_format($summary['critical_count']) }}</strong>
    </section>
</div>

<div class="card" style="margin-top:16px">
    <form class="filters" method="get">
        <input class="input" name="q" value="{{ request('q') }}" placeholder="Agent ID or username">
        <select class="select" name="brand">
            <option value="">All brands</option>
            @foreach($brands as $brand)
                <option value="{{ $brand->id }}" @selected(request('brand')==$brand->id)>{{ $brand->name }}</option>
            @endforeach
        </select>
        <select class="select" name="status">
            <option value="open" @selected($status==='open')>Open credit</option>
            <option value="unpaid" @selected($status==='unpaid')>Unpaid</option>
            <option value="partial" @selected($status==='partial')>Partially paid</option>
            <option value="paid" @selected($status==='paid')>Paid</option>
            <option value="all" @selected($status==='all')>All records</option>
        </select>
        <button class="btn btn-primary">Apply</button>
    </form>
</div>

<div class="card" style="margin-top:16px">
    <div class="table-wrap">
        <table class="table modern-table">
            <thead>
            <tr>
                <th>Agent</th>
                <th>Issued</th>
                <th>Original</th>
                <th>Repaid</th>
                <th>Outstanding</th>
                <th>Due</th>
                <th>Status</th>
                <th>Aging</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            @forelse($records as $record)
                <tr>
                    <td>
                        <b>{{ $record->agent->agent_id }}</b>
                        <div class="tiny muted">{{ $record->agent->username }} · {{ $record->agent->brand->name }}</div>
                    </td>
                    <td>{{ $record->issued_at?->format('d M Y H:i') }}</td>
                    <td>{{ number_format((float)$record->original_amount,2) }}</td>
                    <td>{{ number_format((float)$record->repaid_amount,2) }}</td>
                    <td><b>{{ number_format((float)$record->outstanding_amount,2) }}</b></td>
                    <td>{{ $record->due_at?->format('d M Y') ?? 'No due date' }}</td>
                    <td><span class="status-pill {{ $record->status==='paid'?'success':($record->status==='partial'?'warning':'neutral') }}">{{ str($record->status)->replace('_',' ')->title() }}</span></td>
                    <td>
                        <span class="status-pill {{ match($record->aging_status){'critical'=>'danger','serious'=>'danger','warning'=>'warning','paid'=>'success',default=>'neutral'} }}">
                            {{ str($record->aging_status)->title() }}
                        </span>
                    </td>
                    <td><a class="btn btn-sm btn-outline" href="{{ route('admin.transactions.show',$record->issueTransaction) }}">Transaction</a></td>
                </tr>
            @empty
                <tr><td colspan="9" class="empty">No credit records match these filters.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    {{ $records->links() }}
</div>
@endsection
