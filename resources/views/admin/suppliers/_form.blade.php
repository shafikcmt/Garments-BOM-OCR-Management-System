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
        <label class="form-label">Contact Person</label>
        <input type="text" name="contact_person" class="form-control @error('contact_person') is-invalid @enderror"
               value="{{ old('contact_person', $supplier->contact_person ?? '') }}"
               placeholder="Valentina Herlina">
        @error('contact_person')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="col-md-4">
        <label class="form-label">Email</label>
        <input type="email" name="email" class="form-control @error('email') is-invalid @enderror"
               value="{{ old('email', $supplier->email ?? '') }}"
               placeholder="supplier@example.com">
        @error('email')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="col-md-4">
        <label class="form-label">Phone</label>
        <input type="text" name="phone" class="form-control @error('phone') is-invalid @enderror"
               value="{{ old('phone', $supplier->phone ?? '') }}"
               placeholder="+880...">
        @error('phone')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="col-md-12">
        <label class="form-label">Address</label>
        <textarea name="address" rows="2" class="form-control @error('address') is-invalid @enderror"
                  placeholder="Supplier address">{{ old('address', $supplier->address ?? '') }}</textarea>
        @error('address')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="col-md-4">
        <label class="form-label">City</label>
        <input type="text" name="city" class="form-control @error('city') is-invalid @enderror"
               value="{{ old('city', $supplier->city ?? '') }}">
        @error('city')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="col-md-4">
        <label class="form-label">Country</label>
        <input type="text" name="country" class="form-control @error('country') is-invalid @enderror"
               value="{{ old('country', $supplier->country ?? '') }}">
        @error('country')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="col-md-4">
        <label class="form-label">Item Type</label>
        <select name="item_type" class="form-select @error('item_type') is-invalid @enderror">
            @php
                $itemTypes = ['Fabric', 'Zipper', 'Hook & Bar', 'Trim', 'Interlining', 'Scrim'];
                $selectedItemType = old('item_type', $supplier->item_type ?? '');
            @endphp

            <option value="">Select Item Type</option>
            @foreach($itemTypes as $itemType)
                <option value="{{ $itemType }}" {{ $selectedItemType === $itemType ? 'selected' : '' }}>
                    {{ $itemType }}
                </option>
            @endforeach
        </select>
        @error('item_type')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="col-md-4">
        <label class="form-label">Incoterm</label>
        <select name="incoterm" class="form-select @error('incoterm') is-invalid @enderror">
            @php
                $incoterms = ['FOB', 'CIF', 'CFR', 'Ex-Works'];
                $selectedIncoterm = old('incoterm', $supplier->incoterm ?? '');
            @endphp

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
            @php
                $shipModes = ['Sea', 'Air', 'Courier', 'Truck'];
                $selectedShipMode = old('ship_mode', $supplier->ship_mode ?? '');
            @endphp

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

    <div class="col-md-4 d-flex align-items-end">
        <div class="form-check form-switch mb-2">
            <input type="hidden" name="is_active" value="0">

            <input class="form-check-input" type="checkbox" name="is_active" value="1" id="is_active"
                   {{ old('is_active', $supplier->is_active ?? true) ? 'checked' : '' }}>

            <label class="form-check-label" for="is_active">
                Active Supplier
            </label>
        </div>
    </div>

</div>