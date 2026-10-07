@extends('layouts.app')
@section('title','Import Review')
@section('topbar','Import Review')
@section('content')

<div class="page-head">
    <div>
        <span class="eyebrow">IMPORT PREVIEW</span>
        <h2>{{ $import->original_name }}</h2>
        <p>Review every validation result before committing changes to the agent master list.</p>
    </div>
    <a class="btn btn-ghost" href="{{ route('admin.agent-imports.index') }}">← Back to Imports</a>
</div>

<div class="metric-grid metric-grid-4">
    <section class="metric-card"><span>Total rows</span><strong>{{ $import->total_rows }}</strong></section>
    <section class="metric-card accent-green"><span>Valid</span><strong>{{ $import->valid_rows }}</strong></section>
    <section class="metric-card accent-red"><span>Errors</span><strong>{{ $import->error_rows }}</strong></section>
    <section class="metric-card accent-blue"><span>New / Moves</span><strong>{{ $import->new_rows }} <small>new</small> · {{ $import->move_rows }} <small>moves</small></strong></section>
</div>

@if($import->error_rows>0)
    <div class="alert error" style="margin-top:16px">
        <b>Import cannot be confirmed.</b> Correct every red row in the Excel file and upload it again. Unknown/inactive brands, duplicate IDs/usernames, and cross-agent conflicts are rejected.
    </div>
@elseif(in_array($import->status,['preview','preview_with_errors'],true))
    <section class="card import-confirm-card" style="margin-top:16px">
        <div>
            <span class="eyebrow">READY TO COMMIT</span>
            <h3>Validation passed</h3>
            <p class="small muted">This action adds new agents and keeps globally unique identities intact.</p>
        </div>
        <form method="post" action="{{ route('admin.agent-imports.confirm',$import) }}" class="import-confirm-actions">
            @csrf
            @if($import->move_rows>0)
                <label class="check"><input type="checkbox" name="allow_moves" value="1" required> I confirm {{ $import->move_rows }} existing agent brand move(s).</label>
            @endif
            <button class="btn btn-success btn-lg">Confirm Import</button>
        </form>
    </section>
@endif

<section class="card" style="margin-top:16px">
    <div class="card-title-row"><div><span class="eyebrow">ROW VALIDATION</span><h3>Preview</h3></div><span class="status-pill {{ $import->error_rows?'danger':'success' }}">{{ $import->error_rows?$import->error_rows.' error(s)':'Clean' }}</span></div>

    <div class="table-wrap">
        <table class="table modern-table">
            <thead><tr><th>Row</th><th>Brand</th><th>Agent ID</th><th>Username</th><th>Action</th><th>Status</th><th>Message</th></tr></thead>
            <tbody>
            @foreach($import->rows as $row)
                <tr class="{{ $row->status==='error'?'row-error':'' }}">
                    <td>{{ $row->row_number }}</td>
                    <td>{{ $row->brand_name ?: '—' }}</td>
                    <td><b>{{ $row->agent_id ?: '—' }}</b></td>
                    <td>{{ $row->agent_username ?: '—' }}</td>
                    <td>{{ $row->action ? str($row->action)->title() : '—' }}</td>
                    <td><span class="status-pill {{ $row->status==='valid'?'success':'danger' }}">{{ str($row->status)->title() }}</span></td>
                    <td>
                        @if($row->errors)
                            <span class="error-copy">{{ implode('; ',$row->errors) }}</span>
                        @else
                            <span class="muted">Ready</span>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</section>
@endsection
