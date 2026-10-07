@extends('layouts.app')

@section('title', 'General Stock — '.$requisition->requisition_no)

@php
    $qty = fn ($v) => \App\Support\Quantity::format($v);
    $money = fn ($v) => $v === null ? '—' : number_format((float) $v, 2);
    $date = fn ($v) => $v ? $v->format('d-M-y') : '—';

    $badges = [
        'draft' => 'bg-secondary-subtle text-secondary-emphasis',
        'submitted' => 'bg-primary-subtle text-primary',
        'store_reviewed' => 'bg-info-subtle text-info-emphasis',
        'approved' => 'bg-success-subtle text-success',
        'purchase_action_taken' => 'bg-dark-subtle text-dark',
        'cancelled' => 'bg-danger-subtle text-danger',
    ];

    // The latest Return and the Cancel, for the banners (their reasons live
    // in the history).
    $lastReturn = $requisition->actions->firstWhere('action', \App\Models\PurchaseRequisitionAction::RETURNED);
    $cancelAction = $requisition->actions->firstWhere('action', \App\Models\PurchaseRequisitionAction::CANCELLED);

    $total = $requisition->items->sum('amount');
@endphp

@section('content')
<div class="container-fluid gx-stock-scope">
    <x-breadcrumb :items="[
        ['label' => 'Store', 'url' => \App\Models\PurchaseRequisition::seesEveryRequisition(auth()->user()) ? route('store.dashboard') : null],
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
                        {{-- progressLabel(), not statusLabel(): the internal
                             names describe the Store's workflow, and "Store
                             Reviewed" means nothing to the department that sent
                             the request in. This says "Sent to Store" /
                             "Received by Store" / "Approved". --}}
                        <span class="badge {{ $badges[$requisition->status] ?? 'bg-light text-dark' }}">{{ $requisition->progressLabel() }}</span>
                        @if($requisition->store_ack_at)
                            · received {{ $requisition->store_ack_at->format('d M Y') }}
                        @endif
                        · {{ optional($requisition->requisition_date)->format('d M Y') }}
                        · {{ $requisition->items->count() }} item(s)
                    </p>
                </div>
            </div>
            <div class="d-flex flex-wrap gap-2">
                {{-- The Store's two review steps. Each shows only at its own
                     stage and only for whoever holds its permission, so a
                     department user reading their own submission sees the
                     status and no buttons. --}}
                @include('store.stock.requisitions._review-actions', ['requisition' => $requisition])
                {{-- Editable either because it is still the requester's draft,
                     or because the Store is correcting a department's sheet on
                     the way through — the two doors authorizeEditable() opens.
                     $canCorrect carries the correction permission so a viewer
                     who could not save is not offered the button. --}}
                @if($requisition->isEditable() || (($canCorrect ?? false) && $requisition->isOpenToStoreCorrection()))
                    <a href="{{ route('store.stock.requisitions.edit', $requisition) }}" class="btn btn-outline-secondary">
                        <i class="bi bi-pencil me-1" aria-hidden="true"></i>Edit
                    </a>
                @endif
                @if($requisition->canBeCancelledBy(auth()->user()))
                    {{-- Closed for good, with a reason; stays on record. --}}
                    <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#cancelRequisition{{ $requisition->id }}">
                        <i class="bi bi-x-circle me-1" aria-hidden="true"></i>Cancel Requisition
                    </button>
                    <x-action-modal :id="'cancelRequisition'.$requisition->id"
                                    :action="route('store.stock.requisitions.cancel', $requisition)"
                                    :title="'Cancel '.$requisition->requisition_no"
                                    :required="true"
                                    placeholder="Why is this requisition being cancelled?"
                                    submit-label="Cancel Requisition" submit-icon="bi-x-circle" submit-class="btn-danger">
                        It is closed for good and no longer counted. It stays on record with its history.
                    </x-action-modal>
                @endif
                @if($canDelete)
                    {{-- A draft: its raiser or Admin, reason optional. Once
                         submitted: Admin only, reason required. --}}
                    <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteRequisition{{ $requisition->id }}">
                        <i class="bi bi-trash me-1" aria-hidden="true"></i>Delete
                    </button>
                    <x-action-modal :id="'deleteRequisition'.$requisition->id"
                                    :action="route('store.stock.requisitions.destroy', $requisition)"
                                    method="DELETE"
                                    :title="'Delete '.$requisition->requisition_no"
                                    :required="$requisition->deleteNeedsReason()"
                                    submit-label="Delete" submit-icon="bi-trash" submit-class="btn-danger"
                                    :placeholder="$requisition->deleteNeedsReason() ? 'Why is this submitted requisition being deleted?' : null">
                        The requisition and all its item lines will be removed. Its history is kept.
                    </x-action-modal>
                @endif
                <a href="{{ route('store.stock.requisitions.pdf', $requisition) }}" class="btn btn-outline-secondary">
                    <i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>PDF
                </a>
                <a href="{{ route('store.stock.requisitions.excel', $requisition) }}" class="btn btn-outline-secondary">
                    <i class="bi bi-file-earmark-spreadsheet me-1" aria-hidden="true"></i>Excel
                </a>
                <a href="{{ route('store.stock.requisitions.index') }}" class="btn btn-outline-secondary">
                    <i class="bi bi-list-ul me-1" aria-hidden="true"></i>{{ \App\Models\PurchaseRequisition::listLabelFor(auth()->user()) }}
                </a>
            </div>
        </div>
    </div>

    @include('store.stock._stock-ui')
    @include('store._flash')

    {{-- Items the department asked for that the Item Master has never
         carried. The Store decides whether they belong in the master, so
         this is offered only to a Store-side viewer who may create items
         ($promotableLines is empty for anyone else). Each button opens the
         Item Master's own Add form, seeded with the typed name, and the save
         links the line back to the new item. --}}
    @if($promotableLines->isNotEmpty())
        <div class="alert alert-warning border-0 shadow-sm rounded-3 mb-4">
            <div class="d-flex align-items-start gap-3 flex-wrap">
                <i class="bi bi-exclamation-triangle-fill fs-5" aria-hidden="true"></i>
                <div class="flex-grow-1">
                    <div class="fw-semibold mb-1">
                        {{ $promotableLines->count() }} {{ $promotableLines->count() === 1 ? 'item on this requisition is' : 'items on this requisition are' }} not in the Item Master.
                    </div>
                    <div class="small mb-2">Add each one to the master so it can be received, issued and reported like any other item. The line is linked to the new item automatically.</div>
                    <ul class="list-unstyled mb-0 d-flex flex-column gap-1">
                        @foreach($promotableLines as $line)
                            <li class="d-flex align-items-center gap-2 flex-wrap">
                                <span class="fw-semibold">{{ $line->custom_item_name }}</span>
                                @if($line->specification)<span class="text-muted small">— {{ $line->specification }}</span>@endif
                                <a href="{{ route('store.stock.items.index', ['requisition_item' => $line->id]) }}" class="btn btn-sm btn-outline-dark">
                                    <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add to Item Master
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>
    @endif

    {{-- Header block, mirroring rows 3-8 of the reference sheet. --}}
    <div class="card gx-stock-card mb-4">
        <div class="gx-stock-card-body">
            <div class="row g-4">
                <div class="col-12 col-lg-6">
                    <dl class="row gx-stock-dl">
                        <dt class="col-5">Requisition No</dt><dd class="col-7">{{ $requisition->requisition_no }}</dd>
                        <dt class="col-5">Requisition Date</dt><dd class="col-7">{{ optional($requisition->requisition_date)->format('d F Y') ?? '—' }}</dd>
                        {{-- The snapshot from the last submit; an older sheet
                             reads its own saved columns, as before. --}}
                        @php($header = app(\App\Services\RequisitionHeaderResolver::class)->forDocument($requisition, $departmentHeadName ?? null))
                        <dt class="col-5">Name of Unit</dt><dd class="col-7">{{ $header['unit_name'] ?: '—' }}</dd>
                        <dt class="col-5">Section</dt><dd class="col-7">{{ $header['section'] ?: 'All' }}</dd>
                        <dt class="col-5">Name of User</dt><dd class="col-7">{{ $header['user_name'] ?: '—' }}</dd>
                        <dt class="col-5">Contact No. / Email</dt><dd class="col-7">{{ $header['contact'] ?: '—' }}</dd>
                    </dl>
                </div>
                <div class="col-12 col-lg-6">
                    <dl class="row gx-stock-dl">
                        <dt class="col-5">Type</dt><dd class="col-7">{{ \App\Models\PurchaseRequisition::modeLabels()[$requisition->mode] ?? $requisition->mode }}</dd>
                        <dt class="col-5">Requisition Level</dt>
                        <dd class="col-7">
                            {{ \App\Models\PurchaseRequisition::levelLabels()[$requisition->level] ?? 'Final Purchase Requisition' }}
                            @if($requisition->isDepartmentLevel() && $requisition->department_role)
                                <span class="text-muted">· {{ $requisition->departmentLabel() }}</span>
                            @endif
                        </dd>
                        <dt class="col-5">Submitted</dt><dd class="col-7">{{ $requisition->submitted_at?->format('d-M-Y H:i') ?: '—' }}</dd>
                        {{-- One line per configured signature box — the same
                             boxes the PDF and Excel print, from Admin >
                             Settings. A step the system records reads
                             name · date or Pending; a box signed on paper
                             reads the configured name. --}}
                        @foreach($signatures as $box)
                            <dt class="col-5">{{ $box['label'] }}@if($box['title'] !== '') <span class="text-muted fw-normal">({{ $box['title'] }})</span>@endif</dt>
                            <dd class="col-7">
                                @if($box['signed_at'])
                                    {{ $box['name'] }} · {{ $box['signed_at']->format('d-M-Y') }}
                                @elseif($box['stamped'])
                                    {{-- A stage-engine box names who it waits for. --}}
                                    <span class="text-muted">Pending</span>@if($requisition->usesStageEngine() && $box['name'] !== '') <span class="text-muted">· {{ $box['name'] }}</span>@endif
                                @else
                                    {{ $box['name'] !== '' ? $box['name'] : '—' }}
                                @endif
                            </dd>
                        @endforeach
                    </dl>
                </div>
            </div>

            @if($requisition->isCancelled())
                <div class="alert alert-danger border-0 mt-3 mb-0 small">
                    <strong>Cancelled</strong>@if($cancelAction) by {{ $cancelAction->actorName() }} on {{ $cancelAction->created_at?->format('d-M-Y') }}: {{ $cancelAction->comment }}@endif
                    <span class="d-block mt-1">This requisition is closed and is not counted anywhere.</span>
                </div>
            @elseif($requisition->returnedToRaiser() && ! $requisition->rejectedByDepartmentHead() && $lastReturn)
                {{-- A stage-engine sheet sent back to its raiser by a later stage. --}}
                <div class="alert alert-warning border-0 mt-3 mb-0 small">
                    <strong>Returned by {{ $lastReturn->actorName() }}</strong> ({{ $lastReturn->stageLabel() }}) on {{ $lastReturn->created_at?->format('d-M-Y') }}:
                    {{ $lastReturn->comment }}
                    <span class="d-block mt-1">Amend the requisition and submit it again, or cancel it.</span>
                </div>
            @elseif($requisition->usesStageEngine() && $lastReturn && $requisition->current_stage > 1 && ($lastReturn->meta['stage_sequence'] ?? 0) > $requisition->current_stage)
                {{-- Sent back one stage, to the approver now holding it. --}}
                <div class="alert alert-warning border-0 mt-3 mb-0 small">
                    <strong>Returned by {{ $lastReturn->actorName() }}</strong> ({{ $lastReturn->stageLabel() }}) on {{ $lastReturn->created_at?->format('d-M-Y') }}:
                    {{ $lastReturn->comment }}
                </div>
            @endif

            @if($requisition->rejectedByDepartmentHead())
                <div class="alert alert-warning border-0 mt-3 mb-0 small">
                    <strong>Returned by {{ optional($requisition->headCheckedBy())->name ?: 'the '.strtolower($requisition->reviewLevelLabel()) }}</strong>
                    on {{ $requisition->headCheckedAt()?->format('d-M-Y') }}:
                    {{ $requisition->department_review_note ?: 'No reason given.' }}
                    @if($requisition->isEditable())
                        <span class="d-block mt-1">Amend the requisition and submit it again.</span>
                    @endif
                </div>
            @endif

            {{-- Step 4: where a department sheet went, and what a final sheet
                 was made from. A sheet this viewer may not open is named,
                 not linked. --}}
            @if($requisition->mergedInto)
                <div class="alert alert-info border-0 mt-3 mb-0 small">
                    <i class="bi bi-intersect me-1" aria-hidden="true"></i><strong>Merged into final requisition</strong>
                    @if($requisition->mergedInto->isVisibleTo(auth()->user()))
                        <a href="{{ route('store.stock.requisitions.show', $requisition->mergedInto) }}" class="fw-semibold">{{ $requisition->mergedInto->requisition_no }}</a>
                    @else
                        {{ $requisition->mergedInto->requisition_no }}
                    @endif
                    ({{ $requisition->mergedInto->progressLabel() }})@if($requisition->merged_at) on {{ $requisition->merged_at->format('d-M-Y') }}@endif.
                    <span class="d-block mt-1">The final requisition carries these items through Store Head, HR Audit, Finance &amp; Commercial and Operation Head approval.</span>
                </div>
            @elseif($requisition->isReadyToMerge())
                <div class="alert alert-light border mt-3 mb-0 small">
                    <i class="bi bi-hourglass-split me-1" aria-hidden="true"></i>Received by Store. Waiting to be merged into the final requisition.
                </div>
            @endif
            @if($requisition->mergedSources->isNotEmpty())
                <div class="alert alert-info border-0 mt-3 mb-0 small">
                    <i class="bi bi-intersect me-1" aria-hidden="true"></i><strong>Merged from {{ $requisition->mergedSources->count() }} department requisition{{ $requisition->mergedSources->count() === 1 ? '' : 's' }}:</strong>
                    @foreach($requisition->mergedSources as $source)
                        @if($source->isVisibleTo(auth()->user()))<a href="{{ route('store.stock.requisitions.show', $source) }}">{{ $source->requisition_no }}</a>@else{{ $source->requisition_no }}@endif{{ $source->indentSection ? ' ('.$source->indentSection->name.')' : '' }}{{ $loop->last ? '' : ', ' }}
                    @endforeach
                </div>
            @endif

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
                            {{-- Stock in Hand and Safety Stock figures are shown to Store (and Admin / Management) only. --}}
                            @php($showStockFigures = $showStockFigures ?? false)
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
                            @php($original = ($originalQty ?? [])[$line->id] ?? null)
                            @php($notApproved = (float) $line->qty_requested <= 0)
                            <tr @class(['gx-line-not-approved' => $notApproved])>
                                <td class="text-center gx-line-no">{{ $i + 1 }}</td>
                                {{-- One row per item: the entry form's per-department
                                     lines were merged on save, so nothing here can
                                     repeat an item or its stock figure. --}}
                                <td class="fw-semibold text-slate-900">
                                    {{ $line->displayName() }}
                                    {{-- Said on the row, so whoever reads this
                                         requisition knows the item still has to
                                         be added to the master before it can be
                                         received against. --}}
                                    @if($line->isCustomItem())
                                        <span class="badge bg-warning-subtle text-warning-emphasis ms-1">
                                            {{ \App\Models\PurchaseRequisitionItem::NOT_IN_MASTER_NOTE }}
                                        </span>
                                    @endif
                                </td>
                                <td class="small">{{ $line->uom_snapshot ?: '—' }}</td>
                                <td class="small text-muted">{{ optional($line->itemCategory)->name ?: '—' }}</td>
                                <td class="small">{{ $line->specification ?: '—' }}</td>
                                <td class="small">{{ $line->type ? ($typeLabels[$line->type] ?? $line->type) : '—' }}</td>
                                <td class="small gx-line-dept">{{ $line->user_dept ?: '—' }}</td>

                                <td class="text-end fw-bold">
                                    {{ $qty($line->qty_requested) }}
                                    {{-- Changed by an approver: the original request stays in view. --}}
                                    @if($original)
                                        <span class="gx-line-original">Requested {{ $qty($original['qty']) }}@if($original['stages']) · changed at {{ $original['stages'] }}@endif</span>
                                    @endif
                                    @if($notApproved)
                                        <span class="badge bg-secondary-subtle text-secondary-emphasis mt-1">Not approved</span>
                                    @endif
                                </td>
                                {{-- Columns stay for everyone; the figures are shown to the Store side only. --}}
                                <td class="text-end">{{ $showStockFigures ? $qty($line->stock_in_hand_snapshot) : '—' }}</td>
                                <td class="text-end">{{ $showStockFigures ? \App\Support\Quantity::safety($line->safety_stock_snapshot) : '—' }}</td>
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

    {{-- Step 3d: the person the sheet waits for may change quantities at
         their stage — up or down, 0 for "not approved" — with one reason
         for the save. Approving stays a separate click above. --}}
    @if($canChangeQty ?? false)
        <div class="card gx-stock-card mt-4" id="changeQuantities">
            <div class="gx-stock-card-body">
                <div class="gx-stock-card-head">
                    <h5 class="mb-0">Change Quantities</h5>
                    <span class="text-muted small">Raise or lower a quantity, or enter 0 if a line is not approved. Then approve the requisition.</span>
                </div>

                @if($errors->has('qty') || $errors->has('qty.*') || $errors->has('comment'))
                    <div class="alert alert-warning py-2 small">
                        {{ $errors->first('qty') ?: ($errors->first('qty.*') ?: $errors->first('comment')) }}
                    </div>
                @endif

                <form method="POST" action="{{ route('store.stock.requisitions.stage-quantities', $requisition) }}">
                    @csrf
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-3">
                            <thead>
                                <tr>
                                    <th style="width:44px;">SL</th>
                                    <th>Name of Item</th>
                                    <th>Uom</th>
                                    <th class="text-end">Requested</th>
                                    <th class="text-end" style="width:160px;">New Qty</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($requisition->items as $i => $line)
                                    @php($original = ($originalQty ?? [])[$line->id] ?? null)
                                    <tr>
                                        <td class="text-muted">{{ $i + 1 }}</td>
                                        <td class="fw-semibold">{{ $line->displayName() }}</td>
                                        <td class="small">{{ $line->uom_snapshot ?: '—' }}</td>
                                        <td class="text-end">{{ $qty($original['qty'] ?? $line->qty_requested) }}</td>
                                        <td>
                                            <input type="number" step="0.0001" min="0" required
                                                   class="form-control form-control-sm text-end"
                                                   name="qty[{{ $line->id }}]" aria-label="New quantity for {{ $line->displayName() }}"
                                                   value="{{ old('qty.'.$line->id, \App\Support\Quantity::plain($line->qty_requested)) }}">
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <label class="form-label fw-semibold" for="qtyChangeReason">Reason</label>
                    <textarea name="comment" id="qtyChangeReason" class="form-control mb-2" rows="2" maxlength="1000" required
                              placeholder="Why are the quantities being changed?">{{ old('comment') }}</textarea>
                    <div class="form-text mb-3">Saved in the requisition history with every line changed.</div>

                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save me-1" aria-hidden="true"></i>Save Qty Changes
                    </button>
                </form>
            </div>
        </div>
    @endif

    @include('store.stock.requisitions._history', ['requisition' => $requisition])

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

        /* Step 3d: the original request under a changed quantity, and a line
           set to 0 by an approver. */
        .gx-line-original { display: block; margin-top: .15rem; font-size: .66rem; font-weight: 600; color: #b45309; white-space: nowrap; }
        .gx-line-table tr.gx-line-not-approved td { color: #94a3b8; }

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
