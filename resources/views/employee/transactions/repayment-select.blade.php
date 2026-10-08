@extends('layouts.app')
@section('title','Choose Repayment Agent')
@section('topbar','Credit Repayment')
@section('content')

<div class="mobile-workspace">
    <div class="page-head">
        <div>
            <span class="eyebrow">CREDIT REPAYMENT · CHOOSE AGENT</span>
            <h2>Which agent is repaying credit?</h2>
            <p>Select the agent with an outstanding balance. You will then upload the bank receipt. No record is created until evidence is uploaded.</p>
        </div>
        <a class="btn btn-outline" href="{{ route('employee.transactions.create') }}">← Types</a>
    </div>

    <div class="assigned-brand-strip">
        <span>Your assigned brands</span>
        <div>@foreach($assignedBrands as $brand)<span class="brand-chip">{{ $brand->name }}</span>@endforeach</div>
    </div>

    <div class="card">
        <div class="card-title-row">
            <div><span class="eyebrow">OUTSTANDING CREDIT</span><h3>Select one agent</h3></div>
            <span class="status-pill neutral">{{ $outstandingAgents->count() }} eligible</span>
        </div>
        <div class="activity-list">
            @forelse($outstandingAgents as $row)
                <a class="activity-row repayment-agent-choice"
                   href="{{ route('employee.transactions.repayment.evidence', ['agent'=>$row['agent']]) }}">
                    <span class="entity-avatar">{{ strtoupper(substr($row['agent']->username,0,1)) }}</span>
                    <span class="activity-copy">
                        <b>{{ $row['agent']->agent_id }} · {{ $row['agent']->username }}</b>
                        <small>{{ $row['agent']->brand->name }} · {{ number_format($row['outstanding'],2) }} ETB outstanding</small>
                    </span>
                    <span class="type-arrow">→</span>
                </a>
            @empty
                <div class="empty">No eligible agents with outstanding credit in your assigned brands.</div>
            @endforelse
        </div>
    </div>
    <p class="tiny muted" style="margin-top:12px">Agents outside your assigned brands and agents without outstanding credit cannot be selected.</p>
</div>
@endsection
