@extends('layouts.app')
@section('title','System Readiness')
@section('topbar','System Readiness')
@section('content')

<div class="page-head">
    <div>
        <span class="eyebrow">DEPLOYMENT & OPERATIONS</span>
        <h2>System Readiness</h2>
        <p>Safe checks of the actual environment. Configuration alone is not proof that AI, bank verification or queue workers are running.</p>
    </div>
    <a class="btn btn-outline" href="{{ route('admin.settings.edit') }}">Verification settings</a>
</div>

<div class="grid grid-2">
    @foreach($checks as $check)
        <section class="card readiness-card">
            <div class="readiness-head">
                <div>
                    <span class="eyebrow">ENVIRONMENT CHECK</span>
                    <h3>{{ $check['name'] }}</h3>
                </div>
                <span class="status-pill {{ $check['status']==='ready'?'success':($check['status']==='error' || $check['status']==='warning'?'danger':'neutral') }}">
                    {{ ucfirst($check['status']) }}
                </span>
            </div>
            <p class="muted small">{{ $check['description'] }}</p>
        </section>
    @endforeach
</div>

<section class="card" style="margin-top:18px">
    <div class="card-title-row">
        <div>
            <span class="eyebrow">PRIVATE EVIDENCE</span>
            <h3>Storage permission test</h3>
            <p class="muted small">Tests write, read and delete of one random text marker in <code>health-checks/</code>. No customer screenshot is used.</p>
        </div>
    </div>
    <form method="post" action="{{ route('admin.health.storage-test') }}">
        @csrf
        <button class="btn btn-primary">Run private storage test</button>
    </form>
    <p class="tiny muted" style="margin-top:12px">Private object storage is required for durable financial screenshots in a scalable Laravel Cloud environment. A passing test does not confirm retention, backups, worker access, or safe credential rotation.</p>
</section>

<section class="card" style="margin-top:18px">
    <div class="card-title-row">
        <div><span class="eyebrow">LAUNCH CHECKLIST</span><h3>Before processing real evidence</h3></div>
    </div>
    <div class="stack">
        <div class="status-line"><span>Deploy latest GitHub main revision in Laravel Cloud</span><b>Verify in Cloud</b></div>
        <div class="status-line"><span>Attach durable private object storage and run test above</span><b>Verify in Cloud</b></div>
        <div class="status-line"><span>Run the evidence/default queue workers</span><b>Verify in Cloud</b></div>
        <div class="status-line"><span>Test AI extraction with real bank and agent-system screenshots</span><b>Manual QA</b></div>
        <div class="status-line"><span>Validate Check.et provider behavior if enabled</span><b>Manual QA</b></div>
        <div class="status-line"><span>Run concurrency, backup/restore and final mobile usability tests</span><b>Manual QA</b></div>
    </div>
</section>
@endsection
