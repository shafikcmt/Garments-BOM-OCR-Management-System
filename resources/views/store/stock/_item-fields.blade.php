{{--
    The twelve Item Master fields, shared by the Add and the Edit modal so the
    two can never drift apart.

    Parameters
        $item        StockItem|null — null on the Add form.
        $categories  Category master for the dropdown.

    Field order, names, validation and the "new:<name>" category convention are
    exactly as they were when this form lived in the left column. Only the
    grouping and the labels around them are new.

    Repopulating after a failed validation
        Every form on this screen posts a hidden `form` field naming itself.
        Laravel's old() is global, so without that marker a rejected Add would
        push its half-typed values into all 25 Edit modals on the page. Reading
        old() only for the form that was actually submitted keeps each modal
        showing its own row. The controller never reads `form` — validate()
        only ever returns the keys it was given.
--}}
@php
    $formKey = $item ? 'edit:'.$item->id : 'add';
    $wasSubmitted = old('form') === $formKey;

    // old() for the form the user just submitted, the stored value for the rest.
    $value = fn (string $field, $stored = null) => $wasSubmitted ? old($field, $stored) : $stored;
@endphp

<input type="hidden" name="form" value="{{ $formKey }}">

<div class="gx-stock-fieldset">
    <div class="gx-stock-fieldset-label">What the item is</div>

    <label class="form-label">Item Name <span class="text-danger">*</span></label>
    <input name="name" value="{{ $value('name', $item?->name) }}" class="form-control mb-3" required
           @if(! $item) autofocus @endif>

    <div class="row g-3 mb-3">
        <div class="col-7">
            <label class="form-label">Brand</label>
            <input name="brand" value="{{ $value('brand', $item?->brand) }}" class="form-control" maxlength="255"
                   placeholder="Organ / Groz-Beckert">
        </div>
        <div class="col-5">
            {{-- Text, not a number: "14", "M" and "40/2" are all valid sizes. --}}
            <label class="form-label">Size</label>
            <input name="size" value="{{ $value('size', $item?->size) }}" class="form-control" maxlength="100"
                   placeholder="14 / M / 40-2">
        </div>
    </div>

    <label class="form-label">Specification</label>
    <textarea name="specification" rows="2" class="form-control mb-3" maxlength="2000"
              placeholder="Any detail that separates this item from a similar one">{{ $value('specification', $item?->specification) }}</textarea>

    <div class="row g-3">
        <div class="col-5">
            <label class="form-label">UOM</label>
            <input name="uom" value="{{ $value('uom', $item?->uom) }}" class="form-control" placeholder="pcs / kg / yd">
        </div>
        <div class="col-7">
            <label class="form-label">Category</label>
            {{-- Sourced from the Category master in Issue Setup. A category
                 typed here that is not on the list is added to it. --}}
            <select name="item_category_id" class="form-select js-creatable">
                <option value="">Select or type a category…</option>
                @foreach($categories as $c)
                    <option value="{{ $c->id }}" @selected($value('item_category_id', $item?->item_category_id) == $c->id)>{{ $c->name }}</option>
                @endforeach
            </select>
        </div>
    </div>
</div>

<div class="gx-stock-fieldset">
    <div class="gx-stock-fieldset-label">Stock on hand today</div>

    {{-- Counted opening balance. Without it the Stock Report would open this
         item at zero and every closing balance after it would be short by
         whatever was already on the shelf. --}}
    <div class="row g-3">
        <div class="col-6">
            <label class="form-label">Opening Stock</label>
            <input type="number" step="0.0001" name="opening_qty" value="{{ $value('opening_qty', $item?->opening_qty) }}" class="form-control">
        </div>
        <div class="col-6">
            <label class="form-label">Counted On</label>
            <input type="date" name="opening_as_on"
                   value="{{ $value('opening_as_on', optional($item?->opening_as_on)->toDateString()) }}" class="form-control">
        </div>
    </div>
</div>

<div class="gx-stock-fieldset">
    <div class="gx-stock-fieldset-label">When to re-order</div>

    <div class="row g-3 mb-2">
        <div class="col-4">
            <label class="form-label">Safety Stock</label>
            <input type="number" step="0.0001" min="0" name="safety_stock_qty"
                   value="{{ $value('safety_stock_qty', $item?->safety_stock_qty) }}" class="form-control" placeholder="Auto">
        </div>
        <div class="col-4">
            <label class="form-label">Re-order Lvl</label>
            <input type="number" step="0.0001" min="0" name="reorder_level"
                   value="{{ $value('reorder_level', $item?->reorder_level) }}" class="form-control" placeholder="Auto">
        </div>
        <div class="col-4">
            <label class="form-label">Lead (days)</label>
            <input type="number" min="0" name="lead_time_days"
                   value="{{ $value('lead_time_days', $item?->lead_time_days) }}" class="form-control">
        </div>
    </div>
    <p class="gx-stock-help">
        Leave Safety Stock and Re-order Level blank to have them calculated from last month's consumption.
    </p>
</div>

<div class="gx-stock-fieldset">
    <div class="gx-stock-fieldset-label">Anything else</div>

    @if($item)
        {{-- Only an existing item can be deactivated; a new one starts active. --}}
        <div class="form-check mb-3">
            <input type="hidden" name="is_active" value="0">
            <input class="form-check-input" type="checkbox" name="is_active" value="1" id="active{{ $item->id }}"
                   {{ $value('is_active', $item->is_active) ? 'checked' : '' }}>
            <label class="form-check-label" for="active{{ $item->id }}">Active</label>
        </div>
    @endif

    <label class="form-label">Remarks</label>
    <textarea name="remarks" rows="2" class="form-control" maxlength="1000">{{ $value('remarks', $item?->remarks) }}</textarea>
</div>
