{{--
    Issue History — the consumption list, swapped wholesale by
    stock-list-table.js when a filter changes or a page is clicked.

    Everything that depends on WHICH rows are on screen has to live in here.
    That is the whole reason the file exists: the container's innerHTML is
    replaced on every search and every page, so anything keyed to an issue id
    and left outside would go on describing the previous page's rows.

    The per-issue Edit dialogs are therefore inside it, the same arrangement and
    for the same reason as _purchases-list.blade.php and _items-list.blade.php.
    Left outside, Edit on a row swapped in by a search would open a dialog built
    for a row no longer on screen — another issue's quantity, saved against it.

    What does NOT need to be here, and is deliberately left on the page:

      - The Record Issue form and its line-builder script. Scoped entirely to
        #issueForm / #issueLines / #issueStockBlock / #issueDate, which sit in
        the card ABOVE this one; Add Another Item binds its handlers per row
        inside addLine() and never reaches a history row.
      - The skipped-rows notice (#skippedRowsNotice) and its dismiss / fade-on-
        download script, which sit near the top of the page.
      - The Edit buttons themselves: Bootstrap's modal data-api is delegated
        from the document, so a button that arrives with a swapped fragment
        opens its dialog untouched.
      - The Delete confirm, an inline onsubmit attribute parsed with the HTML.

    Parameters — all supplied by StockIssueController::index()
        $issues, $filters, $canEdit, $canDelete,
        $sections, $persons, $approvers, $categories, $requisitionTypes
--}}

@php
    // Defined HERE, not inherited from the page. This partial is rendered on
    // its own for every AJAX swap, where nothing the parent view's @php block
    // set up exists — reading $qty from there worked inline and threw a 500 the
    // moment the fragment was fetched by itself, which is how it went wrong on
    // the purchases screen.
    $qty = fn ($v) => rtrim(rtrim(number_format((float) $v, 4, '.', ','), '0'), '.');

    // Drives the empty state's wording only: "nothing matched" and "nothing
    // yet" call for different next steps. Recomputed from $filters for the same
    // reason as $qty above.
    $hasFilters = collect($filters)->filter(fn ($v) => $v !== null && $v !== '')->isNotEmpty();
@endphp

{{-- Figures the screen shows outside the swapped region. --}}
<div data-list-meta="{{ json_encode(['total' => $issues->total()]) }}" hidden></div>

                    <div class="table-responsive">
                        <table class="table align-middle gx-stock-table">
                            <thead>
                                <tr>
                                    <th>Date</th><th>Section</th><th>Person</th><th>Approved By</th>
                                    <th>Req No</th><th>Type</th>
                                    <th>SKU</th>
                                    <th style="min-width:160px;">Item</th>
                                    <th>Category</th>
                                    <th class="text-end">Issued Qty</th>
                                    <th class="text-end gx-stock-actions">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($issues as $i)
                                    <tr>
                                        <td class="small">{{ optional($i->issue_date)->format('d-M-Y') ?? '—' }}</td>
                                        {{-- Fall back to the older free-text columns so
                                             records entered before the masters existed
                                             still show who indented them. --}}
                                        <td class="small">{{ optional($i->indentSection)->name ?: ($i->department ?: '—') }}</td>
                                        <td class="small">{{ optional($i->indentPerson)->name ?: ($i->issued_to ?: '—') }}</td>
                                        <td class="small text-muted">{{ optional($i->approver)->name ?: '—' }}</td>
                                        <td class="small">{{ $i->requisition_no ?: '—' }}</td>
                                        <td class="small">
                                            @if($i->requisition_type)
                                                <span class="badge bg-secondary-subtle text-secondary">{{ $i->requisition_type }}</span>
                                            @else — @endif
                                        </td>
                                        <td class="small fw-semibold text-nowrap">{{ optional($i->stockItem)->sku ?: '—' }}</td>
                                        <td><div class="fw-semibold">{{ optional($i->stockItem)->name ?? '—' }}</div></td>
                                        <td class="small text-muted">{{ optional($i->itemCategory)->name ?: (optional($i->stockItem)->category ?: '—') }}</td>
                                        <td class="text-end fw-bold">{{ $qty($i->qty) }}</td>
                                        {{-- Edit and Delete are Admin / Management
                                             rights (store.issues.edit / .delete, or
                                             the flat store.edit / store.delete); the
                                             controller enforces the same checks
                                             server-side. --}}
                                        <td class="text-end gx-stock-actions gx-row-actions">
                                            @if($canEdit)
                                                <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editIssue{{ $i->id }}"><i class="bi bi-pencil me-1" aria-hidden="true"></i>Edit</button>
                                            @endif
                                            @if($canDelete)
                                                <form method="POST" action="{{ route('store.stock.issues.destroy', $i) }}" class="d-inline" onsubmit="return confirm('Remove this issue?');">
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
                                    {{-- Says which of the two empties this is, the
                                         same as every other General Stock list:
                                         "nothing matched" and "nothing yet" call
                                         for different next steps. --}}
                                    <tr><td colspan="11" class="gx-stock-empty">
                                            <span class="gx-stock-empty-icon"><i class="bi bi-{{ $hasFilters ? 'search' : 'box-arrow-up' }}" aria-hidden="true"></i></span>
                                            <div class="gx-stock-empty-title">{{ $hasFilters ? 'No issues match this filter' : 'No issues recorded yet' }}</div>
                                            <div class="gx-stock-empty-hint">{{ $hasFilters ? 'Try a different month or search.' : 'Record an issue using the form above.' }}</div>
                                        </td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="mt-3">{{ $issues->links() }}</div>

    {{-- Correct one recorded issue. Keyed by issue id and rendered only for a
         role that can submit it, the same arrangement the Item Master edit
         modals use — markup that carries no action a non-admin could replay.

         ONE ROW, not the requisition. A requisition is several rows sharing a
         header and the table lists it a row at a time, so this edits the row the
         user clicked and leaves its siblings alone.

         The Item is shown but not editable: moving an issue to another item is
         one balance undone and another created, which is a delete and a
         re-record, not a correction. The masters are plain dropdowns rather than
         the create form's type-to-add ones — a correction is not the place to
         coin a new Section, and Issue Setup is one click away. --}}
    @if($canEdit)
        @foreach($issues as $i)
            {{-- old() is global, so without this marker a rejected edit would
                 repopulate EVERY modal on the page with the failed row's values.
                 Same idiom the Item Master modals use.

                 Written in the INLINE php form on purpose, never the block
                 form. Blade pairs raw PHP blocks by matching an opening php
                 directive to the next closing one, and this file already uses
                 the inline form further up — so one closing directive added
                 below it pairs with THAT opener and swallows the whole page
                 in between. The same reason this note spells the directives
                 out rather than writing them. --}}
            @php($formKey = 'edit:'.$i->id)
            @php($wasSubmitted = old('form') === $formKey)
            @php($value = fn ($field, $stored = null) => $wasSubmitted ? old($field, $stored) : $stored)
            @php($qtyValue = rtrim(rtrim(number_format((float) $i->qty, 4, '.', ''), '0'), '.'))
            <div class="modal fade" id="editIssue{{ $i->id }}" tabindex="-1" aria-labelledby="editIssueLabel{{ $i->id }}" aria-hidden="true">
                <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                    <div class="modal-content gx-stock-card">
                        <form method="POST" action="{{ route('store.stock.issues.update', $i) }}">
                            @csrf @method('PUT')
                            <input type="hidden" name="form" value="{{ $formKey }}">
                            <div class="modal-header">
                                <h5 class="modal-title" id="editIssueLabel{{ $i->id }}">Edit Issue</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                {{-- The refusal, where the user is looking. The page
                                     banner behind this dialog says the same thing,
                                     but it is out of sight once the modal reopens. --}}
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
                                            <span class="gx-edit-locked-value">{{ optional($i->stockItem)->name ?? '—' }}</span>
                                        </div>
                                        <div class="gx-edit-hint">
                                            Moving this issue to another item changes two stock balances, so it is a
                                            delete and a fresh entry rather than an edit.
                                        </div>
                                    </div>
                                    <div class="col-6 col-md-4">
                                        <label class="form-label" for="editIssueDate{{ $i->id }}">Issue Date <span class="text-danger">*</span></label>
                                        <input type="date" id="editIssueDate{{ $i->id }}" name="issue_date" class="form-control @if($wasSubmitted && $errors->has('issue_date')) is-invalid @endif" required
                                               value="{{ $value('issue_date', optional($i->issue_date)->toDateString()) }}">
                                    </div>
                                    <div class="col-6 col-md-4">
                                        <label class="form-label" for="editIssueQty{{ $i->id }}">Issued Qty <span class="text-danger">*</span></label>
                                        <input type="number" step="0.0001" min="0.0001" id="editIssueQty{{ $i->id }}" name="qty" class="form-control @if($wasSubmitted && $errors->has('qty')) is-invalid @endif" required
                                               value="{{ $value('qty', $qtyValue) }}">
                                        <div class="gx-edit-hint">
                                            {{ optional($i->stockItem)->uom ? 'In '.$i->stockItem->uom.'. ' : '' }}Cannot take this item below zero.
                                        </div>
                                    </div>
                                    <div class="col-12 col-md-4">
                                        <label class="form-label" for="editIssueReqNo{{ $i->id }}">Requisition Number <span class="text-danger">*</span></label>
                                        <input type="text" id="editIssueReqNo{{ $i->id }}" name="requisition_no" class="form-control @if($wasSubmitted && $errors->has('requisition_no')) is-invalid @endif" maxlength="100" required
                                               value="{{ $value('requisition_no', $i->requisition_no) }}">
                                        @if($wasSubmitted && $errors->has('requisition_no'))<div class="invalid-feedback d-block">{{ \App\Support\FieldMessage::short($errors->first('requisition_no')) }}</div>@endif
                                    </div>
                                    <div class="col-12 col-md-4">
                                        <label class="form-label" for="editIssueSection{{ $i->id }}">Indent Section <span class="text-danger">*</span></label>
                                        <select id="editIssueSection{{ $i->id }}" name="indent_section_id" class="form-select @if($wasSubmitted && $errors->has('indent_section_id')) is-invalid @endif" required>
                                            <option value="">—</option>
                                            @foreach($sections as $s)
                                                <option value="{{ $s->id }}" @selected($value('indent_section_id', $i->indent_section_id) == $s->id)>{{ $s->name }}</option>
                                            @endforeach
                                        </select>
                                        @if($wasSubmitted && $errors->has('indent_section_id'))<div class="invalid-feedback d-block">{{ \App\Support\FieldMessage::short($errors->first('indent_section_id')) }}</div>@endif
                                    </div>
                                    <div class="col-12 col-md-4">
                                        <label class="form-label" for="editIssuePerson{{ $i->id }}">Indent Person <span class="text-danger">*</span></label>
                                        <select id="editIssuePerson{{ $i->id }}" name="indent_person_id" class="form-select @if($wasSubmitted && $errors->has('indent_person_id')) is-invalid @endif" required>
                                            <option value="">—</option>
                                            @foreach($persons as $p)
                                                <option value="{{ $p->id }}" @selected($value('indent_person_id', $i->indent_person_id) == $p->id)>{{ $p->name }}</option>
                                            @endforeach
                                        </select>
                                        @if($wasSubmitted && $errors->has('indent_person_id'))<div class="invalid-feedback d-block">{{ \App\Support\FieldMessage::short($errors->first('indent_person_id')) }}</div>@endif
                                    </div>
                                    <div class="col-12 col-md-4">
                                        <label class="form-label" for="editIssueApprover{{ $i->id }}">Approved By <span class="text-danger">*</span></label>
                                        <select id="editIssueApprover{{ $i->id }}" name="issue_approver_id" class="form-select @if($wasSubmitted && $errors->has('issue_approver_id')) is-invalid @endif" required>
                                            <option value="">—</option>
                                            @foreach($approvers as $a)
                                                <option value="{{ $a->id }}" @selected($value('issue_approver_id', $i->issue_approver_id) == $a->id)>{{ $a->name }}</option>
                                            @endforeach
                                        </select>
                                        @if($wasSubmitted && $errors->has('issue_approver_id'))<div class="invalid-feedback d-block">{{ \App\Support\FieldMessage::short($errors->first('issue_approver_id')) }}</div>@endif
                                    </div>
                                    <div class="col-12 col-md-4">
                                        <label class="form-label" for="editIssueCategory{{ $i->id }}">Category</label>
                                        <select id="editIssueCategory{{ $i->id }}" name="item_category_id" class="form-select">
                                            <option value="">—</option>
                                            @foreach($categories as $c)
                                                <option value="{{ $c->id }}" @selected($value('item_category_id', $i->item_category_id) == $c->id)>{{ $c->name }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="col-12 col-md-4">
                                        <label class="form-label" for="editIssueType{{ $i->id }}">Type <span class="text-danger">*</span></label>
                                        <select id="editIssueType{{ $i->id }}" name="requisition_type" class="form-select @if($wasSubmitted && $errors->has('requisition_type')) is-invalid @endif" required>
                                            <option value="">—</option>
                                            @foreach($requisitionTypes as $type)
                                                <option value="{{ $type }}" @selected($value('requisition_type', $i->requisition_type) === $type)>{{ $type }}</option>
                                            @endforeach
                                        </select>
                                        @if($wasSubmitted && $errors->has('requisition_type'))<div class="invalid-feedback d-block">{{ \App\Support\FieldMessage::short($errors->first('requisition_type')) }}</div>@endif
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label" for="editIssueRemarks{{ $i->id }}">Remarks</label>
                                        <textarea id="editIssueRemarks{{ $i->id }}" name="remarks" class="form-control @if($wasSubmitted && $errors->has('remarks')) is-invalid @endif" rows="2" maxlength="1000">{{ $value('remarks', $i->remarks) }}</textarea>
                                        @if($wasSubmitted && $errors->has('remarks'))<div class="invalid-feedback d-block">{{ \App\Support\FieldMessage::short($errors->first('remarks')) }}</div>@endif
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1" aria-hidden="true"></i>Update Issue</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        @endforeach
    @endif
