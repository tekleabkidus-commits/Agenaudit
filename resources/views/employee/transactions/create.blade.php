@extends('layouts.app')
@section('title','New Transaction')
@section('topbar','New Transaction')
@section('content')

<div class="mobile-workspace">
    <div class="page-head">
        <div>
            <span class="eyebrow">CHOOSE YOUR TRANSACTION</span>
            <h2>What are you recording?</h2>
            <p>Tap a transaction type to go directly to its next step. No empty transaction is created.</p>
        </div>
    </div>

    <div class="assigned-brand-strip">
        <span>Your assigned brands</span>
        <div>
            @forelse($assignedBrands as $brand)
                <span class="brand-chip">{{ $brand->name }}</span>
            @empty
                <span class="status-pill danger">No active brand assigned</span>
            @endforelse
        </div>
    </div>

    <div class="transaction-type-grid">
        @foreach($types as $type)
            @php
                $meta = match($type->value) {
                    'paid_topup' => ['icon'=>'+','tone'=>'deposit','title'=>'Add Balance','desc'=>'Next: upload agent-system evidence. Bank receipts follow.'],
                    'credit' => ['icon'=>'C','tone'=>'credit','title'=>'Give Credit','desc'=>'Next: upload agent-system evidence.'],
                    'credit_repayment' => ['icon'=>'↺','tone'=>'repayment','title'=>'Credit Repayment','desc'=>'Next: choose an agent with outstanding credit.'],
                    'withdrawal' => ['icon'=>'−','tone'=>'withdrawal','title'=>'Remove Balance','desc'=>'Next: upload agent evidence and provide a reason.'],
                    'commission' => ['icon'=>'%','tone'=>'commission','title'=>'Commission Deposit','desc'=>'Next: upload agent evidence. Admin controls eligibility.'],
                };
            @endphp

            <a class="transaction-type-card {{ $meta['tone'] }}"
               href="{{ route('employee.transactions.start',['type'=>$type->value]) }}">
                <span class="type-icon">{{ $meta['icon'] }}</span>
                <span class="type-copy">
                    <b>{{ $meta['title'] }}</b>
                    <small>{{ $meta['desc'] }}</small>
                </span>
                <span class="type-arrow" aria-hidden="true">→</span>
            </a>
        @endforeach
    </div>

    <div class="tiny muted" style="margin-top:14px">
        Nothing is saved until you upload your first screenshot.
    </div>
</div>
@endsection
