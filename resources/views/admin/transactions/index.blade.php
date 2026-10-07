@extends('layouts.app')
@section('title','Transactions')
@section('topbar','Transactions')
@section('content')

<div class="page-head">
    <div>
        <span class="eyebrow">AUDIT STREAM</span>
        <h2>All Transactions</h2>
        <p>Paid top-ups, credit, repayments, commission deposits and balance removals in one immutable audit stream.</p>
    </div>
</div>

<div class="card filter-card">
    <form class="filters" method="get">
        <input class="input" name="q" value="{{ request('q') }}" placeholder="Reference, bank TX ID, Agent ID or username">
        <select class="select" name="type">
            <option value="">All types</option>
            @foreach($types as $type)<option value="{{ $type->value }}" @selected(request('type')===$type->value)>{{ $type->label() }}</option>@endforeach
        </select>
        <select class="select" name="status">
            <option value="">All statuses</option>
            @foreach($statuses as $status)<option value="{{ $status->value }}" @selected(request('status')===$status->value)>{{ str($status->value)->replace('_',' ')->title() }}</option>@endforeach
        </select>
        <select class="select" name="brand">
            <option value="">All brands</option>
            @foreach($brands as $brand)<option value="{{ $brand->id }}" @selected(request('brand')==$brand->id)>{{ $brand->name }}</option>@endforeach
        </select>
        <select class="select" name="employee">
            <option value="">All employees</option>
            @foreach($employees as $employee)<option value="{{ $employee->id }}" @selected(request('employee')==$employee->id)>{{ $employee->name }}</option>@endforeach
        </select>
        <button class="btn btn-primary">Apply</button>
        @if(request()->hasAny(['q','type','status','brand','employee']))<a class="btn btn-ghost" href="{{ route('admin.transactions.index') }}">Reset</a>@endif
    </form>
</div>

<section class="card" style="margin-top:16px">
    <div class="card-title-row">
        <div><span class="eyebrow">RESULTS</span><h3>Transaction Audit</h3></div>
        <span class="count-pill">{{ $transactions->total() }}</span>
    </div>

    <div class="table-wrap">
        <table class="table modern-table">
            <thead>
            <tr>
                <th>Transaction</th>
                <th>Type</th>
                <th>Agent</th>
                <th>Brand</th>
                <th>Employee</th>
                <th>Amount</th>
                <th>Status</th>
                <th>Risk</th>
                <th>Created</th>
            </tr>
            </thead>
            <tbody>
            @forelse($transactions as $transaction)
                <tr>
                    <td>
                        <a href="{{ route('admin.transactions.show',$transaction) }}">
                            <b class="mono">{{ str($transaction->reference)->limit(14) }}</b>
                            <div class="tiny muted">Open audit detail →</div>
                        </a>
                    </td>
                    <td><b>{{ $transaction->type->label() }}</b></td>
                    <td>{{ $transaction->agent?->agent_id ?? 'Pending AI' }}<div class="tiny muted">{{ $transaction->agent?->username }}</div></td>
                    <td>{{ $transaction->brand?->name ?? '—' }}</td>
                    <td>{{ $transaction->employee?->name }}</td>
                    <td>{{ $transaction->amount!==null?number_format((float)$transaction->amount,2).' ETB':'—' }}</td>
                    <td>
                        <span class="status-pill {{ match($transaction->status->value){'completed'=>'success','rejected'=>'danger','cancelled'=>'neutral','pending_employee_confirmation'=>'info','ready_for_review'=>'success',default=>'warning'} }}">
                            {{ str($transaction->status->value)->replace('_',' ')->title() }}
                        </span>
                    </td>
                    <td>
                        @if($transaction->risk_level)
                            <span class="status-pill {{ match($transaction->risk_level->value){'critical'=>'danger','alarming'=>'danger','serious'=>'warning','warning'=>'warning',default=>'neutral'} }}">{{ str($transaction->risk_level->value)->title() }}</span>
                        @else
                            <span class="muted">—</span>
                        @endif
                    </td>
                    <td>{{ $transaction->created_at->format('d M Y H:i') }}</td>
                </tr>
            @empty
                <tr><td colspan="9" class="empty">No transactions match these filters.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    {{ $transactions->links() }}
</section>
@endsection
