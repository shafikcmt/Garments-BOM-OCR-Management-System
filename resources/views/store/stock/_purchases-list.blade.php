{{--
    Purchase History — the deliveries list, swapped wholesale by
    stock-list-table.js when a filter changes or a page is clicked.

    Everything that depends on WHICH rows are on screen has to live in here.
    That is the whole reason the file exists: the container's innerHTML is
    replaced on every search and every page, so anything keyed to a line id and
    left outside would go on describing the previous page's rows.

    The per-line Edit dialogs are therefore inside it, the same arrangement and
    for the same reason as _items-list.blade.php — "keyed by item id, so after a
    filter changes the rows the modals have to change with them". Left outside,
    Edit on page 2 would open a dialog built for page 1.

    What does NOT need to be here: the collapse panes and the Edit buttons are
    driven by Bootstrap's data-api, which is delegated from the document and so
    reaches markup that arrives later; the Remove confirm is an inline onsubmit
    attribute, parsed with the HTML; and the View/Hide Items label flip is a
    delegated listener on the page for the same reason.

    Parameters — all supplied by StockPurchaseController::index()
        $groups, $lines, $suppliers, $filters, $canEdit, $canDelete
--}}

@php
    // Defined HERE, not inherited from the page. This partial is rendered on
    // its own for every AJAX swap, where nothing the parent view's @php block
    // set up exists — reading $qty from there worked inline and threw a 500 the
    // moment the fragment was fetched by itself.
    $qty = fn ($v) => $v === null ? '—' : rtrim(rtrim(number_format((float) $v, 4, '.', ','), '0'), '.');
    $money = fn ($v) => $v === null ? '—' : number_format((float) $v, 2);
@endphp

{{-- Figures the screen shows outside the swapped region. --}}
<div data-list-meta="{{ json_encode(['total' => $groups->total()]) }}" hidden></div>


            <div class="table-responsive">
                <table class="table align-middle gx-stock-table">
                    <thead>
                        <tr>
                            <th style="min-width:140px;">GRN No</th>
                            <th>Challan / Inv</th>
                            <th>Challan Date</th>
                            <th>RCV Date</th>
                            <th style="min-width:150px;">Supplier</th>
                            <th class="text-end">Items</th>
                            <th class="text-end">Total Qty</th>
                            <th class="text-end">Total Value</th>
                            <th class="text-end gx-stock-actions">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($groups as $group)
                            @php
                                $groupLines = $lines[$group->group_key] ?? collect();
                                $paneId = 'rv-'.md5($group->group_key);
                                // Numbers issued before the RV was generated are bare
                                // digits; the generated ones always carry a month prefix.
                                //
                                // Four OR five digits: the sequence was narrowed from
                                // five to four, and the numbers already issued keep the
                                // width they were issued with. Matching only one width
                                // would label half the generated numbers as legacy.
                                $isLegacy = ! preg_match('/^[A-Z]{3}\d{2}-\d{4,5}$/', (string) $group->rv_no);
                            @endphp
                            <tr>
                                <td>
                                    <div class="fw-bold text-slate-900">{{ $group->rv_no ?: '—' }}</div>
                                    @if($isLegacy && $group->rv_no)
                                        <div class="gx-stock-spec">entered before GRN numbering</div>
                                    @endif
                                </td>
                                <td class="small">{{ $group->challan_no ?: '—' }}</td>
                                <td class="small">{{ $group->purchase_date ? \Illuminate\Support\Carbon::parse($group->purchase_date)->format('d-M-Y') : '—' }}</td>
                                <td class="small">{{ $group->rcv_date ? \Illuminate\Support\Carbon::parse($group->rcv_date)->format('d-M-Y') : '—' }}</td>
                                <td class="small text-muted">{{ $group->supplier_name ?: '—' }}</td>
                                <td class="text-end fw-semibold">{{ $group->line_count }}</td>
                                <td class="text-end">{{ $qty($group->group_total_qty) }}</td>
                                <td class="text-end fw-semibold">{{ $money($group->group_total_value) }}</td>
                                <td class="text-end gx-stock-actions">
                                    <button type="button" class="btn btn-sm btn-outline-primary"
                                            data-bs-toggle="collapse" data-bs-target="#{{ $paneId }}"
                                            aria-expanded="false" aria-controls="{{ $paneId }}"
                                            data-rv-toggle>
                                        <i class="bi bi-list-ul me-1" aria-hidden="true"></i><span data-rv-toggle-text>View Items</span>
                                    </button>
                                </td>
                            </tr>
                            {{-- Inline detail rather than a modal: it keeps the
                                 receiving row in view and avoids rendering 25
                                 dialogs on every page load. --}}
                            <tr class="gx-rv-detail">
                                <td colspan="9" class="p-0">
                                    <div class="collapse" id="{{ $paneId }}">
                                        <div class="gx-rv-detail-body">
                                            {{-- What the actions below reach.

                                                 A "delivery" is not a record — it is these rows,
                                                 grouped by GRN, challan and challan date. So every
                                                 action in this pane is per LINE, while two of the
                                                 fields on show above are shared by all of them. The
                                                 Edit dialog already says so once it is open; this
                                                 says it before anything is clicked, in the same
                                                 amber the dialog uses for the identical point. --}}
                                            @if($groupLines->count() > 1)
                                                <div class="gx-line-scope mb-2">
                                                    <span class="gx-edit-scope">All {{ $groupLines->count() }} items</span>
                                                    <span>
                                                        RCV Date and Supplier are shared by every item on this GRN.
                                                        Qty, Unit Price and Remarks belong to one line, and so do
                                                        Edit and Remove below.
                                                    </span>
                                                </div>
                                            @endif
                                            <table class="table align-middle mb-0 gx-line-table">
                                                <thead>
                                                    <tr>
                                                        <th style="min-width:200px;">Item</th>
                                                        <th>Uom</th>
                                                        <th class="text-end">Qty</th>
                                                        <th class="text-end">Unit Price</th>
                                                        <th class="text-end">Total</th>
                                                        <th style="min-width:140px;">Remarks</th>
                                                        <th class="text-end gx-stock-actions">Action</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    @foreach($groupLines as $line)
                                                        <tr>
                                                            <td class="fw-semibold text-slate-900">{{ optional($line->stockItem)->name ?? '—' }}</td>
                                                            <td class="small">{{ optional($line->stockItem)->uom ?: '—' }}</td>
                                                            <td class="text-end fw-bold">{{ $qty($line->qty) }}</td>
                                                            <td class="text-end small">{{ $line->unit_price !== null ? number_format((float) $line->unit_price, 2) : '—' }}</td>
                                                            <td class="text-end fw-semibold">{{ number_format($line->total_value, 2) }}</td>
                                                            <td class="small text-muted">{{ $line->remarks ?: '—' }}</td>
                                                            {{-- Edit and Delete are Admin / Management
                                                                 rights (store.receiving.edit / .delete,
                                                                 or the flat store.edit / store.delete);
                                                                 the controller enforces the same checks
                                                                 server-side. Both act on this one line,
                                                                 not the whole receiving. --}}
                                                            <td class="text-end gx-stock-actions gx-row-actions">
                                                                @if($canEdit)
                                                                    <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editPurchase{{ $line->id }}"><i class="bi bi-pencil me-1" aria-hidden="true"></i>Edit</button>
                                                                @endif
                                                                @if($canDelete)
                                                                    {{-- "Remove", not "Delete": this takes one item
                                                                         OUT OF a delivery, and Delete beside a GRN
                                                                         number reads as removing the whole thing.
                                                                         Issue History keeps "Delete" — an issue is a
                                                                         record in its own right, not part of a set.

                                                                         The confirm has to branch, because on the LAST
                                                                         line the old wording was simply untrue. There
                                                                         is no delivery record to keep: the group is
                                                                         these rows, so removing the last one takes the
                                                                         GRN and challan with it. --}}
                                                                    @php
                                                                        $itemLabel = optional($line->stockItem)->name ?? 'this item';
                                                                        $grnLabel = $line->rv_no ?: 'this receiving';
                                                                        $others = $groupLines->count() - 1;

                                                                        $confirmText = $others > 0
                                                                            ? 'Remove '.$itemLabel.' from GRN '.$grnLabel.'? The other '
                                                                                .($others === 1 ? 'item on this receiving is kept.' : $others.' items on this receiving are kept.')
                                                                            : $itemLabel.' is the only item on GRN '.$grnLabel
                                                                                .'. Removing it deletes the whole receiving, including its GRN and challan record. Continue?';
                                                                    @endphp
                                                                    <form method="POST" action="{{ route('store.stock.purchases.destroy', $line) }}" class="d-inline"
                                                                          onsubmit="return confirm(@js($confirmText));">
                                                                        @csrf @method('DELETE')
                                                                        <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash me-1" aria-hidden="true"></i>Remove</button>
                                                                    </form>
                                                                @endif
                                                                @if(! $canEdit && ! $canDelete)
                                                                    <span class="text-muted small">—</span>
                                                                @endif
                                                            </td>
                                                        </tr>
                                                    @endforeach
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="9" class="gx-stock-empty">
                                    <span class="gx-stock-empty-icon"><i class="bi bi-truck" aria-hidden="true"></i></span>
                                    <div class="gx-stock-empty-title">{{ $hasFilters ? 'No receivings match this filter' : 'No purchases recorded yet' }}</div>
                                    <div class="gx-stock-empty-hint">{{ $hasFilters ? 'Try a different month or search.' : 'Record a goods receiving above.' }}</div>
                                </td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-3">{{ $groups->links() }}</div>

{{-- Correct one line of a recorded receiving.

     Inside this partial on purpose — see the note at the top of the file. The
     dialogs are keyed by line id, so they have to be replaced whenever the rows
     are, or Edit on a freshly loaded page opens a dialog describing a row that
     is no longer on screen.

     Still rendered outside the history TABLE rather than inside the collapse
     pane each Edit button sits in: a dialog nested in a <td> inherits the
     table's own overflow and stacking, and the pane it lives in can be
     collapsed while the dialog is open.

     THREE FIELDS IDENTIFY THE DELIVERY and are shown locked — GRN No, Challan
     No and Challan Date are what group these lines into one receiving, on this
     screen and in the Receiving Report alike. Two more, RCV Date and Supplier,
     describe the delivery rather than the line and are written to every line of
     it; they are marked so nobody is surprised by a change reaching rows they
     did not open. --}}
    @if($canEdit)
        @foreach($lines->flatten() as $line)
            @php
                // old() is global; this marker keeps a rejected edit from
                // repopulating every other dialog on the page.
                $formKey = 'edit:'.$line->id;
                $wasSubmitted = old('form') === $formKey;
                $value = fn (string $field, $stored = null) => $wasSubmitted ? old($field, $stored) : $stored;
                $lineQty = rtrim(rtrim(number_format((float) $line->qty, 4, '.', ''), '0'), '.');
                $siblingCount = $lines->get($line->group_key)?->count() ?? 1;
            @endphp
            <div class="modal fade" id="editPurchase{{ $line->id }}" tabindex="-1" aria-labelledby="editPurchaseLabel{{ $line->id }}" aria-hidden="true">
                <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                    <div class="modal-content gx-stock-card">
                        <form method="POST" action="{{ route('store.stock.purchases.update', $line) }}">
                            @csrf @method('PUT')
                            <input type="hidden" name="form" value="{{ $formKey }}">
                            <div class="modal-header">
                                <h5 class="modal-title" id="editPurchaseLabel{{ $line->id }}">Edit Receiving Line</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                @if($wasSubmitted && $errors->any())
                                    <div class="gx-edit-alert mb-3" role="alert">
                                        <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
                                        <div>
                                            <div class="gx-edit-alert-title">This change was not saved</div>
                                            <div class="gx-edit-alert-body">
                                                @foreach($errors->all() as $error)
                                                    <span>{{ $error }}</span>
                                                @endforeach
                                            </div>
                                        </div>
                                    </div>
                                @endif

                                <div class="row g-3">
                                    <div class="col-12">
                                        <label class="form-label">Item<span class="gx-lock-tag">Locked</span></label>
                                        <div class="gx-edit-locked">
                                            <i class="bi bi-lock-fill" aria-hidden="true"></i>
                                            <span class="gx-edit-locked-value">{{ optional($line->stockItem)->name ?? '—' }}</span>
                                        </div>
                                    </div>

                                    <div class="col-12 col-md-4">
                                        <label class="form-label">GRN No<span class="gx-lock-tag">Locked</span></label>
                                        <div class="gx-edit-locked">
                                            <i class="bi bi-lock-fill" aria-hidden="true"></i>
                                            <span class="gx-edit-locked-value">{{ $line->rv_no ?: '—' }}</span>
                                        </div>
                                    </div>
                                    <div class="col-6 col-md-4">
                                        <label class="form-label">Challan No<span class="gx-lock-tag">Locked</span></label>
                                        <div class="gx-edit-locked">
                                            <i class="bi bi-lock-fill" aria-hidden="true"></i>
                                            <span class="gx-edit-locked-value">{{ $line->challan_no ?: '—' }}</span>
                                        </div>
                                    </div>
                                    <div class="col-6 col-md-4">
                                        <label class="form-label">Challan Date<span class="gx-lock-tag">Locked</span></label>
                                        <div class="gx-edit-locked">
                                            <i class="bi bi-lock-fill" aria-hidden="true"></i>
                                            <span class="gx-edit-locked-value">{{ optional($line->purchase_date)->format('d-M-Y') ?: '—' }}</span>
                                        </div>
                                    </div>
                                    <div class="col-12">
                                        <div class="gx-edit-hint">
                                            These three identify the delivery and group its items together. To correct
                                            one of them, delete this receiving and enter it again.
                                        </div>
                                    </div>

                                    <div class="col-6 col-md-4">
                                        <label class="form-label" for="editPurchaseQty{{ $line->id }}">Purchased Qty <span class="text-danger">*</span></label>
                                        <input type="number" step="0.0001" min="0.0001" id="editPurchaseQty{{ $line->id }}" name="qty" class="form-control @if($wasSubmitted && $errors->has('qty')) is-invalid @endif" required
                                               value="{{ $value('qty', $lineQty) }}">
                                        <div class="gx-edit-hint">
                                            {{ optional($line->stockItem)->uom ? 'In '.$line->stockItem->uom.'. ' : '' }}Cannot be cut below what has already been issued.
                                        </div>
                                    </div>
                                    <div class="col-6 col-md-4">
                                        <label class="form-label" for="editPurchasePrice{{ $line->id }}">Unit Price</label>
                                        <input type="number" step="0.0001" min="0" id="editPurchasePrice{{ $line->id }}" name="unit_price" class="form-control @if($wasSubmitted && $errors->has('unit_price')) is-invalid @endif"
                                               value="{{ $value('unit_price', $line->unit_price !== null ? rtrim(rtrim(number_format((float) $line->unit_price, 4, '.', ''), '0'), '.') : '') }}">
                                    </div>
                                    <div class="col-12 col-md-4">
                                        <label class="form-label" for="editPurchaseRcv{{ $line->id }}">
                                            RCV Date <span class="text-danger">*</span>
                                            @if($siblingCount > 1)<span class="gx-edit-scope">All {{ $siblingCount }} items</span>@endif
                                        </label>
                                        <input type="date" id="editPurchaseRcv{{ $line->id }}" name="rcv_date" class="form-control @if($wasSubmitted && $errors->has('rcv_date')) is-invalid @endif" required
                                               value="{{ $value('rcv_date', optional($line->rcv_date)->toDateString()) }}">
                                    </div>
                                    <div class="col-12 col-md-8">
                                        <label class="form-label" for="editPurchaseSupplier{{ $line->id }}">
                                            Supplier
                                            @if($siblingCount > 1)<span class="gx-edit-scope">All {{ $siblingCount }} items</span>@endif
                                        </label>
                                        <select id="editPurchaseSupplier{{ $line->id }}" name="general_stock_supplier_id" class="form-select">
                                            <option value="">—</option>
                                            @foreach($suppliers as $supplier)
                                                <option value="{{ $supplier->id }}" @selected($value('general_stock_supplier_id', $line->general_stock_supplier_id) == $supplier->id)>{{ $supplier->name }}</option>
                                            @endforeach
                                        </select>
                                        @if($siblingCount > 1)
                                            <div class="gx-edit-hint">
                                                RCV Date and Supplier describe the whole delivery, so a change here is
                                                written to all {{ $siblingCount }} items received under this GRN.
                                            </div>
                                        @endif
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label" for="editPurchaseRemarks{{ $line->id }}">Remarks</label>
                                        <textarea id="editPurchaseRemarks{{ $line->id }}" name="remarks" class="form-control" rows="2" maxlength="1000">{{ $value('remarks', $line->remarks) }}</textarea>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1" aria-hidden="true"></i>Update Line</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        @endforeach
    @endif
