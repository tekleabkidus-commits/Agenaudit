@extends('layouts.app')
@section('title','Upload Evidence')
@section('topbar','Upload Evidence')
@section('content')

@php
    $isRepayment = $type === App\Enums\TransactionType::CreditRepayment;
    $meta = match ($type) {
        App\Enums\TransactionType::PaidTopup => ['eyebrow'=>'PAID TOP-UP · FIRST EVIDENCE','title'=>'Upload agent-system screenshot','description'=>'Upload the agent balance-added screenshot first. Once AI identifies the agent and brand, you can attach one or more bank receipts.'],
        App\Enums\TransactionType::Credit => ['eyebrow'=>'GIVE CREDIT · FIRST EVIDENCE','title'=>'Upload credit screenshot','description'=>'Upload proof of the balance credited to the agent. No bank screenshot is required for credit issue.'],
        App\Enums\TransactionType::Commission => ['eyebrow'=>'COMMISSION DEPOSIT · FIRST EVIDENCE','title'=>'Upload commission screenshot','description'=>'Upload agent-system proof. The platform checks Admin commission permission and monthly limit automatically.'],
        App\Enums\TransactionType::Withdrawal => ['eyebrow'=>'REMOVE BALANCE · FIRST EVIDENCE','title'=>'Upload balance-removal screenshot','description'=>'Upload agent-system proof. You will choose a structured removal reason after AI identifies the agent.'],
        App\Enums\TransactionType::CreditRepayment => ['eyebrow'=>'CREDIT REPAYMENT · BANK EVIDENCE','title'=>'Upload repayment bank receipt(s)','description'=>'Upload one or more bank receipts for this agent. The verified amount reduces outstanding credit; it does not increase agent balance.'],
    };
@endphp

<div class="mobile-workspace">
    <div class="page-head">
        <div>
            <span class="eyebrow">{{ $meta['eyebrow'] }}</span>
            <h2>{{ $meta['title'] }}</h2>
            <p>{{ $meta['description'] }}</p>
        </div>
        <a class="btn btn-outline" href="{{ $isRepayment ? route('employee.transactions.start',['type'=>$type->value]) : route('employee.transactions.create') }}">← Back</a>
    </div>

    @if($isRepayment)
        <section class="card agent-identity-card" style="margin-bottom:15px">
            <div class="entity-cell">
                <span class="entity-avatar large">{{ strtoupper(substr($agent->username,0,1)) }}</span>
                <div>
                    <span class="eyebrow">SELECTED REPAYMENT AGENT</span>
                    <h3>{{ $agent->agent_id }} · {{ $agent->username }}</h3>
                    <div class="small muted">{{ $agent->brand->name }} · {{ number_format($outstanding,2) }} ETB outstanding</div>
                </div>
            </div>
            <a class="btn btn-sm btn-outline" href="{{ route('employee.transactions.start',['type'=>'credit_repayment']) }}">Change agent</a>
        </section>
    @else
        <div class="assigned-brand-strip">
            <span>Assigned brands</span>
            <div>@foreach($assignedBrands as $brand)<span class="brand-chip">{{ $brand->name }}</span>@endforeach</div>
        </div>
    @endif

    <section class="card workflow-card">
        <div class="step-heading">
            <span class="step-number">1</span>
            <div>
                <h3>{{ $isRepayment ? 'Bank payment evidence' : 'Agent-system evidence' }}</h3>
                <p>This screenshot is required before the transaction is saved.</p>
            </div>
        </div>

        <form method="post" action="{{ route('employee.transactions.initial-evidence.store',['type'=>$type->value]) }}" enctype="multipart/form-data" class="stack">
            @csrf
            @if($isRepayment)
                <input type="hidden" name="repayment_agent_id" value="{{ $agent->id }}">
                <label class="upload-zone">
                    <span class="upload-icon">↑</span>
                    <b>Select bank receipt screenshots</b>
                    <small>1–{{ config('agent_audit.evidence.max_bank_screenshots_per_transaction',12) }} clear JPEG, PNG or WebP images. Each is checked separately.</small>
                    <input type="file" name="screenshots[]" accept="image/jpeg,image/png,image/webp" multiple required>
                </label>
            @else
                <label class="upload-zone">
                    <span class="upload-icon">↑</span>
                    <b>Select agent-system screenshot</b>
                    <small>Clear JPEG, PNG or WebP image showing agent ID, username, amount and timestamp.</small>
                    <input type="file" name="screenshot" accept="image/jpeg,image/png,image/webp" required>
                </label>
            @endif

            <div class="alert info">
                <b>No empty drafts.</b> Leaving this page without uploading does not create a transaction or an entry in your history.
            </div>
            <button class="btn btn-primary btn-lg" type="submit">Upload & Analyze Evidence →</button>
        </form>
    </section>
</div>
@endsection
