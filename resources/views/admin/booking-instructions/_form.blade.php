@php
    $canManageDefaults = $canManageDefaults ?? true;
    $selectedType = old('instruction_type', ($instruction->is_default ?? false) ? 'default' : 'suggestion');

    // Without this a permission-only holder opening the create form sees the
    // Default radio pre-selected and disabled, with no way to reach Suggestion.
    if (! $canManageDefaults) {
        $selectedType = 'suggestion';
    }
@endphp

<div class="gx-form-section">
    <div class="gx-form-section-head">Instruction Text</div>

    <div class="gx-field">
        <label class="form-label fw-semibold" for="instructionText">Instruction Text <span class="text-danger">*</span></label>
        <textarea name="instruction" id="instructionText" rows="4" class="form-control @error('instruction') is-invalid @enderror"
                  placeholder="Write booking instruction text here">{{ old('instruction', $instruction->instruction ?? '') }}</textarea>
        @error('instruction')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
        <div class="form-text">This text will be used in the booking format notes / instructions section.</div>
    </div>
</div>

<div class="gx-form-section">
    <div class="gx-form-section-head">Instruction Use <span class="text-danger">*</span></div>

    <label class="gx-choice">
        <input class="form-check-input" type="radio" name="instruction_type" id="instructionDefault" value="default"
               @checked($selectedType === 'default') @disabled(! $canManageDefaults)>
        <span class="gx-choice-body">
            <span class="gx-choice-title d-block">Default instruction</span>
            <span class="gx-choice-help d-block">Automatically shows in every new booking format.</span>
        </span>
    </label>

    <label class="gx-choice">
        <input class="form-check-input" type="radio" name="instruction_type" id="instructionSuggestion" value="suggestion" @checked($selectedType === 'suggestion')>
        <span class="gx-choice-body">
            <span class="gx-choice-title d-block">Extra suggestion</span>
            <span class="gx-choice-help d-block">Supply Chain user can select and add it only when needed.</span>
        </span>
    </label>

    @unless($canManageDefaults)
        <div class="form-text mt-2">Only an administrator can set an instruction as default.</div>
    @endunless

    @error('instruction_type')
        <div class="text-danger small mt-2">{{ $message }}</div>
    @enderror
</div>

<div class="gx-form-section">
    <div class="gx-form-section-head">Where It Applies</div>

    <div class="row g-3">
        <div class="col-md-6">
            <div class="gx-field">
                <label class="form-label fw-semibold" for="instructionBuyer">Buyer</label>
                <select name="buyer_id" id="instructionBuyer" class="form-select @error('buyer_id') is-invalid @enderror">
                    <option value="">All buyers (global)</option>
                    @foreach($buyers ?? [] as $buyer)
                        <option value="{{ $buyer->id }}" @selected((int) old('buyer_id', $instruction->buyer_id ?? 0) === (int) $buyer->id)>
                            {{ $buyer->buyer_name }}
                        </option>
                    @endforeach
                </select>
                @error('buyer_id')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
                <div class="form-text">
                    Leave as <strong>All buyers</strong> unless the text names one buyer's requirement.
                    A buyer-specific instruction only prints on that buyer's POs.
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="gx-field">
                <label class="form-label fw-semibold" for="instructionSortOrder">Sort Order</label>
                <input type="number" min="0" name="sort_order" id="instructionSortOrder" class="form-control @error('sort_order') is-invalid @enderror"
                       value="{{ old('sort_order', $instruction->sort_order ?? 0) }}">
                @error('sort_order')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
                <div class="form-text">Lower number shows first.</div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="gx-field">
                <label class="form-label fw-semibold d-block">Status</label>
                <div class="form-check form-switch pt-2">
                    <input type="hidden" name="is_active" value="0">
                    <input class="form-check-input" type="checkbox" name="is_active" value="1" id="is_active"
                           {{ old('is_active', $instruction->is_active ?? true) ? 'checked' : '' }}>
                    <label class="form-check-label fw-semibold" for="is_active">Active</label>
                </div>
                <div class="form-text">Inactive instructions will not show to Supply Chain.</div>
            </div>
        </div>
    </div>
</div>
