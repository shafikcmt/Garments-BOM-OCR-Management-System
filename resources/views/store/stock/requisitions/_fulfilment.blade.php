{{--
    Stock Summary — Current Stock vs Purchased vs Pending, one item per row.

    Answers what the Purchase Requisition list and the Receiving report each
    answer half of: what was ASKED for, what ARRIVED, what is on the SHELF, and
    therefore what is still outstanding. Shared by the requisition screen, the
    Store dashboard, the department dashboards and Management / Admin, so all of
    them quote one set of figures from one service.

    The only difference between those places is the SCOPE passed in, which
    RequisitionFulfilmentService decides from the user; the caption says which
    it is showing.

    Cards and table are the Stock Report's own tiles and gx-report-table, not a
    new visual language.

    Expects:
      $fulfilment  payload from RequisitionFulfilmentService::summary()
      $periodLabel what period the figures cover, in words
      $limit       rows to show before saying how many are left (null = all)
      $moreUrl     where "View Requisitions" goes, or null to omit it
--}}
@php
    $limit = $limit ?? null;
    $moreUrl = $moreUrl ?? null;

    $rows = $fulfilment['rows'];
    $totals = $fulfilment['totals'];
    $shown = $limit ? $rows->take($limit) : $rows;

    // Trailing zeros trimmed: a store counts in pieces far more often than in
    // four decimal places, and "1,200" reads faster than "1200.0000".
    $qty = fn ($v) => $v === null
        ? '—'
        : (rtrim(rtrim(number_format((float) $v, 4, '.', ','), '0'), '.') ?: '0');
@endphp

<div class="gx-stock-card-head">
    <h5 class="mb-0">
        Stock Summary
        <span class="badge bg-primary-subtle text-primary ms-1">{{ number_format($totals['items']) }} items</span>
    </h5>
    @if($moreUrl)
        <a href="{{ $moreUrl }}" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-list-ul me-1" aria-hidden="true"></i>View Requisitions
        </a>
    @endif
</div>

<p class="text-muted small mb-3">
    {{ $periodLabel }} ·
    {{ $fulfilment['scoped'] ? 'Your own requisitions' : 'All departments' }} ·
    Submitted requisitions only, drafts excluded.
</p>

@if($rows->isEmpty())
    <div class="gx-stock-empty">
        <span class="gx-stock-empty-icon"><i class="bi bi-clipboard-check" aria-hidden="true"></i></span>
        <div class="gx-stock-empty-title">Nothing requested in this period</div>
        <div class="gx-stock-empty-hint">Raise a requisition, or widen the filter, to see it here.</div>
    </div>
@else
    {{-- The Stock Report's tile row, four across. --}}
    <div class="row g-3 mb-4">
        @foreach([
            ['label' => 'Requested Qty', 'value' => $qty($totals['requested']), 'tone' => 'primary', 'icon' => 'bi-clipboard-check'],
            ['label' => 'Current Stock', 'value' => $qty($totals['stock_in_hand']), 'tone' => 'secondary', 'icon' => 'bi-box-seam'],
            ['label' => 'Purchased Qty', 'value' => $qty($totals['purchased']), 'tone' => 'success', 'icon' => 'bi-truck'],
            ['label' => 'Pending Qty', 'value' => $qty($totals['pending']), 'tone' => 'warning', 'icon' => 'bi-hourglass-split'],
        ] as $tile)
            <div class="col-6 col-xl-3">
                <div class="card gx-stock-card h-100">
                    <div class="gx-stock-tile-body">
                        <span class="gx-stock-tile-icon bg-{{ $tile['tone'] }}-subtle text-{{ $tile['tone'] }}">
                            <i class="bi {{ $tile['icon'] }}" aria-hidden="true"></i>
                        </span>
                        <div>
                            <div class="gx-stock-tile-label">{{ $tile['label'] }}</div>
                            <div class="gx-stock-tile-value">{{ $tile['value'] }}</div>
                        </div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="table-responsive">
        <table class="table align-middle mb-0 gx-report-table">
            <thead>
                <tr>
                    <th class="text-center" style="width:48px;">SL</th>
                    <th style="min-width:220px;">Item Name</th>
                    <th>UOM</th>
                    <th class="text-end">Requested</th>
                    <th class="text-end">Current Stock</th>
                    <th class="text-end">Purchased</th>
                    <th class="text-end">Pending</th>
                    <th style="min-width:140px;">Status</th>
                </tr>
            </thead>
            <tbody>
                {{-- Sorted by what is still outstanding, so the items somebody
                     has to chase are the ones at the top. --}}
                @foreach($shown as $i => $row)
                    <tr>
                        <td class="text-center text-muted small">{{ $i + 1 }}</td>
                        <td class="fw-semibold text-slate-900">
                            {{ $row['item_name'] }}
                            @unless($row['in_master'])
                                {{-- A department asked for something the master
                                     has never carried. Said here because the
                                     Store has to add it before anything can
                                     ever be received against it. --}}
                                <span class="badge bg-warning-subtle text-warning-emphasis ms-1">
                                    {{ \App\Models\PurchaseRequisitionItem::NOT_IN_MASTER_NOTE }}
                                </span>
                            @endunless
                        </td>
                        <td class="small">{{ $row['uom'] ?: '—' }}</td>
                        <td class="text-end">{{ $qty($row['requested']) }}</td>
                        <td class="text-end">{{ $qty($row['stock_in_hand']) }}</td>
                        <td class="text-end">{{ $qty($row['purchased']) }}</td>
                        <td class="text-end fw-semibold">{{ $qty($row['pending']) }}</td>
                        <td class="small">
                            @if($row['pending'] > 0)
                                <span class="badge bg-warning-subtle text-warning-emphasis">
                                    <i class="bi bi-hourglass-split me-1" aria-hidden="true"></i>Pending
                                </span>
                            @elseif($row['over_received'] > 0)
                                {{-- Pending is floored at zero, so the excess is
                                     said here rather than shown as a negative. --}}
                                <span class="badge bg-info-subtle text-info-emphasis">
                                    <i class="bi bi-box-arrow-in-down me-1" aria-hidden="true"></i>Received
                                    {{ $qty($row['over_received']) }} extra
                                </span>
                            @else
                                <span class="badge bg-success-subtle text-success">
                                    <i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Complete
                                </span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="3" class="text-end gx-stock-total-label">Total-</td>
                    <td class="text-end fw-bold">{{ $qty($totals['requested']) }}</td>
                    <td class="text-end fw-bold">{{ $qty($totals['stock_in_hand']) }}</td>
                    <td class="text-end fw-bold">{{ $qty($totals['purchased']) }}</td>
                    <td class="text-end fw-bold">{{ $qty($totals['pending']) }}</td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    </div>

    @if($limit && $rows->count() > $limit)
        <p class="text-muted small mb-0 mt-3">
            Showing the {{ $limit }} items with the most outstanding.
            {{ number_format($rows->count() - $limit) }} more not listed.
        </p>
    @endif

    @if(($totals['not_in_master'] ?? 0) > 0)
        <p class="text-warning-emphasis small mb-0 mt-2">
            <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>
            {{ number_format($totals['not_in_master']) }} requested item(s) are not in the Item Master yet.
            Add them before recording a receipt against them.
        </p>
    @endif

    {{-- Receiving is recorded against a challan, never against a requisition,
         so this compares totals of the same item in the same period — not a
         delivery traced back to the request that prompted it. Said plainly
         because a reader would otherwise assume the stronger claim. --}}
    <p class="text-muted small mb-0 mt-2">
        Purchased is what the Store received of each item in this period, from the Receiving records.
        Current Stock is today's position, as the Stock Report shows it.
    </p>
@endif
