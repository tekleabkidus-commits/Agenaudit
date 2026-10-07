@extends('layouts.app')
@section('title','Agent Import')
@section('topbar','Agent Import')
@section('content')
<div class="page-head">
    <div>
        <h2>Excel agent import</h2>
        <p>Required columns: <span class="kbd">Brand Name</span> <span class="kbd">Agent ID</span> <span class="kbd">Agent Username</span>.</p>
    </div>
    <div class="filters">
        <a class="btn" href="{{ route('admin.agents.index') }}#add-single-agent">Add Single Agent</a>
        <a class="btn btn-primary" href="{{ route('admin.agent-imports.template') }}">Download Sample Excel</a>
    </div>
</div>

<div class="grid grid-2">
    <section class="card">
        <h3 class="section-title">Stage import</h3>
        <div class="tiny muted" style="margin-bottom:12px">
            Download the sample first if you are unsure about the format. Brand Name must match an active existing brand in Agent Audit. Unknown or inactive brands are rejected and the import cannot be confirmed until the file is corrected.
        </div>
        <form method="post" action="{{ route('admin.agent-imports.store') }}" enctype="multipart/form-data" class="stack">
            @csrf
            <div class="upload">
                <input type="file" name="file" accept=".xlsx" required>
                <p class="tiny muted">XLSX only · max 10 MB · Agent ID and Agent Username duplicates are detected globally before confirmation.</p>
            </div>
            <button class="btn btn-primary">Upload & validate</button>
        </form>
    </section>

    <section class="card">
        <h3 class="section-title">Import history</h3>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>File</th><th>Status</th><th>Rows</th><th>Errors</th><th></th></tr></thead>
                <tbody>
                @foreach($imports as $i)
                    <tr>
                        <td>{{ $i->original_name }}<div class="tiny muted">{{ $i->created_at->format('d M Y H:i') }}</div></td>
                        <td><span class="badge">{{ $i->status }}</span></td>
                        <td>{{ $i->total_rows }}</td>
                        <td>{{ $i->error_rows }}</td>
                        <td><a class="btn btn-sm" href="{{ route('admin.agent-imports.show',$i) }}">Open</a></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        {{ $imports->links() }}
    </section>
</div>
@endsection
