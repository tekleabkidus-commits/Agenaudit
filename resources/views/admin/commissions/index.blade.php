@extends('layouts.app')
@section('title','Commission Management')
@section('topbar','Commission Management')
@section('content')

<div class="page-head">
    <div>
        <span class="eyebrow">AGENT CONTROLS</span>
        <h2>Commission Deposit Access</h2>
        <p>Turn Commission Deposit on or off per agent, control monthly usage, and see remaining allowance instantly.</p>
    </div>
</div>

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
                <span class="status-pill {{ $agent->commission_enabled ? 'success' : 'neutral' }}">
                    {{ $agent->commission_enabled ? 'Commission ON' : 'Commission OFF' }}
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
