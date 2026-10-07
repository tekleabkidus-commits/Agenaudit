@extends('layouts.app')
@section('title','New Transaction')
@section('topbar','New Transaction')
@section('content')

<div class="mobile-workspace">
    <div class="page-head">
        <div>
            <span class="eyebrow">STEP 1 · TRANSACTION TYPE</span>
            <h2>What are you recording?</h2>
            <p>For normal transactions you never select a brand or agent. The screenshot identifies both automatically.</p>
        </div>
    </div>

    <div class="assigned-brand-strip">
        <span>Your assigned brands</span>
        <div>
            @forelse($assignedBrands as $brand)
                <span class="brand-chip">{{ $brand->name }}</span>
            @empty
                <span class="status-pill danger">No brand assigned</span>
            @endforelse
        </div>
    </div>

    <form method="post" action="{{ route('employee.transactions.store') }}" class="stack" id="transactionTypeForm">
        @csrf

        <div class="transaction-type-grid">
            @foreach($types as $type)
                @php
                    $selected = old('type',request('type')) === $type->value;
                    $meta = match($type->value) {
                        'paid_topup' => ['icon'=>'+','tone'=>'deposit','title'=>'Add Balance','desc'=>'Money has been received. Multiple bank receipts can be combined.'],
                        'credit' => ['icon'=>'C','tone'=>'credit','title'=>'Give Credit','desc'=>'Increase agent balance now. No bank screenshot until repayment.'],
                        'credit_repayment' => ['icon'=>'↺','tone'=>'repayment','title'=>'Credit Repayment','desc'=>'Money received later. Reduce outstanding credit without adding balance again.'],
                        'withdrawal' => ['icon'=>'−','tone'=>'withdrawal','title'=>'Remove Balance','desc'=>'Sensitive balance removal. Agent screenshot and structured reason are required.'],
                        'commission' => ['icon'=>'%','tone'=>'commission','title'=>'Commission Deposit','desc'=>'No bank screenshot. Available only when Admin enabled it for that agent.'],
                    };
                @endphp

                <label class="transaction-type-card {{ $meta['tone'] }} {{ $selected?'selected':'' }}">
                    <input type="radio" name="type" value="{{ $type->value }}" required @checked($selected)>
                    <span class="type-icon">{{ $meta['icon'] }}</span>
                    <span class="type-copy">
                        <b>{{ $meta['title'] }}</b>
                        <small>{{ $meta['desc'] }}</small>
                    </span>
                    <span class="type-check">✓</span>
                </label>
            @endforeach
        </div>

        <section id="repaymentBox" class="card repayment-box" style="{{ old('type',request('type'))==='credit_repayment'?'':'display:none' }}">
            <span class="eyebrow">CREDIT REPAYMENT ONLY</span>
            <h3>Which outstanding credit account is being repaid?</h3>
            <div class="field">
                <label>Agent with outstanding credit</label>
                <select class="select" name="repayment_agent_id">
                    <option value="">Choose agent</option>
                    @foreach($outstandingAgents as $row)
                        <option value="{{ $row['agent']->id }}" @selected(old('repayment_agent_id')==$row['agent']->id)>
                            {{ $row['agent']->agent_id }} · {{ $row['agent']->username }} · {{ $row['agent']->brand->name }} · {{ number_format($row['outstanding'],2) }} ETB outstanding
                        </option>
                    @endforeach
                </select>
                <small>This is the only flow where an agent is selected manually because repayment does not create a new agent-system balance increase.</small>
            </div>
        </section>

        <div class="create-footer">
            <a class="btn btn-ghost" href="{{ route('employee.home') }}">Cancel</a>
            <button class="btn btn-primary btn-lg">Continue to Evidence →</button>
        </div>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const cards = document.querySelectorAll('.transaction-type-card');
    const repayment = document.getElementById('repaymentBox');

    cards.forEach(card => {
        const input = card.querySelector('input[type=radio]');
        input.addEventListener('change', function () {
            cards.forEach(other => other.classList.toggle('selected', other.querySelector('input').checked));
            if (repayment) repayment.style.display = this.value === 'credit_repayment' ? 'block' : 'none';
        });
    });
});
</script>
@endsection
