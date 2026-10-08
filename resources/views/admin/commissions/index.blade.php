@extends('layouts.app')
@section('title','Commission Management')
@section('topbar','Commission Management')
@section('content')

<div class="page-head">
    <div>
        <span class="eyebrow">AGENT CONTROLS</span>
        <h2>Commission Deposit Access</h2>
        <p>Control commissions across an entire brand or fine-tune individual agents. Brand OFF always blocks payouts even if an agent switch is ON.</p>
    </div>
</div>

<section class="card" style="margin-bottom:18px">
    <div class="card-title-row">
        <div>
            <span class="eyebrow">BRAND-WIDE CONTROLS</span>
            <h3>Bulk commission settings</h3>
            <p class="small muted">Configure a brand master switch and monthly allowance. You can apply the policy to all its agents or keep individual settings unchanged.</p>
        </div>
    </div>
    <div class="stack" style="margin-top:14px">
        @forelse($brands as $brand)
            <details class="commission-brand-panel">
                <summary>
                    <span class="entity-avatar">{{ strtoupper(substr($brand->name,0,1)) }}</span>
                    <span class="activity-copy">
                        <b>{{ $brand->name }}</b>
                        <small>{{ $brand->agents_count }} agents · {{ $brand->commissioned_agents_count }} individually enabled · {{ $brand->commission_monthly_limit }} uses/month default</small>
                    </span>
                    <span class="status-pill {{ $brand->commission_enabled ? 'success' : 'neutral' }}">
                        {{ $brand->commission_enabled ? 'Brand ON' : 'Brand OFF' }}
                    </span>
                    <span class="type-arrow">⌄</span>
                </summary>
                <form class="stack" method="post" action="{{ route('admin.commissions.brands.update',$brand) }}">
                    @csrf
                    @method('PUT')
                    <label class="modern-switch">
                        <input type="checkbox" name="commission_enabled" value="1" @checked($brand->commission_enabled)>
                        <span class="modern-switch-ui"></span>
                        <span><b>Brand commission master switch</b><small>When OFF, nobody in this brand can complete a commission deposit.</small></span>
                    </label>
                    <div class="field">
                        <label>Default monthly commission uses per agent</label>
                        <input class="input" type="number" name="commission_monthly_limit" min="1" max="31" required
                            value="{{ $brand->commission_monthly_limit }}">
                    </div>
                    <label class="check">
                        <input type="checkbox" name="apply_to_agents" value="1" checked>
                        <span>Apply ON/OFF and monthly limit to all {{ $brand->agents_count }} existing agents in this brand</span>
                    </label>
                    <p class="tiny muted">Unchecked: only updates the brand policy and defaults for newly imported agents. Existing agent-specific settings remain intact. This action does not alter historical transactions.</p>
                    <button type="submit" class="btn btn-primary">Save brand commission settings</button>
                </form>
            </details>
        @empty
            <div class="empty">Create a brand first to configure commissions.</div>
        @endforelse
    </div>
</section>

<div class="card" style="margin-bottom:16px">
    <form class="filters" method="get">
        <input class="input" name="q" value="{{ request('q') }}" placeholder="Search agent ID or username">
        <select class="select" name="brand">
            <option value="">All brands</option>
            @foreach($brands as $brand)
                <option value="{{ $brand->id }}" @selected(request('brand')==$brand->id)>{{ $brand->name }}</option>
            @endforeach
        </select>
        <select class="select" name="status">
            <option value="">All commission states</option>
            <option value="enabled" @selected(request('status')==='enabled')>Enabled</option>
            <option value="disabled" @selected(request('status')==='disabled')>Disabled</option>
        </select>
        <button class="btn btn-primary">Apply filters</button>
    </form>
</div>

<div class="stack">
    @forelse($agents as $agent)
        @php
            $used = (int) $agent->commission_used_this_month;
            $limit = max(1, (int) $agent->commission_monthly_limit);
            $remaining = max(0, $limit - $used);
            $percent = min(100, round(($used / $limit) * 100));
        @endphp

        <section class="card commission-control-card">
            <div class="commission-control-head">
                <div>
                    <div class="tiny muted">{{ $agent->brand->name }}</div>
                    <h3 style="margin:3px 0 0">{{ $agent->agent_id }} · {{ $agent->username }}</h3>
                </div>
                <span class="status-pill {{ $agent->commission_enabled && $agent->brand->commission_enabled ? 'success' : 'neutral' }}">
                    {{ $agent->commission_enabled && $agent->brand->commission_enabled ? 'Commission ON' : ($agent->brand->commission_enabled ? 'Agent OFF' : 'Brand OFF') }}
                </span>
            </div>

            <div class="commission-stat-grid">
                <div class="mini-stat">
                    <span>Used this month</span>
                    <b>{{ $used }}</b>
                </div>
                <div class="mini-stat">
                    <span>Monthly limit</span>
                    <b>{{ $limit }}</b>
                </div>
                <div class="mini-stat">
                    <span>Remaining</span>
                    <b>{{ $remaining }}</b>
                </div>
            </div>

            <div class="progress-track" aria-label="Commission usage">
                <span style="width:{{ $percent }}%"></span>
            </div>

            <form method="post" action="{{ route('admin.commissions.update',$agent) }}" class="commission-actions">
                @csrf
                @method('PUT')

                <label class="modern-switch">
                    <input type="checkbox" name="commission_enabled" value="1" @checked($agent->commission_enabled)>
                    <span class="modern-switch-ui"></span>
                    <span>
                        <b>Allow Commission Deposit</b>
                        <small>Employee can use Commission Deposit only while this is ON.</small>
                    </span>
                </label>

                <div class="field" style="min-width:150px">
                    <label>Uses per month</label>
                    <input class="input" type="number" name="commission_monthly_limit" min="1" max="31" value="{{ $limit }}" required>
                </div>

                <button class="btn btn-primary">Save</button>
                <a class="btn btn-outline" href="{{ route('admin.agents.edit',$agent) }}">Open Agent</a>
            </form>
        </section>
    @empty
        <div class="card empty">No agents match these filters.</div>
    @endforelse
</div>

{{ $agents->links() }}
@endsection
