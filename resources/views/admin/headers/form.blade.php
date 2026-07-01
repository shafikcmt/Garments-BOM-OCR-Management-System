@php
    $defaultFormulaOptions = [
        'shipment_month' => 'Shipment Month',
        'pcd_required' => 'PCD Required',
        'order_to_be_placed_by' => 'Order to be placed by',
        'consumption_incl_yy' => 'Consumption including YY',
        'materials_to_be_ordered' => 'Materials to be Ordered',
        'short_excess_ordered' => '(Short)/Excess Ordered',
        'material_order_status' => 'Material Order Status',
        'pi_amount' => 'PI Amount',
        'committed_inhouse' => 'Committed Inhouse',
        'pcd_as_per_committed_inhouse' => 'PCD as per Committed Inhouse',
        'liability_based_on_receiving' => 'Liability Based On Receiving',
        'buyer_liability' => 'Buyer Liability',
        'buyer_liability_value' => 'Buyer Liability Value',
        'final_status' => 'Final Status',
    ];

    $formulaOptions = $formulaOptions ?? $defaultFormulaOptions;

    $formulaMetaExamples = $formulaMetaExamples ?? [
        'shipment_month' => ['source_header_key' => 'contract_shipment_date'],
        'pcd_required' => ['source_header_key' => 'contract_shipment_date', 'subtract_days' => 45, 'format' => 'Y-m-d'],
        'order_to_be_placed_by' => ['source_header_key' => 'pcd_required', 'subtract_days' => 70, 'format' => 'Y-m-d'],
        'consumption_incl_yy' => [
            'formula' => 'booking_consumption_from_cad * (1 + wastage_for_ordering_percent)',
            'source_header_keys' => ['booking_consumption_from_cad', 'wastage_for_ordering_percent'],
        ],
        'materials_to_be_ordered' => [
            'formula' => 'consumption_based_on_which_materials_order_including_yy * customer_contract_quantity',
            'source_header_keys' => ['consumption_based_on_which_materials_order_including_yy', 'customer_contract_quantity'],
        ],
        'short_excess_ordered' => [
            'formula' => 'materials_ordered - materials_to_be_ordered',
            'source_header_keys' => ['materials_ordered', 'materials_to_be_ordered'],
        ],
        'material_order_status' => ['source_header_key' => 'short_excess_ordered'],
        'pi_amount' => ['formula' => 'pi_rate * materials_to_be_ordered', 'source_header_keys' => ['pi_rate', 'materials_to_be_ordered']],
        'committed_inhouse' => ['formula' => 'committed_eta + 7 days', 'source_header_key' => 'committed_eta', 'add_days' => 7, 'format' => 'Y-m-d'],
        'pcd_as_per_committed_inhouse' => ['formula' => 'committed_inhouse + 2 days', 'source_header_key' => 'committed_inhouse', 'add_days' => 2, 'format' => 'Y-m-d'],
        'liability_based_on_receiving' => [
            'formula' => 'receipt_qty - materials_to_be_ordered',
            'source_header_keys' => ['receipt_qty', 'materials_to_be_ordered'],
        ],
        'buyer_liability' => [
            'formula' => '(bom_quantity * consumption_based_on_which_materials_order_including_yy) - (gmts_order_qty * costing_yy_in_sms)',
            'source_header_keys' => ['bom_quantity', 'consumption_based_on_which_materials_order_including_yy', 'gmts_order_qty', 'costing_yy_in_sms'],
        ],
        'buyer_liability_value' => ['formula' => 'buyer_liability * pi_rate', 'source_header_keys' => ['buyer_liability', 'pi_rate']],
        'final_status' => ['source_header_keys' => ['actual_inhouse', 'arrival_status']],
    ];

    $formulaMetaValue = old('formula_meta');

    if ($formulaMetaValue === null && isset($header) && !empty($header->formula_meta)) {
        $formulaMetaValue = is_array($header->formula_meta)
            ? json_encode($header->formula_meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            : $header->formula_meta;
    }
@endphp

@if($errors->any())
    <div class="alert alert-danger d-flex align-items-start gap-2 mb-3" role="alert">
        <i class="bi bi-exclamation-triangle-fill mt-1"></i>
        <div>
            <strong>Please fix the following {{ $errors->count() }} error(s):</strong>
            <ul class="mb-0 mt-1 ps-3">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    </div>
@endif

<div class="row g-3">
    <div class="col-12">
        <div class="section-card">
            <div class="section-title">Basic Information</div>
            <div class="section-subtitle">Header name, key, owner role, position, type and value mode set korun.</div>

            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Header Name</label>
                    <input
                        type="text"
                        name="header_name"
                        id="header_name"
                        class="form-control @error('header_name') is-invalid @enderror"
                        value="{{ old('header_name', $header->header_name ?? '') }}"
                        placeholder="Example: Delivery Date"
                        maxlength="255"
                        required
                    >
                    @error('header_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-md-6">
                    <label class="form-label">Header Key</label>
                    <input
                        type="text"
                        name="header_key"
                        id="header_key"
                        class="form-control @error('header_key') is-invalid @enderror"
                        value="{{ old('header_key', $header->header_key ?? '') }}"
                        placeholder="Example: delivery_date"
                        data-auto="{{ old('header_key', $header->header_key ?? '') ? '0' : '1' }}"
                        maxlength="255"
                        required
                    >
                    @error('header_key')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <small class="text-muted">Auto generate hobe, chaile manually edit korte parben.</small>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Owner Role</label>
                    <select name="owner_role_id" class="form-select @error('owner_role_id') is-invalid @enderror" required>
                        <option value="">Select Role</option>
                        @foreach($roles as $role)
                            <option value="{{ $role->id }}" @selected(old('owner_role_id', $header->owner_role_id ?? '') == $role->id)>
                                {{ ucfirst(str_replace('_', ' ', $role->name)) }}
                            </option>
                        @endforeach
                    </select>
                    @error('owner_role_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-md-4">
                    <label class="form-label">Position</label>
                    <input
                        type="number"
                        name="position"
                        min="1"
                        class="form-control @error('position') is-invalid @enderror"
                        value="{{ old('position', $header->position ?? ($nextPosition ?? 1)) }}"
                        placeholder="Auto position"
                        required
                    >
                    @error('position')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <small class="text-muted">Auto value asbe, chaile change korte parben. Existing position dile oi position theke porer sob header auto shift hobe.</small>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Field Type</label>
                    <select name="field_type" class="form-select @error('field_type') is-invalid @enderror" required>
                        <option value="text" @selected(old('field_type', $header->field_type ?? 'text') === 'text')>Text</option>
                        <option value="number" @selected(old('field_type', $header->field_type ?? '') === 'number')>Number</option>
                        <option value="date" @selected(old('field_type', $header->field_type ?? '') === 'date')>Date</option>
                    </select>
                    @error('field_type')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <small class="text-muted">Output type ki hobe seta select korun.</small>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Value Mode</label>
                    <select name="value_mode" id="value_mode" class="form-select @error('value_mode') is-invalid @enderror" required>
                        <option value="input" @selected(old('value_mode', $header->value_mode ?? 'input') === 'input')>Input</option>
                        <option value="formula" @selected(old('value_mode', $header->value_mode ?? '') === 'formula')>Formula</option>
                        <option value="conditional" @selected(old('value_mode', $header->value_mode ?? '') === 'conditional')>Conditional</option>
                    </select>
                    @error('value_mode')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <small class="text-muted">Input = manual value, Formula = calculated value, Conditional = rule based value.</small>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-7" id="formulaKeyWrap">
        <div class="section-card h-100">
            <div class="section-title">Formula / Rule Setup</div>
            <div class="section-subtitle">Formula or conditional header hole logic key select korun.</div>

            <label class="form-label">Formula / Rule Key</label>
            <select name="formula_key" id="formula_key" class="form-select @error('formula_key') is-invalid @enderror">
                <option value="">Select Formula Logic</option>
                @foreach($formulaOptions as $key => $label)
                    <option value="{{ $key }}" @selected(old('formula_key', $header->formula_key ?? '') === $key)>
                        {{ $label }}
                    </option>
                @endforeach
            </select>
            @error('formula_key')<div class="invalid-feedback">{{ $message }}</div>@enderror
            <small class="text-muted">Formula/conditional header hole ekhane business logic key select korun.</small>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="section-card h-100">
            <div class="section-title">Access & Flags</div>
            <div class="section-subtitle">Required, active, view/edit permission ekhan theke manage korun.</div>

            <div class="check-grid">
                <div class="check-item">
                    <div class="form-check">
                        <input
                            type="checkbox"
                            name="is_required"
                            value="1"
                            class="form-check-input"
                            id="is_required"
                            @checked(old('is_required', isset($header) ? $header->is_required : true))
                        >
                        <label class="form-check-label" for="is_required">Required</label>
                    </div>
                </div>

                <div class="check-item">
                    <div class="form-check">
                        <input
                            type="checkbox"
                            name="is_active"
                            value="1"
                            class="form-check-input"
                            id="is_active"
                            @checked(old('is_active', isset($header) ? $header->is_active : true))
                        >
                        <label class="form-check-label" for="is_active">Active</label>
                    </div>
                </div>

                <div class="check-item">
                    <div class="form-check">
                        <input
                            type="checkbox"
                            name="can_view_all"
                            value="1"
                            class="form-check-input"
                            id="can_view_all"
                            @checked(old('can_view_all', isset($header) ? $header->can_view_all : true))
                        >
                        <label class="form-check-label" for="can_view_all">Can View All</label>
                    </div>
                </div>

                <div class="check-item">
                    <div class="form-check">
                        <input
                            type="checkbox"
                            name="can_edit_owner_only"
                            value="1"
                            class="form-check-input"
                            id="can_edit_owner_only"
                            @checked(old('can_edit_owner_only', isset($header) ? $header->can_edit_owner_only : true))
                        >
                        <label class="form-check-label" for="can_edit_owner_only">Owner Edit Only</label>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-12" id="formulaMetaWrap">
        <div class="section-card">
            <div class="section-title">Formula Meta (JSON)</div>
            <div class="section-subtitle">Rule-specific configuration JSON ekhane define korun.</div>

            <textarea
                name="formula_meta"
                id="formula_meta"
                class="form-control @error('formula_meta') is-invalid @enderror"
                rows="7"
                placeholder='{"source_header_key":"contract_shipment_date"}'
            >{{ $formulaMetaValue }}</textarea>
            @error('formula_meta')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror

            <small class="text-muted d-block mt-2" id="formulaMetaHelp">
                Example: shipment_month = {"source_header_key":"contract_shipment_date","format":"M"}
            </small>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const headerName = document.getElementById('header_name');
    const headerKey = document.getElementById('header_key');
    const valueMode = document.getElementById('value_mode');
    const formulaKey = document.getElementById('formula_key');
    const formulaMeta = document.getElementById('formula_meta');
    const formulaKeyWrap = document.getElementById('formulaKeyWrap');
    const formulaMetaWrap = document.getElementById('formulaMetaWrap');
    const formulaMetaHelp = document.getElementById('formulaMetaHelp');

    const formulaTemplates = @json($formulaMetaExamples);

    const formulaHelpTexts = {
        shipment_month: 'Shipment Month: Contract Shipment Date theke shipment month calculate hobe.',
        pcd_required: 'PCD Required: Contract Shipment Date theke configured days minus hobe.',
        order_to_be_placed_by: 'Order to be placed by: PCD Required theke configured days minus hobe.',
        consumption_incl_yy: 'Consumption including YY: Booking Consumption + Wastage percent diye calculate hobe.',
        materials_to_be_ordered: 'Materials to be Ordered: Consumption including YY * order quantity.',
        short_excess_ordered: '(Short)/Excess Ordered: Materials Ordered - Materials to be Ordered.',
        material_order_status: 'Material Order Status: Short/excess/order quantity check kore status set hobe.',
        pi_amount: 'PI Amount: PI Rate * Materials to be Ordered diye calculate hobe.',
        committed_inhouse: 'Committed Inhouse: Committed ETA + 7 days.',
        pcd_as_per_committed_inhouse: 'PCD as per Committed Inhouse: Committed Inhouse + 2 days.',
        liability_based_on_receiving: 'Liability Based On Receiving: Receipt qty - Materials to be Ordered.',
        buyer_liability: 'Buyer Liability: (BOM Quantity * Consumption including YY) - (GMTS Order Qty * Costing YY in SMS).',
        buyer_liability_value: 'Buyer Liability Value: Buyer Liability * PI Rate.',
        final_status: 'Final Status: inhouse/arrival related fields check kore final status set hobe.'
    };

    function prettyJson(value) {
        if (!value) return '';
        return JSON.stringify(value, null, 2);
    }

    if (formulaMeta) {
        formulaMeta.dataset.touched = formulaMeta.value.trim() !== '' ? '1' : '0';
    }

    if (headerName && headerKey) {
        let autoMode = headerKey.dataset.auto === '1';

        headerKey.addEventListener('input', function () {
            autoMode = false;
        });

        headerName.addEventListener('input', function () {
            if (!autoMode) return;

            let value = headerName.value
                .toLowerCase()
                .trim()
                .replace(/[^a-z0-9\s-_]/g, '')
                .replace(/[\s-]+/g, '_')
                .replace(/_+/g, '_')
                .replace(/^_+|_+$/g, '');

            headerKey.value = value;
        });
    }

    function updateFormulaHelp() {
        if (!formulaMetaHelp) return;

        const key = formulaKey ? formulaKey.value : '';
        formulaMetaHelp.textContent = formulaHelpTexts[key] || 'Formula key select korle sample JSON auto boshe jabe.';
    }

    function toggleFormulaFields() {
        if (!valueMode || !formulaKeyWrap || !formulaMetaWrap) return;

        const showFormulaFields = valueMode.value !== 'input';

        formulaKeyWrap.style.display = showFormulaFields ? '' : 'none';
        formulaMetaWrap.style.display = showFormulaFields ? '' : 'none';

        if (formulaKey) {
            formulaKey.required = showFormulaFields;
        }

        if (formulaMeta) {
            formulaMeta.required = showFormulaFields;
        }

        updateFormulaHelp();
    }

    if (formulaMeta) {
        formulaMeta.addEventListener('input', function () {
            this.dataset.touched = this.value.trim() !== '' ? '1' : '0';
        });
    }

    // Auto-generate the Formula Meta JSON from the selected key's template.
    // Only fills when the field is still empty (untouched), so a manually edited
    // or already-saved JSON is never overwritten.
    function applyFormulaTemplate() {
        if (!formulaKey || !formulaMeta || !valueMode) {
            updateFormulaHelp();
            return;
        }

        const template = formulaTemplates[formulaKey.value];

        if (valueMode.value !== 'input' && template && formulaMeta.dataset.touched !== '1') {
            formulaMeta.value = prettyJson(template);
            formulaMeta.dataset.touched = '1';
        }

        updateFormulaHelp();
    }

    if (formulaKey) {
        formulaKey.addEventListener('change', applyFormulaTemplate);
    }

    if (valueMode) {
        valueMode.addEventListener('change', function () {
            if (this.value === 'input' && formulaKey && formulaMeta) {
                formulaKey.value = '';
                formulaMeta.value = '';
                formulaMeta.dataset.touched = '0';
            }

            toggleFormulaFields();
        });
    }

    toggleFormulaFields();

    // On edit pages the formula key may already be selected while the JSON is empty;
    // fire once on load so the correct JSON auto-fills without manual typing.
    applyFormulaTemplate();
});
</script>
