@extends('layouts.app')

@section('title', 'General Stock — '.$requisition->requisition_no)

@php
    $qty = fn ($v) => $v === null ? '—' : rtrim(rtrim(number_format((float) $v, 4, '.', ','), '0'), '.');
    $money = fn ($v) => $v === null ? '—' : number_format((float) $v, 2);
    $date = fn ($v) => $v ? $v->format('d-M-y') : '—';

    $badges = [
        'draft' => 'bg-secondary-subtle text-secondary-emphasis',
        'submitted' => 'bg-primary-subtle text-primary',
        'store_reviewed' => 'bg-info-subtle text-info-emphasis',
        'approved' => 'bg-success-subtle text-success',
        'purchase_action_taken' => 'bg-dark-subtle text-dark',
    ];

    $total = $requisition->items->sum('amount');
@endphp

@section('content')
<div class="container-fluid gx-stock-scope">
    <x-breadcrumb :items="[
        ['label' => 'Store', 'url' => route('store.dashboard')],
        ['label' => 'General Stock'],
        ['label' => 'Purchase Requisition', 'url' => route('store.stock.requisitions.index')],
        ['label' => $requisition->requisition_no],
    ]" />

    <div class="app-hero-card p-4 mb-4">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div class="d-flex align-items-center gap-3">
                <span class="app-stat-icon gx-stock-hero-icon"><i class="bi bi-clipboard-check" aria-hidden="true"></i></span>
                <div>
                    <div class="app-hero-eyebrow">Purchase Requisition</div>
                    <h3 class="app-hero-title mb-0">{{ $requisition->requisition_no }}</h3>
                    <p class="app-hero-copy mb-0">
                        <span class="badge {{ $badges[$requisition->status] ?? 'bg-light text-dark' }}">{{ $requisition->statusLabel() }}</span>
                        · {{ optional($requisition->requisition_date)->format('d M Y') }}
                        · {{ $requisition->items->count() }} item(s)
                    </p>
                </div>
            </div>
            <div class="d-flex flex-wrap gap-2">
                @if($requisition->isEditable())
                    <a href="{{ route('store.stock.requisitions.edit', $requisition) }}" class="btn btn-outline-secondary">
                        <i class="bi bi-pencil me-1" aria-hidden="true"></i>Edit
                    </a>
                @endif
                @if($canDelete)
                    <form method="POST" action="{{ route('store.stock.requisitions.destroy', $requisition) }}" class="d-inline"
                          onsubmit="return confirm('Delete requisition {{ $requisition->requisition_no }} and all its item lines? This cannot be undone.');">
                        @csrf @method('DELETE')
                        <button type="submit" class="btn btn-outline-danger"><i class="bi bi-trash me-1" aria-hidden="true"></i>Delete</button>
                    </form>
                @endif
                <a href="{{ route('store.stock.requisitions.pdf', $requisition) }}" class="btn btn-outline-secondary">
                    <i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>PDF
                </a>
                <a href="{{ route('store.stock.requisitions.excel', $requisition) }}" class="btn btn-outline-secondary">
                    <i class="bi bi-file-earmark-spreadsheet me-1" aria-hidden="true"></i>Excel
                </a>
                <a href="{{ route('store.stock.requisitions.index') }}" class="btn btn-outline-secondary">
                    <i class="bi bi-list-ul me-1" aria-hidden="true"></i>All Requisitions
                </a>
            </div>
        </div>
    </div>

    @include('store.stock._stock-ui')
    @include('store._flash')

    {{-- Header block, mirroring rows 3-8 of the reference sheet. --}}
    <div class="card gx-stock-card mb-4">
        <div class="gx-stock-card-body">
            <div class="row g-4">
                <div class="col-12 col-lg-6">
                    <dl class="row gx-stock-dl">
                        <dt class="col-5">Requisition No</dt><dd class="col-7">{{ $requisition->requisition_no }}</dd>
                        <dt class="col-5">Requisition Date</dt><dd class="col-7">{{ optional($requisition->requisition_date)->format('d F Y') ?? '—' }}</dd>
                        <dt class="col-5">Name of Unit</dt><dd class="col-7">{{ $requisition->unit_name ?: '—' }}</dd>
                        <dt class="col-5">Section</dt><dd class="col-7">{{ optional($requisition->indentSection)->name ?: 'All' }}</dd>
                        <dt class="col-5">Name of User</dt><dd class="col-7">{{ optional($requisition->indentPerson)->name ?: '—' }}</dd>
                        <dt class="col-5">Contact No. / Email</dt><dd class="col-7">{{ $requisition->contact ?: '—' }}</dd>
                    </dl>
                </div>
                <div class="col-12 col-lg-6">
                    <dl class="row gx-stock-dl">
                        <dt class="col-5">Type</dt><dd class="col-7">{{ \App\Models\PurchaseRequisition::modeLabels()[$requisition->mode] ?? $requisition->mode }}</dd>
                        <dt class="col-5">Prepared by</dt><dd class="col-7">{{ optional($requisition->createdBy)->name ?: '—' }}</dd>
                        <dt class="col-5">Submitted</dt><dd class="col-7">{{ $requisition->submitted_at?->format('d-M-Y H:i') ?: '—' }}</dd>
                        <dt class="col-5">Store Acknowledgement</dt>
                        <dd class="col-7">{{ optional($requisition->storeAckBy)->name ? optional($requisition->storeAckBy)->name.' · '.$requisition->store_ack_at?->format('d-M-Y') : 'Pending' }}</dd>
                        <dt class="col-5">Accounts Acknowledgement</dt>
                        <dd class="col-7">{{ optional($requisition->accountsAckBy)->name ? optional($requisition->accountsAckBy)->name.' · '.$requisition->accounts_ack_at?->format('d-M-Y') : 'Pending' }}</dd>
                        <dt class="col-5">Approved by</dt>
                        <dd class="col-7">{{ optional($requisition->approvedBy)->name ? optional($requisition->approvedBy)->name.' · '.$requisition->approved_at?->format('d-M-Y') : 'Pending' }}</dd>
                    </dl>
                </div>
            </div>

            @if($requisition->remarks)
                <div class="alert alert-light border mt-3 mb-0 small"><strong>Remarks:</strong> {{ $requisition->remarks }}</div>
            @endif
        </div>
    </div>

    {{-- Item table, column for column with the reference sheet. --}}
    <div class="card gx-stock-card">
        <div class="gx-stock-card-body">
            <div class="gx-stock-card-head">
                <h5 class="mb-0">Items</h5>
                <span class="text-muted small">Total Amount: <strong class="text-slate-900">{{ $money($total) }}</strong></span>
            </div>

            <div class="gx-line-scroll">
                <table class="table align-middle mb-0 gx-line-table">
                    <thead>
                        <tr>
                            <th class="text-center" rowspan="2" style="width:44px;">SL</th>
                            <th rowspan="2" style="min-width:200px;">Name of Item</th>
                            <th rowspan="2">Uom</th>
                            <th rowspan="2">Category</th>
                            <th rowspan="2" style="min-width:140px;">Specification / Brand</th>
                            <th rowspan="2">Type</th>
                            <th rowspan="2" style="min-width:130px;">User Dept. / Section</th>
                            <th colspan="4" class="text-center gx-line-group">Stock Details</th>
                            <th colspan="3" class="text-center gx-line-group">Last Purchase Details</th>
                            <th colspan="3" class="text-center gx-line-group">To Be Procured</th>
                            <th rowspan="2" style="min-width:120px;">Remarks</th>
                        </tr>
                        <tr>
                            <th class="text-end">Qty Requested</th>
                            <th class="text-end">Stock in Hand</th>
                            <th class="text-end">Safety Stock</th>
                            <th class="text-end">Cons. (Last Month)</th>
                            <th class="text-end">Qty</th>
                            <th class="text-end">Rate</th>
                            <th class="text-end gx-line-date">Date</th>
                            <th class="text-end">Qty</th>
                            <th class="text-end">Rate Appx.</th>
                            <th class="text-end">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($requisition->items as $i => $line)
                            <tr>
                                <td class="text-center gx-line-no">{{ $i + 1 }}</td>
                                {{-- One row per item: the entry form's per-department
                                     lines were merged on save, so nothing here can
                                     repeat an item or its stock figure. --}}
                                <td class="fw-semibold text-slate-900">{{ optional($line->stockItem)->name ?? '—' }}</td>
                                <td class="small">{{ $line->uom_snapshot ?: '—' }}</td>
                                <td class="small text-muted">{{ optional($line->itemCategory)->name ?: '—' }}</td>
                                <td class="small">{{ $line->specification ?: '—' }}</td>
                                <td class="small">{{ $line->type ? ($typeLabels[$line->type] ?? $line->type) : '—' }}</td>
                                <td class="small gx-line-dept">{{ $line->user_dept ?: '—' }}</td>

                                <td class="text-end fw-bold">{{ $qty($line->qty_requested) }}</td>
                                <td class="text-end">{{ $qty($line->stock_in_hand_snapshot) }}</td>
                                <td class="text-end">{{ $qty($line->safety_stock_snapshot) }}</td>
                                <td class="text-end">{{ $qty($line->consumption_last_month_snapshot) }}</td>

                                <td class="text-end">{{ $qty($line->last_purchase_qty_snapshot) }}</td>
                                <td class="text-end">{{ $money($line->last_purchase_rate_snapshot) }}</td>
                                <td class="text-end small gx-line-date">{{ $date($line->last_purchase_date_snapshot) }}</td>

                                <td class="text-end fw-bold">{{ $qty($line->to_be_procured_qty) }}</td>
                                <td class="text-end">{{ $money($line->rate_appx) }}</td>
                                <td class="text-end fw-semibold">{{ $money($line->amount) }}</td>

                                <td class="small text-muted">{{ $line->remarks ?: '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="16" class="text-end gx-stock-total-label">Total-</td>
                            <td class="text-end fw-bold">{{ $money($total) }}</td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <p class="gx-stock-help mt-3 mb-0">
                Stock, safety, consumption and last purchase are the figures as at
                {{ optional($requisition->requisition_date)->format('d M Y') }} — frozen when this requisition was raised,
                so it still shows the position that justified it.
            </p>
        </div>
    </div>

    <style>
        .gx-line-scroll { overflow-x: auto; }
        .gx-line-table { border-collapse: separate; border-spacing: 0; min-width: 1750px !important; }

        .gx-line-table thead th {
            font-size: .66rem; text-transform: uppercase; letter-spacing: .04em;
            font-weight: 750; color: #64748b; background: #f8fafc;
            border-bottom: 1px solid #e2e8f0; padding: .5rem; vertical-align: bottom;
        }
        .gx-line-table thead th.gx-line-group {
            background: #eef4ff; color: #1d4ed8; font-weight: 800; text-align: center !important;
        }
        .gx-line-table thead th:first-child { border-top-left-radius: 10px; }
        .gx-line-table thead th:last-child { border-top-right-radius: 10px; }

        .gx-line-table tbody td { padding: .5rem .45rem; border-bottom: 1px solid #eef2f7; vertical-align: top; }
        .gx-line-table tbody tr:nth-child(even) td { background: #fcfdff; }
        .gx-line-no { font-size: .78rem; font-weight: 700; color: #94a3b8; }

        /* Last Purchase → Date. The table lays out on content width, and this
           column's heading is a single short word, so the column collapsed to
           about four characters and "08-Aug-26" broke across two lines into
           its neighbour. It is one atomic value: never wrap it, and reserve
           the width the value actually needs. */
        .gx-line-table th.gx-line-date,
        .gx-line-table td.gx-line-date {
            white-space: nowrap !important;
            min-width: 92px;
        }

        /* Same reasoning for every other figure in the row — a wrapped number
           is unreadable, and these are all short. */
        .gx-line-table tbody td.text-end { white-space: nowrap; }

        /* "Store/Production" — one cell listing every department that asked for
           this item, as the reference workbook writes it. Allowed to wrap; a
           requisition serving several sections would otherwise stretch the
           column past everything else. */
        .gx-line-table td.gx-line-dept { white-space: normal; min-width: 140px; }

        /* components.css centres and pills every cell of a row that holds a
           colspan cell; the tfoot total row triggers it for this table. Scoped
           back so the item rows keep the alignment they were written with. */
        .gx-line-table tbody td { text-align: left !important; }
        .gx-line-table tbody td.text-end { text-align: right !important; }
        .gx-line-table tbody td.text-center { text-align: center !important; }
    </style>
</div>
@endsection
