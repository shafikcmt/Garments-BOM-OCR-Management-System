@extends('layouts.app')

@section('title', 'General Stock — Purchase Requisition')

@php
    $editing = isset($requisition);
    $old = fn ($key, $fallback = null) => old($key, $fallback);

    // A DEPARTMENT sheet has its section, head, contact and User Dept. fixed
    // by who is raising it (DepartmentRequisitionDefaults); the Store's own
    // FINAL sheet fixes nothing and is the form exactly as it always was.
    // $hideFigures is a separate question — who is LOOKING — so a Store user
    // correcting a department sheet still sees stock and cost.
    $isDepartmentForm = $defaults->isDepartment();
    $fixSection = $defaults->fixesSection();
    $fixHead = $defaults->fixesHead();
    $lockedUserDept = $isDepartmentForm ? $defaults->userDept() : null;
    $hideFigures = $hideFigures ?? false;

    // Lines to render on first paint: whatever validation bounced back, else
    // the saved lines when editing a draft, else one blank line.
    $existingLines = collect(old('items', $editing
        ? $requisition->items->map(fn ($i) => [
            // Posted back so the line is updated in place, keeping its id.
            'id' => $i->id,
            'stock_item_id' => $i->stock_item_id,
            // Reopens a typed line as a typed line rather than an empty picker.
            'custom_item_name' => $i->custom_item_name,
            'specification' => $i->specification,
            'type' => $i->type,
            'user_dept' => $i->user_dept,
            // "10", not "10.0000" — the column keeps four decimals.
            'qty_requested' => \App\Support\Quantity::plain($i->qty_requested),
            'rate_appx' => $i->rate_appx,
            'remarks' => $i->remarks,
        ])->all()
        : []));
@endphp

@section('content')
<div class="container-fluid gx-stock-scope">
    <x-breadcrumb :items="[
        ['label' => 'Store', 'url' => \App\Models\PurchaseRequisition::seesEveryRequisition(auth()->user()) ? route('store.dashboard') : null],
        ['label' => 'General Stock'],
        ['label' => 'Purchase Requisition', 'url' => route('store.stock.requisitions.index')],
        ['label' => $editing ? 'Edit' : 'New'],
    ]" />

    <div class="app-hero-card p-4 mb-4">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div class="d-flex align-items-center gap-3">
                <span class="app-stat-icon gx-stock-hero-icon"><i class="bi bi-clipboard-check" aria-hidden="true"></i></span>
                <div>
                    <div class="app-hero-eyebrow">General Stock</div>
                    <h3 class="app-hero-title mb-0">{{ $editing ? 'Edit Requisition' : 'New Purchase Requisition' }}</h3>
                    <p class="app-hero-copy mb-0">
                        @if($hideFigures)
                            Pick each item and the quantity you need. The Store checks stock and pricing once it receives the request.
                        @else
                            Stock, consumption and last purchase are filled in for you as you pick each item.
                        @endif
                    </p>
                </div>
            </div>
            <a href="{{ route('store.stock.requisitions.index') }}" class="btn btn-outline-secondary">
                <i class="bi bi-list-ul me-1" aria-hidden="true"></i>{{ \App\Models\PurchaseRequisition::listLabelFor(auth()->user()) }}
            </a>
        </div>
    </div>

    @include('store.stock._stock-ui')
    @include('store._flash')

    {{-- Anything that would stop this sheet being submitted — an approver
         Admin has not set, or the raiser holding a stage — said before the
         form is filled rather than after. --}}
    @if(! empty($workflowProblems))
        <div class="alert alert-warning border-0 shadow-sm rounded-3">
            <div class="fw-semibold mb-1"><i class="bi bi-exclamation-triangle-fill me-1" aria-hidden="true"></i>This requisition cannot be submitted yet.</div>
            <ul class="mb-0">
                @foreach($workflowProblems as $problem)
                    <li>{{ $problem }}</li>
                @endforeach
            </ul>
            <div class="small mt-1">You can still save it as a draft.</div>
        </div>
    @endif

    <form method="POST"
          action="{{ $editing ? route('store.stock.requisitions.update', $requisition) : route('store.stock.requisitions.store') }}"
          id="requisitionForm">
        @csrf
        @if($editing) @method('PUT') @endif

        {{-- One form, any number of lines — the Single/Multiple toggle is gone
             because "Multiple" with one line was already the same requisition.
             Posted as multi always, including when editing a requisition that
             was raised under the old single mode: the server trims a "single"
             requisition to its first line, so leaving the old value here would
             silently drop lines added on this screen. --}}
        <input type="hidden" name="mode" id="requisitionMode" value="multi">

        <div class="card gx-stock-card mb-4">
            <div class="gx-stock-card-body">

                <h5 class="mb-3">Requisition Details</h5>

                <div class="row g-3 mb-4">
                    <div class="col-12 col-md-4 col-xl-3">
                        <label class="form-label" for="requisitionNo">Requisition No</label>
                        {{-- Editable on purpose: a paper requisition that already
                             carries a number can be entered as-is. The unique
                             index on the column is what stops a duplicate. --}}
                        <input type="text" name="requisition_no" id="requisitionNo" maxlength="100"
                               class="form-control @error('requisition_no') is-invalid @enderror"
                               value="{{ $old('requisition_no', $editing ? $requisition->requisition_no : $nextNo) }}">
                        @error('requisition_no')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="gx-stock-help">
                            @if($isDepartmentForm)
                                Numbered under your section — change it only if your document already has a number.
                            @else
                                Suggested for the chosen section — change it if your document already has a number.
                            @endif
                        </div>
                    </div>

                    <div class="col-6 col-md-4 col-xl-2">
                        <label class="form-label" for="requisitionDate">Requisition Date <span class="text-danger">*</span></label>
                        <input type="date" name="requisition_date" id="requisitionDate" required
                               class="form-control @error('requisition_date') is-invalid @enderror"
                               value="{{ $old('requisition_date', $editing ? $requisition->requisition_date->toDateString() : now()->toDateString()) }}">
                        @error('requisition_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-6 col-md-4 col-xl-2">
                        <label class="form-label" for="sectionSelect">Section</label>
                        @if($fixSection)
                            {{-- The requester's own section, set on Admin >
                                 Users. Shown, not chosen: the server writes
                                 the same value whatever is posted. --}}
                            <input type="text" id="sectionSelect" class="form-control gx-stock-readonly" value="{{ $defaults->section->name }}" readonly tabindex="-1">
                            <input type="hidden" name="indent_section_id" value="{{ $defaults->section->id }}">
                        @else
                            <select name="indent_section_id" id="sectionSelect" class="form-select js-searchable">
                                <option value="">All</option>
                                @foreach($sections as $s)
                                    <option value="{{ $s->id }}" @selected($old('indent_section_id', $editing ? $requisition->indent_section_id : null) == $s->id)>{{ $s->name }}</option>
                                @endforeach
                            </select>
                            @if($isDepartmentForm)
                                <div class="gx-stock-help">No section is set on your account yet — ask your administrator to set it under Admin &gt; Users.</div>
                            @endif
                        @endif
                    </div>

                    <div class="col-6 col-md-6 col-xl-2">
                        <label class="form-label" for="personSelect">Requested By</label>
                        @if($fixHead)
                            {{-- The head the sheet goes to — section head, else
                                 department head — from Admin > Settings >
                                 Departments. Nothing is posted: the head is
                                 resolved again on save and on print. --}}
                            <input type="text" id="personSelect" class="form-control gx-stock-readonly" value="{{ $defaults->head->name }}" readonly tabindex="-1">
                            <div class="gx-stock-help">{{ $defaults->headTitle() }}</div>
                        @else
                            <select name="indent_person_id" id="personSelect" class="form-select js-searchable">
                                <option value="">Select…</option>
                                @foreach($persons as $p)
                                    <option value="{{ $p->id }}" @selected($old('indent_person_id', $editing ? $requisition->indent_person_id : null) == $p->id)>{{ $p->name }}</option>
                                @endforeach
                            </select>
                        @endif
                    </div>

                    <div class="col-6 col-md-6 col-xl-3">
                        <label class="form-label" for="contact">Contact No. / Email</label>
                        @if($fixHead)
                            <input type="text" id="contact" class="form-control gx-stock-readonly" value="{{ $defaults->contact() }}" readonly tabindex="-1">
                        @else
                            <input type="text" name="contact" id="contact" maxlength="255"
                                   class="form-control" value="{{ $old('contact', $editing ? $requisition->contact : null) }}">
                        @endif
                    </div>
                </div>

                {{-- ----------------------------------------------------------
                     Item lines — a card each rather than one 19-column row.
                     The fields the requester actually types sit on top at full
                     size; the figures fetched from /requisitions/item-snapshot
                     are read-only facts, so they are shown as muted chips along
                     the bottom instead of as inputs nobody can edit.
                     ---------------------------------------------------------- --}}
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                    <div>
                        <h6 class="gx-stock-subhead mb-0">Items</h6>
                        <span class="gx-stock-help">Pick a category to narrow the item list, then choose the item. Tab out of the last line's Remarks to start the next line.</span>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-primary js-add-line" id="addRequisitionLine">
                        <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add Another Item
                    </button>
                </div>

                @error('items')<div class="alert alert-danger py-2">{{ $message }}</div>@enderror

                <div id="requisitionLines" class="gx-req-lines"></div>

                {{-- The same button again, under the last line card. A
                     requisition can run to a dozen items, and each card is tall,
                     so the top button was a long scroll away by item three. Same
                     handler as it — see the .js-add-line binding below. --}}
                <div class="mt-3">
                    <button type="button" class="btn btn-sm btn-outline-primary js-add-line">
                        <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add Another Item
                    </button>
                </div>

                {{-- Amount is the Store's figure; a department user's form has
                     no rate to total. The element stays (hidden) so the script
                     that writes it needs no second code path. --}}
                <div class="gx-req-total" @if($hideFigures) hidden @endif>
                    <span class="gx-req-total-label">Total Amount</span>
                    <span class="gx-req-total-value" id="grandTotal">0.00</span>
                </div>

                <div class="row g-3 mt-3">
                    <div class="col-12 col-xl-8">
                        <label class="form-label" for="remarks">Remarks</label>
                        <input type="text" name="remarks" id="remarks" maxlength="2000" class="form-control"
                               value="{{ $old('remarks', $editing ? $requisition->remarks : null) }}"
                               placeholder="Anything the approver should know">
                    </div>
                </div>

                <div class="d-flex flex-wrap align-items-center gap-3 mt-4 pt-3 border-top">
                    {{-- Draft keeps it editable; Submit hands it to the store
                         lead and locks the lines. --}}
                    <button type="submit" name="action" value="draft" class="btn btn-outline-secondary">
                        <i class="bi bi-save me-1" aria-hidden="true"></i>Save as Draft
                    </button>
                    <button type="submit" name="action" value="submit" class="btn btn-primary">
                        <i class="bi bi-send me-1" aria-hidden="true"></i>Submit Requisition
                    </button>
                    <p class="gx-stock-help mb-0" style="max-width:620px;">
                        @if($hideFigures)
                            A draft stays editable. Once submitted, the request goes forward for checking and the Store
                            fills in stock and pricing.
                        @else
                            A draft stays editable. Once submitted, the stock and last-purchase figures are frozen on the
                            requisition so it always shows the position that justified it.
                        @endif
                    </p>
                </div>
            </div>
        </div>
    </form>

    {{-- Line template. Cloned by the script below, with __INDEX__ replaced by
         the line's array index. --}}
    <template id="requisitionLineTemplate">
        <div class="gx-req-line">
            <div class="gx-req-line-head">
                <span class="gx-req-line-no js-line-number"></span>
                <button type="button" class="btn btn-sm btn-outline-danger js-line-remove">
                    <i class="bi bi-trash me-1" aria-hidden="true"></i>Remove
                </button>
            </div>

            {{-- What the requester types — one row per line, in the order the
                 requester works through it: Item Name leads because it is what
                 they actually came to choose, then what describes it (Uom,
                 Specification, Category), then what is being asked for and for
                 whom, and the figures last.

                 Category doubles as the filter that narrows the item list, so
                 it can also be set BEFORE the item, from anywhere in the row —
                 its position here does not change that. --}}
            <div class="gx-req-fields">
                <input type="hidden" class="js-line-id" name="items[__INDEX__][id]" value="">
                <div class="gx-req-field is-item">
                    <label class="form-label">Item Name <span class="text-danger">*</span></label>
                    <select class="form-select form-select-sm js-line-item js-searchable" name="items[__INDEX__][stock_item_id]" required>
                        <option value="">Select item…</option>
                        @foreach($items as $it)
                            <option value="{{ $it->id }}"
                                    data-uom="{{ $it->uom }}"
                                    data-brand="{{ $it->brand }}"
                                    data-category-id="{{ $it->item_category_id }}">{{ $it->name }}@if($it->brand) ({{ $it->brand }})@endif</option>
                        @endforeach
                    </select>

                    {{-- Asking for something the Item Master has never carried.
                         A SEPARATE box rather than a "create" option on the
                         dropdown, on purpose: a typed name here must NOT add a
                         row to the Item Master. What belongs in the master has
                         a category, a UOM and a re-order level behind it, and
                         that is a Store decision taken afterwards — so the two
                         are kept visibly apart.

                         The select keeps its own markup and its own TomSelect
                         untouched, which is what leaves item search, UOM
                         auto-fill, the category filter and the stock chips
                         behaving exactly as before on a master line. --}}
                    <div class="gx-req-custom">
                        <div class="form-check">
                            <input class="form-check-input js-line-custom-toggle" type="checkbox"
                                   id="customToggle__INDEX__">
                            <label class="form-check-label" for="customToggle__INDEX__">Not in Item Master</label>
                        </div>
                        <input type="text" class="form-control form-control-sm js-line-custom-name"
                               name="items[__INDEX__][custom_item_name]" maxlength="255"
                               placeholder="Type the item name" hidden>
                    </div>
                </div>
                <div class="gx-req-field is-uom">
                    <label class="form-label">Uom</label>
                    {{-- Derived from the item, never posted. --}}
                    <input type="text" class="form-control form-control-sm js-line-uom gx-stock-readonly text-center" readonly tabindex="-1" placeholder="—">
                </div>
                <div class="gx-req-field is-spec">
                    <label class="form-label">Specification</label>
                    <input type="text" class="form-control form-control-sm js-line-spec" name="items[__INDEX__][specification]" maxlength="255" placeholder="—">
                </div>
                <div class="gx-req-field is-category">
                    <label class="form-label">Category</label>
                    {{-- Two jobs: narrows the item list, and shows the item's own
                         category once one is chosen. The category SAVED always
                         follows the item, so this stays a helper either way. --}}
                    <select class="form-select form-select-sm js-line-category">
                        <option value="">All categories</option>
                        @foreach($categories as $c)
                            <option value="{{ $c->id }}">{{ $c->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="gx-req-field is-type">
                    <label class="form-label">Type</label>
                    {{-- Defaults to New, because most requested lines are. It is
                         a plain <select>, so the requester changes it to Replace
                         the same way as before, and "—" is still there for a
                         line that is neither. The default is written HERE, on
                         the template every row is cloned from, so the first row,
                         an "Add Another Item" row and a row that came back from
                         validation all agree. Restoring a saved line overrides
                         it — see addLine(). --}}
                    <select class="form-select form-select-sm" name="items[__INDEX__][type]">
                        <option value="">—</option>
                        @foreach($typeLabels as $value => $label)
                            <option value="{{ $value }}" @selected($value === \App\Models\PurchaseRequisitionItem::TYPE_NEW)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="gx-req-field is-dept">
                    <label class="form-label">User Dept.</label>
                    @if($lockedUserDept !== null)
                        {{-- A department sheet is for the requester's own
                             section on every line. Locked here and written by
                             the server regardless of what is posted. --}}
                        <input type="text" class="form-control form-control-sm gx-stock-readonly" name="items[__INDEX__][user_dept]" value="{{ $lockedUserDept }}" readonly tabindex="-1">
                    @else
                        <input type="text" class="form-control form-control-sm" name="items[__INDEX__][user_dept]" maxlength="255" placeholder="—">
                    @endif
                </div>
                <div class="gx-req-field is-qty">
                    <label class="form-label">Qty Req. <span class="text-danger">*</span></label>
                    <input type="number" step="0.0001" min="0.0001" class="form-control form-control-sm js-line-qty" name="items[__INDEX__][qty_requested]" required placeholder="0">
                </div>
                {{-- Rate is the Store's to set. Hidden — not removed — for a
                     department user, so the script's recalc still finds it;
                     the server drops whatever such a user posts in it. --}}
                <div class="gx-req-field is-rate" @if($hideFigures) hidden @endif>
                    <label class="form-label">Rate Appx.</label>
                    <input type="number" step="0.0001" min="0" class="form-control form-control-sm js-line-rate" name="items[__INDEX__][rate_appx]" placeholder="0.00" @if($hideFigures) tabindex="-1" @endif>
                </div>
                <div class="gx-req-field is-remarks">
                    <label class="form-label">Remarks</label>
                    <input type="text" class="form-control form-control-sm js-line-remarks" name="items[__INDEX__][remarks]" maxlength="1000" placeholder="Optional">
                </div>
            </div>

            {{-- Read-only facts and the two derived results. Chips rather than
                 disabled inputs: nothing here is typed into, and boxes that
                 look editable but are not is what made the old row confusing.
                 Hidden for a department user: stock, last purchase, shortfall
                 and amount are the Store's knowledge, and the snapshot
                 endpoint sends them nothing to fill these with anyway. --}}
            <div class="gx-req-line-foot" @if($hideFigures) hidden @endif>
                <div class="gx-req-facts">
                    <div class="gx-req-fact-group">
                        <span class="gx-req-group-label">Stock</span>
                        <span class="gx-req-fact"><em>In Hand</em><b class="js-line-stock">—</b></span>
                        <span class="gx-req-fact"><em>Safety</em><b class="js-line-safety">—</b></span>
                        <span class="gx-req-fact"><em>Cons. L/M</em><b class="js-line-consumption">—</b></span>
                    </div>
                    <div class="gx-req-fact-group">
                        <span class="gx-req-group-label">Last Purchase</span>
                        <span class="gx-req-fact"><em>Qty</em><b class="js-line-lpqty">—</b></span>
                        <span class="gx-req-fact"><em>Rate</em><b class="js-line-lprate">—</b></span>
                        <span class="gx-req-fact"><em>Date</em><b class="js-line-lpdate">—</b></span>
                    </div>
                </div>
                <div class="gx-req-results">
                    <span class="gx-req-result"><em>To Be Procured</em><b class="js-line-tbp">0</b></span>
                    <span class="gx-req-result is-amount"><em>Amount</em><b class="js-line-amount">0.00</b></span>
                </div>
            </div>
        </div>
    </template>

    @include('store.stock._searchable')

    <style>
        /* ------------------------------------------------------------------
         * One card per item line. Replaces a 19-column table that could only
         * be used by scrolling sideways: the columns are now rows of ordinary
         * full-size fields, so the form reflows on a narrow screen instead.
         * ------------------------------------------------------------------ */
        .gx-req-lines { display: flex; flex-direction: column; gap: 1rem; }

        .gx-req-line {
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            background: #fff;
            padding: 1rem 1.1rem 0.9rem;
        }
        .gx-req-line:focus-within { border-color: #bfdbfe; box-shadow: 0 0 0 .2rem rgba(37, 99, 235, .06); }

        .gx-req-line-head {
            display: flex; align-items: center; justify-content: space-between;
            gap: .75rem; margin-bottom: .85rem;
        }
        .gx-req-line-no {
            display: inline-flex; align-items: center; justify-content: center;
            min-width: 26px; height: 26px; padding: 0 .45rem;
            border-radius: 8px; background: #eef4ff; color: #1d4ed8;
            font-size: .78rem; font-weight: 800;
        }

        .gx-req-line .form-label {
            font-size: .68rem; text-transform: uppercase; letter-spacing: .04em;
            font-weight: 750; color: #64748b; margin-bottom: .25rem;
            white-space: nowrap;
        }

        /* ------------------------------------------------------------------
         * The nine typed fields of a line. A grid rather than Bootstrap's
         * 12-column row: nine fields do not divide into twelve, and these need
         * genuinely different widths — Item Name is the field people read,
         * Uom is three characters.
         *
         * It steps down instead of scrolling sideways, so a smaller screen
         * wraps to two or three tidy rows rather than hiding fields off-page.
         * ------------------------------------------------------------------ */
        .gx-req-fields { display: grid; gap: .55rem .6rem; grid-template-columns: repeat(2, minmax(0, 1fr)); }
        @media (min-width: 768px)  { .gx-req-fields { grid-template-columns: repeat(4, minmax(0, 1fr)); } }
        @media (min-width: 1200px) { .gx-req-fields { grid-template-columns: repeat(6, minmax(0, 1fr)); } }

        /* One row for all nine, once there is room to do it without cramming. */
        @media (min-width: 1500px) {
            .gx-req-fields {
                /* In the order the fields are typed, which is the order they
                   appear in the markup above. Each keeps the width it has
                   always had; only their positions moved. */
                grid-template-columns:
                    minmax(0, 2.1fr)   /* Item Name */
                    64px               /* Uom */
                    minmax(0, 1.35fr)  /* Specification */
                    minmax(0, 1.2fr)   /* Category */
                    minmax(0, .95fr)   /* Type */
                    minmax(0, 1.1fr)   /* User Dept. */
                    minmax(0, .95fr)   /* Qty Req. */
                    minmax(0, .95fr)   /* Rate Appx. */
                    minmax(0, 1.3fr);  /* Remarks */
            }
        }

        /* The "Not in Item Master" toggle and its typed name, under the item
           picker. Small and quiet: it is the exception, not the normal path. */
        .gx-req-custom { margin-top: .3rem; }
        .gx-req-custom .form-check { margin-bottom: 0; min-height: auto; }
        .gx-req-custom .form-check-label { font-size: .72rem; color: #64748b; }
        .gx-req-custom .form-check-input { margin-top: .2rem; }
        .gx-req-custom .form-control { margin-top: .3rem; }

        /* min-width:0 on the field itself as well: a grid item defaults to
           min-content, and TomSelect's widest option would otherwise stretch
           the Item Name column past its share and squeeze the rest. */
        .gx-req-field { min-width: 0; }
        .gx-req-field .form-control,
        .gx-req-field .form-select,
        .gx-req-field .ts-wrapper { width: 100%; }

        /* The auto-filled figures. Muted and compact on purpose — they are
           evidence for the decision, not fields to fill in. */
        .gx-req-line-foot {
            display: flex; flex-wrap: wrap; align-items: center;
            justify-content: space-between; gap: .75rem 1.25rem;
            margin-top: .9rem; padding-top: .75rem;
            border-top: 1px dashed #e2e8f0;
        }
        .gx-req-facts { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem 1.1rem; }
        .gx-req-fact-group { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem .8rem; }
        .gx-req-fact-group + .gx-req-fact-group {
            padding-left: 1.1rem; border-left: 1px solid #eef2f7;
        }
        .gx-req-group-label {
            font-size: .62rem; text-transform: uppercase; letter-spacing: .06em;
            font-weight: 800; color: #94a3b8;
        }
        .gx-req-fact { display: inline-flex; align-items: baseline; gap: .35rem; font-size: .78rem; }
        .gx-req-fact em { font-style: normal; color: #94a3b8; }
        .gx-req-fact b { color: #475569; font-weight: 700; font-variant-numeric: tabular-nums; }

        /* The two numbers the whole line exists to produce. */
        .gx-req-results { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; }
        .gx-req-result {
            display: inline-flex; align-items: baseline; gap: .4rem;
            padding: .3rem .7rem; border-radius: 9px;
            background: #f8fafc; border: 1px solid #eef2f7;
        }
        .gx-req-result em {
            font-style: normal; font-size: .66rem; text-transform: uppercase;
            letter-spacing: .04em; font-weight: 750; color: #64748b;
        }
        .gx-req-result b { font-size: .92rem; font-weight: 800; color: #0f172a; font-variant-numeric: tabular-nums; }
        .gx-req-result.is-amount { background: #eef4ff; border-color: #dbe4f0; }
        .gx-req-result.is-amount b { color: #1d4ed8; }


        .gx-req-total {
            display: flex; align-items: center; justify-content: flex-end;
            gap: .75rem; margin-top: 1rem; padding-top: .85rem;
            border-top: 1px solid #e2e8f0;
        }
        .gx-req-total-label {
            font-size: .72rem; text-transform: uppercase; letter-spacing: .05em;
            font-weight: 800; color: #64748b;
        }
        .gx-req-total-value {
            font-size: 1.1rem; font-weight: 800; color: #0f172a;
            font-variant-numeric: tabular-nums;
        }

        @media (max-width: 575.98px) {
            .gx-req-line { padding: .85rem .8rem .75rem; }
            .gx-req-fact-group + .gx-req-fact-group { padding-left: 0; border-left: 0; }
        }
    </style>

    <script>
        (function () {
            var form = document.getElementById('requisitionForm');
            if (!form) { return; }

            var body = document.getElementById('requisitionLines');
            var tpl = document.getElementById('requisitionLineTemplate');
            // Both triggers — above the line cards and under the last one.
            // Selected by class so the two can never drift apart.
            var addBtns = document.querySelectorAll('.js-add-line');
            var grand = document.getElementById('grandTotal');
            var dateField = document.getElementById('requisitionDate');

            var SNAPSHOT_URL = @json(route('store.stock.requisitions.item-snapshot', ['stockItem' => '__ID__']));
            var NEXT_NO_URL = @json(route('store.stock.requisitions.next-number'));

            var nextIndex = 0;

            function rows() {
                return Array.prototype.slice.call(body.querySelectorAll('.gx-req-line'));
            }

            function num(value) {
                var n = parseFloat(value);
                return isNaN(n) ? 0 : n;
            }

            /* Trailing zeros trimmed so a whole number reads "15", not "15.0000". */
            function qtyText(value) {
                if (value === null || value === undefined || value === '') { return '—'; }
                return String(parseFloat(parseFloat(value).toFixed(4)));
            }

            function money(value) {
                return num(value).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            }

            /* Renumber the visible line number and rewrite every items[n] name,
               so a removed line never leaves a gap in the posted array. */
            function renumber() {
                rows().forEach(function (row, i) {
                    row.querySelector('.js-line-number').textContent = i + 1;

                    row.querySelectorAll('[name]').forEach(function (field) {
                        field.name = field.name.replace(/items\[\d+\]/, 'items[' + i + ']');
                    });
                });
            }

            /* To Be Procured and Amount, previewed exactly as they will be SAVED.
             *
             * The form lets a requester add a line per department, but on save
             * those lines collapse into one line per item
             * (PurchaseRequisitionService::mergeLines) — an item has one stock
             * figure, one shortfall and one row on the document.
             *
             * So the preview groups the rows the same way the server will:
             * quantities summed per item, the first non-blank rate kept, and
             * the shortfall worked out once from the combined quantity. Every
             * row of a repeated item shows that one shared result, and the
             * Total counts it once — so what is on screen is what gets stored,
             * with no surprise when the merged document appears. */
            function recalcAll() {
                var groups = [];
                var byItem = {};

                rows().forEach(function (row, index) {
                    var itemId = row.querySelector('.js-line-item').value || '';

                    // A row with no item yet cannot merge with anything.
                    var key = itemId === '' ? 'blank-' + index : 'item-' + itemId;

                    if (!byItem[key]) {
                        byItem[key] = { qty: 0, stock: num(row.dataset.stockInHand), rate: 0, rows: [] };
                        groups.push(byItem[key]);
                    }

                    var group = byItem[key];
                    group.qty += num(row.querySelector('.js-line-qty').value);
                    group.rows.push(row);

                    // First non-blank rate wins, matching mergeLines().
                    if (!group.rate) { group.rate = num(row.querySelector('.js-line-rate').value); }
                });

                var sum = 0;

                groups.forEach(function (group) {
                    var tbp = Math.max(0, group.qty - group.stock);
                    var amount = tbp * group.rate;

                    group.rows.forEach(function (row) {
                        row.querySelector('.js-line-tbp').textContent = qtyText(tbp);
                        row.querySelector('.js-line-amount').textContent = money(amount);
                    });

                    // Counted once per item, however many rows fed it.
                    sum += amount;
                });

                grand.textContent = money(sum);
            }

            /* Kept as the name the per-row listeners call. Any one line moving
               can change another line's share of the same item's stock, so the
               whole requisition is always recomputed. */
            function recalc() {
                recalcAll();
            }

            function total() {
                recalcAll();
            }

            /* Ask the server for the item's live position. Read-only: it comes
               from the same service the Stock Report uses, so the two can never
               disagree about the same item on the same day. */
            function loadSnapshot(row) {
                var select = row.querySelector('.js-line-item');
                var id = select.value;

                if (!id) {
                    ['stock', 'safety', 'consumption', 'lpqty', 'lprate', 'lpdate'].forEach(function (key) {
                        row.querySelector('.js-line-' + key).textContent = '—';
                    });
                    row.querySelector('.js-line-uom').value = '';
                    row.dataset.stockInHand = '';
                    recalc(row);
                    return;
                }

                var url = SNAPSHOT_URL.replace('__ID__', encodeURIComponent(id));
                if (dateField && dateField.value) { url += '?on=' + encodeURIComponent(dateField.value); }

                fetch(url, { headers: { 'Accept': 'application/json' } })
                    .then(function (r) { return r.ok ? r.json() : null; })
                    .then(function (data) {
                        if (!data) { return; }

                        row.querySelector('.js-line-uom').value = data.uom || '';
                        row.querySelector('.js-line-stock').textContent = qtyText(data.stock_in_hand);
                        row.querySelector('.js-line-safety').textContent = qtyText(data.safety_stock);
                        row.querySelector('.js-line-consumption').textContent = qtyText(data.consumption_last_month);
                        row.querySelector('.js-line-lpqty').textContent = qtyText(data.last_purchase_qty);
                        row.querySelector('.js-line-lprate').textContent = data.last_purchase_rate == null ? '—' : money(data.last_purchase_rate);
                        row.querySelector('.js-line-lpdate').textContent = data.last_purchase_date || '—';

                        row.dataset.stockInHand = data.stock_in_hand === null ? '' : data.stock_in_hand;

                        // Rate defaults to the last purchase rate but stays
                        // editable, so an expected price rise can be planned in.
                        // `!= null` on purpose: a department user's payload
                        // carries no figures at all (undefined, not null), and
                        // their rate box must stay blank for the server to
                        // fill from the last purchase.
                        var rateField = row.querySelector('.js-line-rate');
                        if (!rateField.value && data.last_purchase_rate != null) {
                            rateField.value = data.last_purchase_rate;
                        }

                        applyItemMasterDefaults(row, data);

                        recalc(row);
                    })
                    .catch(function () { /* Leave the chips blank; the server fills them on save. */ });
            }

            /* Copy across what the Item Master already knows, so nothing that
               is on file has to be typed again.
             *
             * Only fields that genuinely exist on stock_items are touched:
             * Uom, Category (item_category_id) and the item master's merged
             * Brand/Specification, which pre-fills this line's Specification.
             * Type and User Dept. are NOT item attributes; they are decisions
             * about this particular request, so they stay manual.
             *
             * Nothing already typed is overwritten: a requester who asked for a
             * specific brand on this line keeps what they wrote. */
            function applyItemMasterDefaults(row, data) {
                var select = row.querySelector('.js-line-item');
                var option = select.querySelector('option[value="' + select.value + '"]');

                var spec = row.querySelector('.js-line-spec');
                if (!spec.value) {
                    var fromMaster = data.specification || (option ? option.dataset.brand : '') || '';

                    if (fromMaster) { spec.value = fromMaster; }
                }

                // The item's own category. Setting it re-runs the filter, which
                // leaves the chosen item in place because the item obviously
                // matches its own category — so this narrows the list for the
                // next edit without ever clearing the line.
                var category = row.querySelector('.js-line-category');
                var categoryId = data.item_category_id ? String(data.item_category_id) : '';

                if (categoryId && category.value !== categoryId
                    && category.querySelector('option[value="' + categoryId + '"]')) {
                    category.value = categoryId;
                    applyCategoryFilter(row);
                }
            }

            /* Switches one line between picking a master item and typing a name.
             *
             * Only ONE of the two is ever live, because only one of them can
             * name the item: the unused control is hidden AND has its value
             * cleared, so a half-filled line cannot post both and leave the
             * server to guess which the requester meant.
             *
             * The derived boxes are blanked too. Uom, the stock chips and the
             * last-purchase figures all come from the item master, and a typed
             * item has no master record — showing the previous item's numbers
             * beside a different name is how a wrong quantity gets ordered. The
             * requester keeps typing Specification, Qty and Rate themselves.
             *
             * `required` moves with the mode so the browser asks for whichever
             * field is actually in use; the server checks the same pair with
             * required_without either way. */
            function applyCustomItemMode(row) {
                var custom = row.querySelector('.js-line-custom-toggle').checked;
                var select = row.querySelector('.js-line-item');
                var nameBox = row.querySelector('.js-line-custom-name');
                var category = row.querySelector('.js-line-category');

                nameBox.hidden = !custom;
                nameBox.required = custom;
                select.required = !custom;

                // TomSelect draws its own wrapper over the select, so hiding
                // the select alone would leave the widget on screen.
                var wrapper = select.tomselect ? select.tomselect.wrapper : select;
                wrapper.hidden = custom;

                if (custom) {
                    if (select.tomselect) { select.tomselect.clear(true); } else { select.value = ''; }

                    // No master record behind this line, so nothing is known
                    // about it but what is typed on the row.
                    category.value = '';
                    row.querySelector('.js-line-uom').value = '';
                    ['.js-line-stock', '.js-line-safety', '.js-line-consumption',
                     '.js-line-lpqty', '.js-line-lprate', '.js-line-lpdate'
                    ].forEach(function (sel) { row.querySelector(sel).textContent = '—'; });

                    row.dataset.stockInHand = '';
                    recalc(row);
                } else {
                    nameBox.value = '';
                }
            }

            /* Category is a filter on the item list, exactly as on Record Issue. */
            function applyCategoryFilter(row) {
                var categoryId = row.querySelector('.js-line-category').value;
                var select = row.querySelector('.js-line-item');
                var ts = select.tomselect;

                Array.prototype.forEach.call(select.options, function (option) {
                    if (!option.value) { return; }

                    var match = !categoryId || option.dataset.categoryId === categoryId;
                    option.hidden = !match;
                    option.disabled = !match;
                });

                if (ts) {
                    var chosen = select.value;
                    ts.clearOptions();
                    Array.prototype.forEach.call(select.options, function (option) {
                        if (!option.disabled || !option.value) {
                            ts.addOption({ value: option.value, text: option.textContent });
                        }
                    });
                    ts.refreshOptions(false);

                    // The chosen item is no longer offered by this category, so
                    // the line is cleared rather than left showing a stale name.
                    if (chosen && select.querySelector('option[value="' + chosen + '"]').disabled) {
                        ts.clear(true);
                        loadSnapshot(row);
                    } else if (chosen) {
                        ts.setValue(chosen, true);
                    }
                }
            }

            function addLine(values) {
                var html = tpl.innerHTML.split('__INDEX__').join(nextIndex);
                nextIndex++;

                var holder = document.createElement('div');
                holder.innerHTML = html.trim();
                var row = holder.querySelector('.gx-req-line');
                body.appendChild(row);

                if (values) {
                    if (values.id) { row.querySelector('.js-line-id').value = values.id; }
                    if (values.stock_item_id) { row.querySelector('.js-line-item').value = values.stock_item_id; }
                    if (values.specification) { row.querySelector('.js-line-spec').value = values.specification; }
                    // Assigned even when blank, unlike its neighbours. The
                    // template defaults Type to New; a saved line that is
                    // deliberately neither New nor Replace would otherwise keep
                    // that default and be re-saved as New on the next edit.
                    // What was stored wins over the default for a restored row.
                    row.querySelector('[name$="[type]"]').value = values.type || '';
                    if (values.user_dept) { row.querySelector('[name$="[user_dept]"]').value = values.user_dept; }
                    if (values.qty_requested) { row.querySelector('.js-line-qty').value = values.qty_requested; }
                    if (values.rate_appx) { row.querySelector('.js-line-rate').value = values.rate_appx; }
                    if (values.remarks) { row.querySelector('[name$="[remarks]"]').value = values.remarks; }
                    if (values.custom_item_name) {
                        row.querySelector('.js-line-custom-name').value = values.custom_item_name;
                        row.querySelector('.js-line-custom-toggle').checked = true;
                    }
                }

                if (window.gxInitSearchable) { window.gxInitSearchable(row); }

                applyCustomItemMode(row);
                row.querySelector('.js-line-custom-toggle').addEventListener('change', function () {
                    applyCustomItemMode(row);
                });

                row.querySelector('.js-line-category').addEventListener('change', function () { applyCategoryFilter(row); });
                row.querySelector('.js-line-item').addEventListener('change', function () { loadSnapshot(row); });
                row.querySelector('.js-line-qty').addEventListener('input', function () { recalc(row); });
                row.querySelector('.js-line-rate').addEventListener('input', function () { recalc(row); });
                row.querySelector('.js-line-remove').addEventListener('click', function () {
                    // Only ask when there is something to lose — confirming an
                    // empty line every time trains people to click through it.
                    if (rowHasData(row) && ! window.confirm('Remove this item line? What you entered on it will be lost.')) {
                        return;
                    }

                    row.remove();

                    // A requisition always has at least one line to type into.
                    if (rows().length === 0) { addLine(); }

                    renumber();
                    total();
                });

                renumber();

                if (values && values.stock_item_id) { loadSnapshot(row); }

                return row;
            }

            function rowHasData(row) {
                return !!(row.querySelector('.js-line-item').value || row.querySelector('.js-line-qty').value);
            }

            addBtns.forEach(function (btn) { btn.addEventListener('click', function () { addLine(); }); });

            /*
             * Tab out of the last line's Remarks to start the next line.
             *
             * The same keystroke and the same four conditions as Record
             * Receiving and Record Issue, kept as its own copy for the same
             * reason those two are: this form has its own inline script and
             * its own row shape. A requester listing a dozen items ends each
             * line at Remarks and wants the next one without reaching for
             * the mouse.
             *
             *   plain Tab       Shift+Tab still moves backwards and never adds
             *                   a line; modifier combinations belong to the
             *                   browser.
             *   Remarks         the last field of the line; every other field
             *                   tabs within the line as before.
             *   the LAST line   an earlier line's Remarks still tabs on to
             *                   whatever follows it, unchanged.
             *   nothing pending no IME composition in progress.
             *
             * The "+ Add Another Item" buttons are untouched; all call addLine().
             */
            body.addEventListener('keydown', function (event) {
                if (event.key !== 'Tab' || event.shiftKey || event.ctrlKey || event.altKey || event.metaKey) {
                    return;
                }

                if (event.isComposing) { return; }

                var field = event.target;
                if (!field || !field.classList || !field.classList.contains('js-line-remarks')) { return; }

                var row = field.closest('.gx-req-line');
                var all = rows();
                if (!row || row !== all[all.length - 1]) { return; }

                event.preventDefault();

                var added = addLine();
                var select = added && added.querySelector('.js-line-item');
                if (!select) { return; }

                // The item picker is a TomSelect here, so focus goes to the
                // widget's own control rather than the hidden <select>.
                if (select.tomselect) {
                    select.tomselect.focus();
                } else {
                    select.focus();
                }
            });

            // Re-suggest the Requisition No when the section or date changes —
            // the serial restarts per section per month.
            function refreshNumber() {
                var field = document.getElementById('requisitionNo');
                if (!field || field.dataset.touched === '1') { return; }

                var params = new URLSearchParams();
                var section = document.getElementById('sectionSelect');
                if (section && section.value) { params.set('section', section.value); }
                if (dateField && dateField.value) { params.set('on', dateField.value); }

                fetch(NEXT_NO_URL + '?' + params.toString(), { headers: { 'Accept': 'application/json' } })
                    .then(function (r) { return r.ok ? r.json() : null; })
                    .then(function (data) { if (data && data.requisition_no) { field.value = data.requisition_no; } })
                    .catch(function () { /* Keep whatever is in the box. */ });
            }

            @if(! $editing)
                var numberField = document.getElementById('requisitionNo');

                numberField.addEventListener('input', function () { this.dataset.touched = '1'; });
                document.getElementById('sectionSelect').addEventListener('change', refreshNumber);
                if (dateField) { dateField.addEventListener('change', refreshNumber); }

                // The box shows the number so the operator knows what they are
                // about to create, but an untouched preview must NOT be posted:
                // if it were, the server would take the form's value instead of
                // calling the allocator, the counter would never advance, and
                // the next requisition would preview the same number and
                // collide on the unique index.
                //
                // Dropping the name leaves the field purely informational, so
                // the server allocates — and whoever saves second correctly
                // gets the next number rather than an error.
                form.addEventListener('submit', function () {
                    if (numberField.dataset.touched !== '1') {
                        numberField.removeAttribute('name');
                    }
                });
            @endif

            // Re-fetch every line's figures when the requisition date moves:
            // the stock position is as at that date, not today.
            if (dateField) {
                dateField.addEventListener('change', function () {
                    rows().forEach(function (row) { loadSnapshot(row); });
                });
            }

            // First paint: whatever bounced back from validation or is being
            // edited, else one empty line.
            @foreach($existingLines as $line)
                addLine(@json($line));
            @endforeach

            if (rows().length === 0) { addLine(); }
        })();
    </script>
</div>
@endsection
