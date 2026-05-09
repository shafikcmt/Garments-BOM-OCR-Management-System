<div class="row g-3">
    <div class="col-md-8">
        <label class="form-label">Destination Title <span class="text-danger">*</span></label>
        <input type="text" name="title" class="form-control @error('title') is-invalid @enderror"
               value="{{ old('title', $destination->title ?? '') }}"
               placeholder="Factory / warehouse / buyer nominated delivery place">
        @error('title')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="col-md-4">
        <label class="form-label">Sort Order</label>
        <input type="number" min="0" name="sort_order" class="form-control @error('sort_order') is-invalid @enderror"
               value="{{ old('sort_order', $destination->sort_order ?? 0) }}">
        @error('sort_order')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="col-12">
        <label class="form-label">Delivery / Ship To Details <span class="text-danger">*</span></label>
        <textarea name="details" rows="6" class="form-control @error('details') is-invalid @enderror"
                  placeholder="Full delivery address, contact person, phone, BIN/TIN or applicable condition">{{ old('details', $destination->details ?? '') }}</textarea>
        @error('details')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="col-md-4 d-flex align-items-end">
        <div class="form-check form-switch mb-2">
            <input type="hidden" name="is_active" value="0">
            <input class="form-check-input" type="checkbox" name="is_active" value="1" id="is_active"
                   {{ old('is_active', $destination->is_active ?? true) ? 'checked' : '' }}>
            <label class="form-check-label" for="is_active">Active</label>
        </div>
    </div>
</div>
