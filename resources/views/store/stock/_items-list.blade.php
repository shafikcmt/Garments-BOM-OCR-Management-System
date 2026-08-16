{{--
    Item Master list — the part that changes when a filter changes.

    Rendered twice: inline by store.stock.items on a full page load, and on its
    own by the same controller action when the JS asks for ?partial=1. Same
    Blade both ways, so a live search and a full reload can never show the same
    item differently.

    The per-item Edit modals live in here too, not just the table. They are
    keyed by item id, so a swapped-in row whose modal had been left behind would
    have an Edit button pointing at nothing.

    The formatters and the status map are declared here rather than inherited:
    on an AJAX render there is no parent view.
--}}
@php
    use App\Services\GeneralStockReportService as StockStatus;

    $statusMeta = [
        StockStatus::STATUS_OUT => ['label' => 'Out of Stock', 'badge' => 'bg-danger text-white', 'tone' => 'danger'],
        StockStatus::STATUS_PLACE_ORDER => ['label' => 'Place Order', 'badge' => 'bg-danger-subtle text-danger', 'tone' => 'danger'],
        StockStatus::STATUS_LOW => ['label' => 'Low Stock', 'badge' => 'bg-warning-subtle text-warning-emphasis', 'tone' => 'warning'],
        StockStatus::STATUS_OK => ['label' => 'Ok', 'badge' => 'bg-success-subtle text-success', 'tone' => 'success'],
    ];

    $qty = fn ($v) => $v === null ? '—' : rtrim(rtrim(number_format((float) $v, 4), '0'), '.');

    $activeFilters = collect($filters)->filter(fn ($v) => $v !== null && $v !== '')->all();
    $hasFilters = $activeFilters !== [];

    // Figures that live outside this fragment — the toolbar's item count and
    // its status chips. Both count the whole filtered master rather than the
    // page, so they travel with the fragment or they go stale as soon as a
    // filter narrows the list.
    $meta = [
        'total' => $items->total(),
        'chips' => collect([StockStatus::STATUS_OUT, StockStatus::STATUS_PLACE_ORDER, StockStatus::STATUS_LOW])
            ->mapWithKeys(fn ($chip) => [$chip => (int) ($statusCounts[$chip] ?? 0)])
            ->all(),
    ];
@endphp

<div data-list-meta="{{ json_encode($meta) }}" hidden></div>

<div class="table-responsive">
    <table class="table align-middle gx-stock-table">
        <thead>
            <tr>
                <th>Item</th>
                <th>UOM</th>
                <th class="text-end">Current Stock</th>
                <th class="text-end">Safety / Re-order</th>
                <th>Status</th>
                <th class="text-end gx-stock-actions">Action</th>
            </tr>
        </thead>
        <tbody>
            @forelse($items as $item)
                {{-- Lifetime balance: counted opening + everything
                     received − everything issued. --}}
                @php
                    $current = (float) $item->opening_qty + (float) $item->purchased_qty - (float) $item->issued_qty;

                    $status = StockStatus::statusFor(
                        $current,
                        $item->safety_stock_qty !== null ? (float) $item->safety_stock_qty : null,
                        $item->reorder_level !== null ? (float) $item->reorder_level : null,
                    );

                    // Only drawn against a real re-order level. Where
                    // that is blank the level is calculated later from
                    // consumption, and a bar against a denominator we
                    // invented here would read as fact.
                    $reorder = $item->reorder_level !== null ? (float) $item->reorder_level : null;
                    $fill = $reorder > 0 ? max(0, min(100, ($current / $reorder) * 100)) : null;
                @endphp
                <tr @class(['table-danger' => $status === StockStatus::STATUS_OUT])>
                    <td>
                        <div class="fw-bold text-slate-900">{{ $item->name }}</div>
                        {{-- Category · Brand/Specification on one line,
                             blanks dropped so a sparse item does not
                             read as a row of dashes. Set with the
                             section's shared secondary-detail size,
                             the same as the Stock Report. --}}
                        <div class="gx-stock-micro">
                            {{ collect([$item->category, $item->brand])->filter()->implode(' · ') ?: '—' }}
                        </div>
                    </td>
                    <td class="small">{{ $item->uom ?: '—' }}</td>
                    <td class="text-end">
                        <div class="fw-bold text-slate-900">{{ $qty($current) }}</div>
                        @if($fill !== null)
                            <div class="gx-stock-health gx-stock-health--{{ $statusMeta[$status]['tone'] }} ms-auto"
                                 title="{{ $qty($current) }} against a re-order level of {{ $qty($reorder) }}">
                                <i style="width:{{ $fill }}%;"></i>
                            </div>
                        @endif
                    </td>
                    <td class="text-end small text-muted">
                        {{ $item->safety_stock_qty !== null ? $qty($item->safety_stock_qty) : '—' }}
                        /
                        {{ $item->reorder_level !== null ? $qty($item->reorder_level) : '—' }}
                    </td>
                    <td>
                        <div class="gx-stock-pills">
                            <span class="badge {{ $statusMeta[$status]['badge'] }}">{{ $statusMeta[$status]['label'] }}</span>
                            {{-- An inactive item still has a stock level, so
                                 the two are shown together rather than one
                                 replacing the other. Beside the status
                                 rather than under it — on its own line it
                                 set the height of every row in the table
                                 for the sake of the rare inactive one. --}}
                            @unless($item->is_active)
                                <span class="badge bg-secondary-subtle text-secondary">Inactive</span>
                            @endunless
                        </div>
                    </td>
                    {{-- Edit and Delete are Admin / Management rights
                         (store.edit / store.delete); both controller
                         methods enforce the same check server-side. --}}
                    <td class="text-end gx-stock-actions">
                        @if($canEdit)
                            <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editItem{{ $item->id }}"><i class="bi bi-pencil me-1" aria-hidden="true"></i>Edit</button>
                        @endif
                        @if($canDelete)
                            <form method="POST" action="{{ route('store.stock.items.destroy', $item) }}" class="d-inline" onsubmit="return confirm('Remove this item?');">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash me-1" aria-hidden="true"></i>Delete</button>
                            </form>
                        @endif
                        @if(! $canEdit && ! $canDelete)
                            <span class="text-muted small">—</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="gx-stock-empty">
                        <span class="gx-stock-empty-icon"><i class="bi bi-{{ $hasFilters ? 'search' : 'box-seam' }}" aria-hidden="true"></i></span>
                        @if($hasFilters)
                            <div class="gx-stock-empty-title">No items match this filter</div>
                            <div class="gx-stock-empty-hint">Try a different search, category or status.</div>
                        @else
                            <div class="gx-stock-empty-title">No stock items yet</div>
                            <div class="gx-stock-empty-hint">Use Add Item above, or import a list.</div>
                        @endif
                    </td></tr>
            @endforelse
        </tbody>
    </table>
</div>

{{-- Unconditional and un-tweaked, exactly as this screen rendered it before:
     adding AJAX should not change how the pager looks. The project's own
     pagination.gx view draws it. --}}
<div class="mt-3">{{ $items->links() }}</div>

{{-- Edit. Same fields, same grouping, same partial as Add. Not rendered at
     all for a role that cannot submit it, so the markup carries no action a
     non-admin could replay.

     Inside the swapped fragment on purpose: these are keyed by item id, so
     after a filter changes the rows the modals have to change with them. --}}
@if($canEdit)
    @foreach($items as $item)
        <div class="modal fade" id="editItem{{ $item->id }}" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content gx-stock-card">
                    <form method="POST" action="{{ route('store.stock.items.update', $item) }}">
                        @csrf @method('PUT')
                        <div class="modal-header">
                            <h5 class="modal-title">Edit Item</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            @include('store.stock._item-fields', ['item' => $item])
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary">Update</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endforeach
@endif
