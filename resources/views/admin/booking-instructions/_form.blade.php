@php
    $selectedType = old('instruction_type', ($instruction->is_default ?? false) ? 'default' : 'suggestion');
@endphp

<div class="row g-3">
    <div class="col-12">
        <label class="form-label">Instruction Text <span class="text-danger">*</span></label>
        <textarea name="instruction" rows="4" class="form-control @error('instruction') is-invalid @enderror"
                  placeholder="Write booking instruction text here">{{ old('instruction', $instruction->instruction ?? '') }}</textarea>
        @error('instruction')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
        <div class="form-text">This text will be used in the booking format notes / instructions section.</div>
    </div>

    <div class="col-md-6">
        <label class="form-label d-block">Instruction Use <span class="text-danger">*</span></label>
        <div class="border rounded-4 p-3 h-100">
            <div class="form-check mb-2">
                <input class="form-check-input" type="radio" name="instruction_type" id="instructionDefault" value="default" @checked($selectedType === 'default')>
                <label class="form-check-label fw-semibold" for="instructionDefault">Default instruction</label>
                <div class="form-text">Automatically shows in every new booking format.</div>
            </div>
            <div class="form-check">
                <input class="form-check-input" type="radio" name="instruction_type" id="instructionSuggestion" value="suggestion" @checked($selectedType === 'suggestion')>
                <label class="form-check-label fw-semibold" for="instructionSuggestion">Extra suggestion</label>
                <div class="form-text">Supply Chain user can select and add it only when needed.</div>
            </div>
        </div>
        @error('instruction_type')
            <div class="text-danger small mt-1">{{ $message }}</div>
        @enderror
    </div>

    <div class="col-md-3">
        <label class="form-label">Sort Order</label>
        <input type="number" min="0" name="sort_order" class="form-control @error('sort_order') is-invalid @enderror"
               value="{{ old('sort_order', $instruction->sort_order ?? 0) }}">
        @error('sort_order')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
        <div class="form-text">Lower number shows first.</div>
    </div>

    <div class="col-md-3 d-flex align-items-end">
        <div class="form-check form-switch mb-3">
            <input type="hidden" name="is_active" value="0">
            <input class="form-check-input" type="checkbox" name="is_active" value="1" id="is_active"
                   {{ old('is_active', $instruction->is_active ?? true) ? 'checked' : '' }}>
            <label class="form-check-label" for="is_active">Active</label>
            <div class="form-text">Inactive instructions will not show to Supply Chain.</div>
        </div>
    </div>
</div>
