@extends('layouts.app')
@section('title','My Transactions')
@section('topbar','My History')
@section('content')

<div class="mobile-workspace">
    <div class="page-head">
        <div>
            <span class="eyebrow">YOUR RECORDS ONLY</span>
            <h2>Transaction History</h2>
            <p>Company totals are never shown here. You can only see your own transactions within brands assigned to you.</p>
        </div>
        <a class="btn btn-primary" href="{{ route('employee.transactions.create') }}">＋ New</a>
    </div>

    <div class="card filter-card">
        <form class="filters" method="get">
            <select class="select" name="type">
                <option value="">All types</option>
                @foreach(App\Enums\TransactionType::cases() as $type)
                    <option value="{{ $type->value }}" @selected(request('type')===$type->value)>{{ $type->label() }}</option>
                @endforeach
            </select>
            <select class="select" name="status">
                <option value="">All statuses</option>
                @foreach(App\Enums\TransactionStatus::cases() as $status)
                    <option value="{{ $status->value }}" @selected(request('status')===$status->value)>{{ str($status->value)->replace('_',' ')->title() }}</option>
                @endforeach
            </select>
            <select class="select" name="agent" aria-label="Filter by agent">
                <option value="">All agents</option>
                @foreach($agents as $agent)
                    <option value="{{ $agent->id }}" @selected(request('agent')==(string)$agent->id)>
                        {{ $agent->agent_id }} · {{ $agent->username }} · {{ $agent->brand?->name }}
                    </option>
                @endforeach
            </select>
            <button class="btn btn-primary">Filter</button>
            @if(request()->hasAny(['type','status','agent']))<a class="btn btn-ghost" href="{{ route('employee.transactions.index') }}">Reset</a>@endif
        </form>
    </div>

    <div class="activity-list card" style="margin-top:14px">
        @forelse($transactions as $transaction)
            <a class="activity-row history-row" href="{{ route('employee.transactions.show',$transaction) }}">
                <span class="activity-icon {{ $transaction->type->value }}">{{ match($transaction->type->value){'paid_topup'=>'+','credit'=>'C','credit_repayment'=>'↺','withdrawal'=>'−','commission'=>'%'} }}</span>
                <span class="activity-copy">
                    <b>{{ $transaction->type->label() }}</b>
                    <small>{{ $transaction->agent?->agent_id ?? 'Agent pending' }} · {{ $transaction->brand?->name ?? 'Brand pending' }}</small>
                    <small>{{ $transaction->created_at->format('d M Y, H:i') }} @if($transaction->amount!==null) · {{ number_format((float)$transaction->amount,2) }} ETB @endif</small>
                </span>
                <span class="status-pill {{ match($transaction->status->value){'completed'=>'success','rejected'=>'danger','cancelled'=>'neutral','pending_employee_confirmation'=>'info',default=>'warning'} }}">
                    {{ str($transaction->status->value)->replace('_',' ')->title() }}
                </span>
            </a>
        @empty
            <div class="empty">No transactions match this filter.</div>
        @endforelse
    </div>

    {{ $transactions->links() }}
</div>
@endsection
