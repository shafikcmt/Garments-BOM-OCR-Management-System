@extends('layouts.app')

@section('title', 'General Stock — '.$supplier->name)

@php
    $qty = fn ($v) => $v === null ? '—' : rtrim(rtrim(number_format((float) $v, 4, '.', ','), '0'), '.');
    $money = fn ($v) => $v === null ? '—' : number_format((float) $v, 2);
    $fmt = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->format('d-M-Y') : '—';
@endphp

@section('content')
<div class="container-fluid gx-stock-scope">
    <x-breadcrumb :items="[
        ['label' => 'Store', 'url' => route('store.dashboard')],
        ['label' => 'General Stock'],
        ['label' => 'Master Setup', 'url' => route('store.stock.setup', ['tab' => 'suppliers'])],
        ['label' => $supplier->name],
    ]" />

    @include('store.stock._stock-ui')

    <div class="app-hero-card p-4 mb-4">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div class="d-flex align-items-center gap-3">
                <span class="app-stat-icon gx-stock-hero-icon"><i class="bi bi-shop" aria-hidden="true"></i></span>
                <div>
                    <div class="app-hero-eyebrow">General Stock Supplier</div>
                    <h3 class="app-hero-title mb-0">{{ $supplier->name }}</h3>
                    <p class="app-hero-copy mb-0">
                        @if($supplier->is_active)
                            Active — offered in the Record Purchase dropdown.
                        @else
                            Inactive — hidden from the Record Purchase dropdown.
                        @endif
                    </p>
                </div>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a href="{{ route('store.stock.setup', ['tab' => 'suppliers']) }}" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Back to Suppliers
                </a>
            </div>
        </div>
    </div>

    @include('store._flash')

    <div class="row g-4">

        {{-- ---------------- Contact details ---------------- --}}
        <div class="col-12 col-xl-4">
            <div class="card gx-stock-card">
                <div class="gx-stock-card-body">
                    <h5 class="mb-3">Contact Details</h5>

                    @if($canEdit)
                        <form method="POST" action="{{ route('store.stock.purchase-setup.update', $supplier->id) }}">
                            @csrf @method('PUT')

                            <label class="form-label" for="name">Name <span class="text-danger">*</span></label>
                            <input id="name" name="name" value="{{ old('name', $supplier->name) }}"
                                   class="form-control mb-3 @error('name') is-invalid @enderror" maxlength="255" required>
                            @error('name')<div class="invalid-feedback d-block mb-3">{{ $message }}</div>@enderror

                            <label class="form-label" for="contact_person">Contact Person</label>
                            <input id="contact_person" name="contact_person" value="{{ old('contact_person', $supplier->contact_person) }}"
                                   class="form-control mb-3" maxlength="255">

                            <label class="form-label" for="phone">Phone</label>
                            <input id="phone" name="phone" value="{{ old('phone', $supplier->phone) }}"
                                   class="form-control mb-3" maxlength="50">

                            <label class="form-label" for="email">Email</label>
                            <input id="email" type="email" name="email" value="{{ old('email', $supplier->email) }}"
                                   class="form-control mb-3 @error('email') is-invalid @enderror" maxlength="255">
                            @error('email')<div class="invalid-feedback d-block mb-3">{{ $message }}</div>@enderror

                            <label class="form-label" for="address">Address</label>
                            <textarea id="address" name="address" rows="3" class="form-control mb-3" maxlength="1000">{{ old('address', $supplier->address) }}</textarea>

                            <div class="form-check mb-3">
                                <input type="hidden" name="is_active" value="0">
                                <input class="form-check-input" type="checkbox" name="is_active" value="1"
                                       id="is_active" {{ old('is_active', $supplier->is_active) ? 'checked' : '' }}>
                                <label class="form-check-label" for="is_active">Active (shown in the dropdown)</label>
                            </div>

                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-save me-1" aria-hidden="true"></i>Save Details
                            </button>
                        </form>
                    @else
                        {{-- Same fields, read-only, for a store user without the
                             correction right. The history below is what they came for. --}}
                        <dl class="row mb-0 small">
                            <dt class="col-5 text-muted fw-normal">Contact Person</dt>
                            <dd class="col-7">{{ $supplier->contact_person ?: '—' }}</dd>
                            <dt class="col-5 text-muted fw-normal">Phone</dt>
                            <dd class="col-7">{{ $supplier->phone ?: '—' }}</dd>
                            <dt class="col-5 text-muted fw-normal">Email</dt>
                            <dd class="col-7">{{ $supplier->email ?: '—' }}</dd>
                            <dt class="col-5 text-muted fw-normal">Address</dt>
                            <dd class="col-7">{{ $supplier->address ?: '—' }}</dd>
                        </dl>
                    @endif
                </div>
            </div>
        </div>

        {{-- ---------------- Purchase history ---------------- --}}
        <div class="col-12 col-xl-8">
            <div class="card gx-stock-card">
                <div class="gx-stock-card-body">
                    <h5 class="mb-1">Purchase History</h5>
                    <p class="gx-stock-help mb-3">
                        Every General Stock receiving recorded against this supplier. Read-only —
                        record a purchase from the Receiving screen.
                    </p>

                    @if($purchases->isNotEmpty())
                        <div class="row g-3 mb-4">
                            <div class="col-6 col-md-3">
                                <div class="gx-stock-help">Item lines</div>
                                <div class="fw-bold fs-5 text-slate-900">{{ number_format($summary['lines']) }}</div>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="gx-stock-help">Deliveries</div>
                                <div class="fw-bold fs-5 text-slate-900">{{ number_format($summary['deliveries']) }}</div>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="gx-stock-help">Total value</div>
                                <div class="fw-bold fs-5 text-slate-900">{{ $money($summary['total']) }}</div>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="gx-stock-help">Last purchase</div>
                                <div class="fw-bold fs-5 text-slate-900">{{ $fmt($summary['last_date']) }}</div>
                            </div>
                        </div>
                    @endif

                    <div class="table-responsive">
                        <table class="table gx-stock-table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Item</th>
                                    <th style="min-width:130px;">GRN No</th>
                                    <th>Challan No</th>
                                    <th>Challan Date</th>
                                    <th class="text-end">Qty</th>
                                    <th class="text-end">Rate</th>
                                    <th class="text-end">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($purchases as $purchase)
                                    <tr>
                                        <td class="fw-semibold text-slate-900">
                                            {{ optional($purchase->stockItem)->name ?: '—' }}
                                            @if(optional($purchase->stockItem)->uom)
                                                <span class="gx-stock-spec">{{ $purchase->stockItem->uom }}</span>
                                            @endif
                                        </td>
                                        <td>{{ $purchase->rv_no ?: '—' }}</td>
                                        <td class="small">{{ $purchase->challan_no ?: '—' }}</td>
                                        <td class="small">{{ $fmt($purchase->purchase_date) }}</td>
                                        <td class="text-end">{{ $qty($purchase->qty) }}</td>
                                        <td class="text-end">{{ $money($purchase->unit_price) }}</td>
                                        <td class="text-end fw-semibold">{{ $money((float) $purchase->qty * (float) $purchase->unit_price) }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="7" class="gx-stock-empty">
                                            <span class="gx-stock-empty-icon"><i class="bi bi-truck" aria-hidden="true"></i></span>
                                            <div class="gx-stock-empty-title">Nothing purchased from this supplier yet</div>
                                            <div class="gx-stock-empty-hint">
                                                Receivings recorded before the supplier list was linked keep only a
                                                typed name, so they do not appear here.
                                            </div>
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                            @if($purchases->isNotEmpty())
                                <tfoot>
                                    <tr>
                                        <td colspan="6" class="text-end gx-stock-total-label">Total-</td>
                                        <td class="text-end fw-bold">{{ $money($summary['total']) }}</td>
                                    </tr>
                                </tfoot>
                            @endif
                        </table>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>
@endsection
