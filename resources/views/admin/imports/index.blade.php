@extends('layouts.app')
@section('title','Agent Import')
@section('topbar','Agent Import')
@section('content')

<div class="page-head">
    <div>
        <span class="eyebrow">BULK MASTER DATA</span>
        <h2>Import Agents from Excel</h2>
        <p>Every row must reference an active brand that already exists in Agent Audit. Unknown or inactive brands are rejected before confirmation.</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-outline" href="{{ route('admin.agents.index') }}#add-single-agent">+ Add Single Agent</a>
        <a class="btn btn-primary" href="{{ route('admin.agent-imports.template') }}">↓ Download Sample Excel</a>
    </div>
</div>

<div class="import-step-grid">
    <section class="import-step"><span>1</span><div><b>Download template</b><small>Use exactly three required columns.</small></div></section>
    <section class="import-step"><span>2</span><div><b>Use existing brands</b><small>Brand Name must match an active brand.</small></div></section>
    <section class="import-step"><span>3</span><div><b>Upload & validate</b><small>Global IDs/usernames are checked before commit.</small></div></section>
    <section class="import-step"><span>4</span><div><b>Review & confirm</b><small>Nothing is added until the preview is clean.</small></div></section>
</div>

<div class="grid grid-2" style="margin-top:16px">
    <section class="card elevated">
        <div class="card-title-row">
            <div><span class="eyebrow">UPLOAD</span><h3>Stage Import</h3></div>
            <span class="icon-bubble">⇧</span>
        </div>

        <div class="required-columns">
            <span>Brand Name</span><span>Agent ID</span><span>Agent Username</span>
        </div>

        <form method="post" action="{{ route('admin.agent-imports.store') }}" enctype="multipart/form-data" class="stack">
            @csrf
            <label class="upload-zone">
                <span class="upload-icon">↑</span>
                <b>Choose XLSX file</b>
                <small>Maximum 10 MB · up to {{ number_format(config('agent_audit.imports.max_agent_rows',50000)) }} rows</small>
                <input type="file" name="file" accept=".xlsx" required>
            </label>

            <div class="alert info" style="margin-bottom:0">
                Agent ID and Agent Username are globally unique across all brands. Existing agents may be moved only after explicit confirmation.
            </div>

            <button class="btn btn-primary btn-lg">Upload & Validate</button>
        </form>
    </section>

    <section class="card">
        <div class="card-title-row">
            <div><span class="eyebrow">HISTORY</span><h3>Previous Imports</h3></div>
            <span class="count-pill">{{ $imports->total() }}</span>
        </div>

        <div class="table-wrap">
            <table class="table modern-table">
                <thead><tr><th>File</th><th>Status</th><th>Rows</th><th>Errors</th><th></th></tr></thead>
                <tbody>
                @forelse($imports as $import)
                    <tr>
                        <td><b>{{ $import->original_name }}</b><div class="tiny muted">{{ $import->created_at->format('d M Y H:i') }}</div></td>
                        <td><span class="status-pill {{ str_contains($import->status,'error')||$import->status==='failed'?'danger':($import->status==='completed'?'success':'warning') }}">{{ str($import->status)->replace('_',' ')->title() }}</span></td>
                        <td>{{ $import->total_rows }}</td>
                        <td><b class="{{ $import->error_rows?'red-text':'' }}">{{ $import->error_rows }}</b></td>
                        <td><a class="btn btn-sm btn-outline" href="{{ route('admin.agent-imports.show',$import) }}">Review</a></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="empty">No imports yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        {{ $imports->links() }}
    </section>
</div>
@endsection
