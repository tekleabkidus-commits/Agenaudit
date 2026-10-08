@extends('layouts.app')
@section('title','Employee Home')
@section('topbar','Home')
@section('content')

<div class="mobile-workspace">
    <div class="employee-welcome">
        <div>
            <span class="eyebrow">EMPLOYEE WORKSPACE</span>
            <h2>Hello, {{ auth()->user()->name }}</h2>
            <p>Choose what you are recording. Agent and brand are identified from evidence automatically.</p>
        </div>
        <a href="{{ route('employee.transactions.create') }}" class="btn btn-primary btn-lg">＋ New Transaction</a>
    </div>

    <div class="assigned-brand-strip">
        <span>Assigned brands</span>
        <div>
            @forelse($assignedBrands as $brand)
                <span class="brand-chip">{{ $brand->name }}</span>
            @empty
                <span class="status-pill danger">No brand assigned</span>
            @endforelse
        </div>
    </div>

    <section class="transaction-launch-grid">
        <a class="launch-card deposit" href="{{ route('employee.transactions.start',['type'=>'paid_topup']) }}">
            <span class="launch-icon">＋</span>
            <div><b>Add Balance</b><small>Paid top-up</small></div>
            <span class="launch-arrow">→</span>
        </a>
        <a class="launch-card credit" href="{{ route('employee.transactions.start',['type'=>'credit']) }}">
            <span class="launch-icon">C</span>
            <div><b>Give Credit</b><small>Balance now, payment later</small></div>
            <span class="launch-arrow">→</span>
        </a>
        <a class="launch-card commission" href="{{ route('employee.transactions.start',['type'=>'commission']) }}">
            <span class="launch-icon">%</span>
            <div><b>Commission</b><small>Admin-enabled agents only</small></div>
            <span class="launch-arrow">→</span>
        </a>
        <a class="launch-card withdrawal" href="{{ route('employee.transactions.start',['type'=>'withdrawal']) }}">
            <span class="launch-icon">−</span>
            <div><b>Remove Balance</b><small>Sensitive action</small></div>
            <span class="launch-arrow">→</span>
        </a>
        <a class="launch-card repayment" href="{{ route('employee.transactions.start',['type'=>'credit_repayment']) }}">
            <span class="launch-icon">↺</span>
            <div><b>Credit Repayment</b><small>Reduce outstanding credit</small></div>
            <span class="launch-arrow">→</span>
        </a>
    </section>

    <section class="card" style="margin-top:16px">
        <div class="card-title-row">
            <div><span class="eyebrow">YOUR ACTIVITY</span><h3>Recent Transactions</h3></div>
            <a class="btn btn-sm btn-ghost" href="{{ route('employee.transactions.index') }}">View history</a>
        </div>

        <div class="activity-list">
            @forelse($recent as $transaction)
                <a class="activity-row" href="{{ route('employee.transactions.show',$transaction) }}">
                    <span class="activity-icon {{ $transaction->type->value }}">{{ match($transaction->type->value){'paid_topup'=>'+','credit'=>'C','credit_repayment'=>'↺','withdrawal'=>'−','commission'=>'%'} }}</span>
                    <span class="activity-copy">
                        <b>{{ $transaction->type->label() }}</b>
                        <small>{{ $transaction->agent?->agent_id ?? 'Agent pending AI' }} · {{ $transaction->brand?->name ?? 'Brand pending' }} · {{ $transaction->created_at->format('d M H:i') }}</small>
                    </span>
                    <span class="status-pill {{ match($transaction->status->value){'completed'=>'success','rejected'=>'danger','cancelled'=>'neutral','pending_employee_confirmation'=>'info',default=>'warning'} }}">{{ str($transaction->status->value)->replace('_',' ')->title() }}</span>
                </a>
            @empty
                <div class="empty">No transactions yet. Start with New Transaction.</div>
            @endforelse
        </div>
    </section>
</div>
@endsection
