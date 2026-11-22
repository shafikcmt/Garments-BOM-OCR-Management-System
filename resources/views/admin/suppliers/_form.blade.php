<div class="row g-3">

    <div class="col-md-4">
        <label class="form-label">Supplier Code</label>
        <input type="text" name="supplier_code" class="form-control @error('supplier_code') is-invalid @enderror"
               value="{{ old('supplier_code', $supplier->supplier_code ?? '') }}"
               placeholder="SUP-001">
        @error('supplier_code')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="col-md-4">
        <label class="form-label">Supplier Name <span class="text-danger">*</span></label>
        <input type="text" name="supplier_name" class="form-control @error('supplier_name') is-invalid @enderror"
               value="{{ old('supplier_name', $supplier->supplier_name ?? '') }}"
               placeholder="IDEAL FASTENER">
        @error('supplier_name')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="col-md-4">
        <label class="form-label">Legal Name</label>
        <input type="text" name="legal_name" class="form-control @error('legal_name') is-invalid @enderror"
               value="{{ old('legal_name', $supplier->legal_name ?? '') }}"
               placeholder="IDEAL FASTENER CORP">
        @error('legal_name')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="col-md-4">
        <label class="form-label">Vendor Type</label>
        @php $vt = old('vendor_type', $supplier->vendor_type ?? 'local'); @endphp
        <select name="vendor_type" class="form-select @error('vendor_type') is-invalid @enderror">
            <option value="local" {{ $vt === 'local' ? 'selected' : '' }}>Local</option>
            <option value="foreign" {{ $vt === 'foreign' ? 'selected' : '' }}>Foreign</option>
        </select>
        @error('vendor_type')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="col-md-4">
        <label class="form-label">Vendor Category</label>
        <input type="text" name="vendor_category"  class="form-control @error('vendor_category') is-invalid @enderror"
               value="{{ old('vendor_category', ($supplier->vendor_category ?? '') instanceof \DateTimeInterface ? $supplier->vendor_category->format('Y-m-d') : ($supplier->vendor_category ?? '')) }}" placeholder="Trims, Fabric, Packaging">
        @error('vendor_category')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="col-md-4">
        <label class="form-label">Country</label>
        <input type="text" name="country"  class="form-control @error('country') is-invalid @enderror"
               value="{{ old('country', ($supplier->country ?? '') instanceof \DateTimeInterface ? $supplier->country->format('Y-m-d') : ($supplier->country ?? '')) }}" placeholder="">
        @error('country')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="col-md-4">
        <label class="form-label">TIN</label>
        <input type="text" name="tin"  class="form-control @error('tin') is-invalid @enderror"
               value="{{ old('tin', ($supplier->tin ?? '') instanceof \DateTimeInterface ? $supplier->tin->format('Y-m-d') : ($supplier->tin ?? '')) }}" placeholder="">
        @error('tin')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="col-md-4">
        <label class="form-label">VAT / BIN Number</label>
        <input type="text" name="vat_bin_number"  class="form-control @error('vat_bin_number') is-invalid @enderror"
               value="{{ old('vat_bin_number', ($supplier->vat_bin_number ?? '') instanceof \DateTimeInterface ? $supplier->vat_bin_number->format('Y-m-d') : ($supplier->vat_bin_number ?? '')) }}" placeholder="">
        @error('vat_bin_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="col-md-4">
        <label class="form-label">Business Registration No.</label>
        <input type="text" name="business_reg_number"  class="form-control @error('business_reg_number') is-invalid @enderror"
               value="{{ old('business_reg_number', ($supplier->business_reg_number ?? '') instanceof \DateTimeInterface ? $supplier->business_reg_number->format('Y-m-d') : ($supplier->business_reg_number ?? '')) }}" placeholder="">
        @error('business_reg_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="col-md-4">
        <label class="form-label">Default Currency</label>
        <input type="text" name="default_currency" maxlength="10" class="form-control @error('default_currency') is-invalid @enderror"
               value="{{ old('default_currency', ($supplier->default_currency ?? '') instanceof \DateTimeInterface ? $supplier->default_currency->format('Y-m-d') : ($supplier->default_currency ?? '')) }}" placeholder="USD">
        @error('default_currency')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="col-md-4">
        <label class="form-label">Default Payment Terms</label>
        <input type="text" name="default_payment_terms" list="paymentTermOptions" autocomplete="off" class="form-control @error('default_payment_terms') is-invalid @enderror"
               value="{{ old('default_payment_terms', ($supplier->default_payment_terms ?? '') instanceof \DateTimeInterface ? $supplier->default_payment_terms->format('Y-m-d') : ($supplier->default_payment_terms ?? '')) }}" placeholder="LC at sight / 30 days">
        <datalist id="paymentTermOptions">
            @foreach(\App\Models\SupplierOption::names('payment_term') as $paymentTermOption)
                <option value="{{ $paymentTermOption }}"></option>
            @endforeach
        </datalist>
        @error('default_payment_terms')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    @php
        // Choices are managed by Admin (Booking Setup, Vendor Options). A value the
        // vendor already holds stays selectable even if it was deleted from the list,
        // so opening the form to fix a phone number never blanks what the PO prints.
        $selectedItemTypes = old('item_types') !== null
            ? array_values(array_filter(array_map('trim', (array) old('item_types'))))
            : \App\Models\SupplierOption::split(old('item_type', $supplier->item_type ?? ''));
        $itemTypes = \App\Models\SupplierOption::names('item_type', $selectedItemTypes);
        $selectedIncoterm = old('incoterm', $supplier->incoterm ?? '');
        $incoterms = \App\Models\SupplierOption::names('incoterm', [$selectedIncoterm]);
        $selectedShipMode = old('ship_mode', $supplier->ship_mode ?? '');
        $shipModes = \App\Models\SupplierOption::names('ship_mode', [$selectedShipMode]);
    @endphp

    <div class="col-md-12">
        <label class="form-label">Item Type <span class="text-muted fw-normal">(tick all that apply)</span></label>
        <div class="d-flex flex-wrap gap-2 @error('item_types') is-invalid @enderror">
            @foreach($itemTypes as $i => $itemType)
                <div class="form-check form-check-inline border rounded px-3 py-1 m-0">
                    <input class="form-check-input ms-0 me-2" type="checkbox" name="item_types[]" value="{{ $itemType }}"
                           id="item_type_{{ $i }}" {{ in_array($itemType, $selectedItemTypes, true) ? 'checked' : '' }}>
                    <label class="form-check-label" for="item_type_{{ $i }}">{{ $itemType }}</label>
                </div>
            @endforeach
            {{-- Sent even when nothing is ticked, so un-ticking everything clears the type. --}}
            <input type="hidden" name="item_types[]" value="">
        </div>
        @error('item_types')
            <div class="invalid-feedback d-block">{{ $message }}</div>
        @enderror
        @error('item_type')
            <div class="invalid-feedback d-block">{{ $message }}</div>
        @enderror
    </div>

    <div class="col-md-4">
        <label class="form-label">Incoterm</label>
        <select name="incoterm" class="form-select @error('incoterm') is-invalid @enderror">
            <option value="">Select Incoterm</option>
            @foreach($incoterms as $incoterm)
                <option value="{{ $incoterm }}" {{ $selectedIncoterm === $incoterm ? 'selected' : '' }}>
                    {{ $incoterm }}
                </option>
            @endforeach
        </select>
        @error('incoterm')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="col-md-4">
        <label class="form-label">Ship Mode</label>
        <select name="ship_mode" class="form-select @error('ship_mode') is-invalid @enderror">
            <option value="">Select Ship Mode</option>
            @foreach($shipModes as $shipMode)
                <option value="{{ $shipMode }}" {{ $selectedShipMode === $shipMode ? 'selected' : '' }}>
                    {{ $shipMode }}
                </option>
            @endforeach
        </select>
        @error('ship_mode')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="col-md-4">
        <label class="form-label">Tolerance %</label>
        <input type="number" step="0.01" min="0" max="100" name="tolerance_percent"
               class="form-control @error('tolerance_percent') is-invalid @enderror"
               value="{{ old('tolerance_percent', $supplier->tolerance_percent ?? '') }}"
               placeholder="3.00">
        @error('tolerance_percent')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="col-md-4">
        <label class="form-label">Lead Time (days)</label>
        <input type="number" name="lead_time_days" min="0" max="999" class="form-control @error('lead_time_days') is-invalid @enderror"
               value="{{ old('lead_time_days', ($supplier->lead_time_days ?? '') instanceof \DateTimeInterface ? $supplier->lead_time_days->format('Y-m-d') : ($supplier->lead_time_days ?? '')) }}" placeholder="">
        @error('lead_time_days')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="col-md-4">
        <label class="form-label">MOQ</label>
        <input type="number" name="moq" min="0" step="0.001" class="form-control @error('moq') is-invalid @enderror"
               value="{{ old('moq', ($supplier->moq ?? '') instanceof \DateTimeInterface ? $supplier->moq->format('Y-m-d') : ($supplier->moq ?? '')) }}" placeholder="">
        @error('moq')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="col-md-4">
        <label class="form-label">Effective Date</label>
        <input type="date" name="effective_date"  class="form-control @error('effective_date') is-invalid @enderror"
               value="{{ old('effective_date', ($supplier->effective_date ?? '') instanceof \DateTimeInterface ? $supplier->effective_date->format('Y-m-d') : ($supplier->effective_date ?? '')) }}" placeholder="">
        @error('effective_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="col-md-4">
        <label class="form-label">Expiry Date</label>
        <input type="date" name="expiry_date"  class="form-control @error('expiry_date') is-invalid @enderror"
               value="{{ old('expiry_date', ($supplier->expiry_date ?? '') instanceof \DateTimeInterface ? $supplier->expiry_date->format('Y-m-d') : ($supplier->expiry_date ?? '')) }}" placeholder="">
        @error('expiry_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="col-md-4">
        <label class="form-label">External ERP Vendor Code</label>
        <input type="text" name="external_erp_code"  class="form-control @error('external_erp_code') is-invalid @enderror"
               value="{{ old('external_erp_code', ($supplier->external_erp_code ?? '') instanceof \DateTimeInterface ? $supplier->external_erp_code->format('Y-m-d') : ($supplier->external_erp_code ?? '')) }}" placeholder="">
        @error('external_erp_code')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="col-12">
        <label class="form-label">Remarks</label>
        <textarea name="remarks" rows="2" class="form-control @error('remarks') is-invalid @enderror">{{ old('remarks', $supplier->remarks ?? '') }}</textarea>
        @error('remarks')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="col-12 text-muted small">
        Contacts, addresses, item categories, business units and bank accounts are added on the vendor profile after saving.
        A new vendor is a draft until it is submitted and approved.
    </div>

</div>
