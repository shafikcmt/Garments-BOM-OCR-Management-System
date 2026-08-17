{{--
    The Item Master fields, shared by the Add and the Edit modal so the two can
    never drift apart.

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

    // The Advanced panel is folded away, which would hide an error raised on a
    // field inside it — the form would bounce back complaining about something
    // the user cannot see. Opened when one of its own fields is what failed,
    // and left open when the user had typed an override, so a rejected form
    // comes back showing what they were working on.
    $advancedFields = ['safety_stock_qty', 'reorder_level', 'lead_time_days'];

    $advancedOpen = $wasSubmitted && collect($advancedFields)
        ->contains(fn ($f) => $errors->has($f) || filled(old($f)));
@endphp

<input type="hidden" name="form" value="{{ $formKey }}">

<div class="gx-stock-fieldset">
    <div class="gx-stock-fieldset-label">What the item is</div>

    <label class="form-label">Item Name <span class="text-danger">*</span></label>
    <input name="name" value="{{ $value('name', $item?->name) }}" class="form-control mb-3" required
           @if(! $item) autofocus @endif>

    {{-- One field, not three. Brand, Size and Specification were filled
         inconsistently and read as a single line anyway, so the store keeps
         them together here: "Organ 14", "Groz-Beckert DBx1 90/14 ball point". --}}
    <label class="form-label">Brand/Specification</label>
    <input name="brand" value="{{ $value('brand', $item?->brand) }}" class="form-control mb-3" maxlength="255"
           placeholder="Organ DPX17-14 / Groz-Beckert, chrome">

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
         whatever was already on the shelf.

         Counted On is prefilled with today rather than left empty. It is a
         manual fact — the day the shelf was actually counted, and what decides
         which months the counted figure appears in — so it keeps its picker for
         anyone entering last week's count. But the overwhelmingly common case
         is counting it now, and validation makes the date REQUIRED as soon as a
         quantity is typed, so an empty default turned the ordinary case into an
         error message. The bulk import has always defaulted it this way. --}}
    <div class="row g-3">
        <div class="col-6">
            <label class="form-label">Opening Stock</label>
            <input type="number" step="0.0001" name="opening_qty" value="{{ $value('opening_qty', $item?->opening_qty) }}" class="form-control">
        </div>
        <div class="col-6">
            <label class="form-label">Counted On</label>
            <input type="date" name="opening_as_on"
                   value="{{ $value('opening_as_on', optional($item?->opening_as_on)->toDateString() ?? ($item ? null : now()->toDateString())) }}"
                   class="form-control">
        </div>
    </div>

    {{-- The standard price per unit. Optional, like every other number here:
         an item is often set up before its price is settled.

         It does NOT drive the Stock Report's Value column today — that reads
         the price off the most recent challan, which is what the Excel did and
         what the report still does. --}}
    <div class="row g-3 mt-0">
        <div class="col-6">
            <label class="form-label">Unit Price</label>
            <input type="number" step="0.0001" min="0" name="unit_price"
                   value="{{ $value('unit_price', $item?->unit_price) }}" class="form-control" placeholder="Optional">
        </div>
    </div>
</div>

{{-- Re-order planning, folded away.

     Safety Stock and Re-order Level are CALCULATED from last month's
     consumption, and have been all along — the form simply presented the
     override as though it were the normal way to fill the form, so three of the
     five numbers on an Add Item screen were fields nobody needed to touch.

     They are not removed, because the Stock Report still branches on them and
     labels a pinned value as pinned. Folded away instead: the common case is
     now name, uom, category, opening stock, and the override is one click away
     for the item that genuinely needs it.

     Lead time stays here too, and stays MANUAL, because nothing can derive it.
     Lead time is order date to receipt date, and no order date is recorded
     anywhere — stock_purchases holds the challan date and the arrival date,
     both on the delivery side. Blank means the configured default. --}}
<div class="gx-stock-fieldset">
    <button type="button" class="gx-advanced-toggle @if(! $advancedOpen) collapsed @endif" data-bs-toggle="collapse"
            data-bs-target="#advanced{{ $item?->id ?? 'Add' }}"
            aria-expanded="{{ $advancedOpen ? 'true' : 'false' }}" aria-controls="advanced{{ $item?->id ?? 'Add' }}">
        <i class="bi bi-chevron-right" aria-hidden="true"></i>
        <span>Advanced — override the calculated values</span>
        @if($item && ($item->safety_stock_qty !== null || $item->reorder_level !== null))
            {{-- Says the override is in use without having to open the panel. --}}
            <span class="gx-edit-scope">Pinned</span>
        @endif
    </button>

    <div class="collapse @if($advancedOpen) show @endif" id="advanced{{ $item?->id ?? 'Add' }}">
        <div class="pt-3">
            <p class="gx-edit-hint mb-3 mt-0">
                Leave Safety Stock and Re-order Level blank and the Stock Report calculates them
                from last month's consumption. Type a value only to pin this item to a figure of
                your own — the report will mark it as pinned.
            </p>
            <div class="row g-3">
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
                           value="{{ $value('lead_time_days', $item?->lead_time_days) }}" class="form-control"
                           placeholder="{{ config('stock.general_stock.default_lead_time_days', 7) }}">
                </div>
            </div>
        </div>
    </div>
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
