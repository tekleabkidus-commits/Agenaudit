@extends('layouts.app')
@section('title','Agents')
@section('topbar','Agents')
@section('content')

<div class="page-head">
    <div>
        <span class="eyebrow">MASTER DATA</span>
        <h2>Agent Directory</h2>
        <p>Agent ID and username are globally unique across every brand.</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-outline" href="{{ route('admin.commissions.index') }}">Commission Controls</a>
        <a class="btn btn-outline" href="{{ route('admin.agent-imports.index') }}">Bulk Import</a>
        <a class="btn btn-primary" href="#add-single-agent">+ Add Agent</a>
    </div>
</div>

<div class="card filter-card">
    <form class="filters" method="get">
        <input class="input" name="q" value="{{ request('q') }}" placeholder="Search Agent ID or username">
        <select class="select" name="brand">
            <option value="">All brands</option>
            @foreach($brands as $brand)
                <option value="{{ $brand->id }}" @selected(request('brand')==$brand->id)>{{ $brand->name }}</option>
            @endforeach
        </select>
        <button class="btn btn-primary">Filter</button>
        @if(request()->hasAny(['q','brand']))<a class="btn btn-ghost" href="{{ route('admin.agents.index') }}">Reset</a>@endif
    </form>
</div>

<div class="grid grid-2" style="margin-top:16px">
    <section class="card elevated" id="add-single-agent">
        <div class="card-title-row">
            <div><span class="eyebrow">QUICK CREATE</span><h3>Add Single Agent</h3></div>
            <span class="icon-bubble">+</span>
        </div>
        <p class="small muted">For bulk creation, use the Excel importer. This form creates one agent immediately.</p>

        <form class="stack" method="post" action="{{ route('admin.agents.store') }}">
            @csrf
            <div class="form-grid">
                <div class="field">
                    <label>Brand</label>
                    <select class="select" name="brand_id" id="new-agent-brand" required>
                        @foreach($brands as $brand)
                            <option value="{{ $brand->id }}"
                                data-commission-enabled="{{ $brand->commission_enabled ? '1' : '0' }}"
                                data-commission-limit="{{ $brand->commission_monthly_limit }}">
                                {{ $brand->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="field"><label>Agent ID</label><input class="input" name="agent_id" required></div>
                <div class="field"><label>Agent username</label><input class="input" name="username" required></div>
                <div class="field"><label>Credit limit</label><input class="input" type="number" step=".01" min="0" name="credit_limit" placeholder="Unlimited if blank"></div>
                <div class="field"><label>Credit due days</label><input class="input" type="number" min="0" max="365" name="credit_due_days" placeholder="Use platform default"></div>
                <div class="field"><label>Commission uses / month</label><input class="input" type="number" min="1" max="31" name="commission_monthly_limit" id="new-agent-commission-limit" value="{{ $brands->first()?->commission_monthly_limit ?? 2 }}"></div>
            </div>

            <div class="toggle-grid">
                <label class="setting-row">
                    <span><b>Credit enabled</b><small>Allow Give Credit for this agent.</small></span>
                    <span class="modern-switch compact"><input type="checkbox" name="credit_enabled" value="1" checked><span class="modern-switch-ui"></span></span>
                </label>
                <label class="setting-row">
                    <span><b>Commission Deposit</b><small>Admin-controlled. Employee can use it only while ON.</small></span>
                    <span class="modern-switch compact"><input type="hidden" name="commission_enabled" value="0"><input type="checkbox" id="new-agent-commission-enabled" name="commission_enabled" value="1" @checked($brands->first()?->commission_enabled)><span class="modern-switch-ui"></span></span>
                </label>
            </div>

            <button class="btn btn-primary btn-lg">Create Agent</button>
        </form>
    </section>

    <section class="card">
        <div class="card-title-row">
            <div><span class="eyebrow">ACTIVE DIRECTORY</span><h3>Agents</h3></div>
            <span class="count-pill">{{ $agents->total() }}</span>
        </div>

        <div class="table-wrap">
            <table class="table modern-table">
                <thead><tr><th>Agent</th><th>Brand</th><th>Outstanding credit</th><th>Commission</th><th></th></tr></thead>
                <tbody>
                @forelse($agents as $agent)
                    <tr>
                        <td>
                            <div class="entity-cell">
                                <div class="entity-avatar">{{ strtoupper(substr($agent->username,0,1)) }}</div>
                                <div><b>{{ $agent->agent_id }}</b><div class="tiny muted">{{ $agent->username }}</div></div>
                            </div>
                        </td>
                        <td><span class="brand-chip">{{ $agent->brand->name }}</span></td>
                        <td><b>{{ number_format($agent->outstanding_credit,2) }}</b> <span class="tiny muted">ETB</span></td>
                        <td>
                            <span class="status-pill {{ $agent->commission_enabled && $agent->brand->commission_enabled ? 'success' : 'neutral' }}">
                                {{ $agent->brand->commission_enabled ? ($agent->commission_enabled ? 'ON' : 'Agent OFF') : 'Brand OFF' }}
                            </span>
                        </td>
                        <td><a class="btn btn-sm btn-outline" href="{{ route('admin.agents.edit',$agent) }}">Manage</a></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="empty">No agents found.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        {{ $agents->links() }}
    </section>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const brand = document.getElementById('new-agent-brand');
    const enabled = document.getElementById('new-agent-commission-enabled');
    const limit = document.getElementById('new-agent-commission-limit');

    brand?.addEventListener('change', function () {
        const selected = brand.selectedOptions[0];
        if (!selected) return;
        enabled.checked = selected.dataset.commissionEnabled === '1';
        limit.value = selected.dataset.commissionLimit || '2';
    });
});
</script>
@endsection
