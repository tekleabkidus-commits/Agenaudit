@extends('layouts.app')
@section('title','Brands')
@section('topbar','Brands')
@section('content')

<div class="page-head">
    <div>
        <span class="eyebrow">MASTER DATA</span>
        <h2>Brands</h2>
        <p>Brands are dynamic. Agents belong to one brand at a time, while Employees and receiving accounts may be assigned to multiple brands.</p>
    </div>
</div>

<div class="grid grid-2">
    <section class="card elevated">
        <div class="card-title-row"><div><span class="eyebrow">CREATE</span><h3>Add Brand</h3></div><span class="icon-bubble">◈</span></div>
        <form method="post" action="{{ route('admin.brands.store') }}" class="stack">
            @csrf
            <div class="field"><label>Brand name</label><input class="input" name="name" placeholder="Enter brand name" required></div>
            <button class="btn btn-primary btn-lg">Add Brand</button>
        </form>
    </section>

    <section class="card">
        <div class="card-title-row"><div><span class="eyebrow">DIRECTORY</span><h3>Existing Brands</h3></div><span class="count-pill">{{ $brands->total() }}</span></div>
        <div class="brand-admin-list">
            @forelse($brands as $brand)
                <form method="post" action="{{ route('admin.brands.update',$brand) }}" class="brand-admin-row">
                    @csrf @method('PUT')
                    <div class="brand-admin-icon">{{ strtoupper(substr($brand->name,0,1)) }}</div>
                    <div class="field brand-name-field"><label>Brand</label><input class="input" name="name" value="{{ $brand->name }}"></div>
                    <div class="brand-agent-count"><span>Agents</span><b>{{ number_format($brand->agents_count) }}</b></div>
                    <label class="modern-switch compact">
                        <input type="checkbox" name="is_active" value="1" @checked($brand->is_active)>
                        <span class="modern-switch-ui"></span>
                        <span class="switch-label">{{ $brand->is_active?'Active':'Inactive' }}</span>
                    </label>
                    <button class="btn btn-sm btn-outline">Save</button>
                </form>
            @empty
                <div class="empty">No brands created.</div>
            @endforelse
        </div>
        {{ $brands->links() }}
    </section>
</div>
@endsection
