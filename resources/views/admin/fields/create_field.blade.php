@extends('layouts.app')

@section('content')
<div class="container">
    <h4 class="mb-3">Add Field for Section: <strong>{{ $section->name }}</strong></h4>

    <!-- Add Field Form -->
    <form method="POST" action="{{ route('admin.fields.store_field', $section) }}" class="mb-4">
        @csrf
        <div class="row g-2">
            <div class="col-md-6">
                <label class="form-label">Field Label</label>
                <input type="text" name="field_label" class="form-control" placeholder="Enter Field Label" required>
            </div>
            <div class="col-md-6">
                <label class="form-label">Field Key</label>
                <input type="text" name="field_key" class="form-control" placeholder="Unique Key (no spaces)" required>
            </div>
            <div class="col-md-6">
                <label class="form-label">Field Type</label>
                <select name="field_type" class="form-select" id="fieldType" required>
                    <option value="text">Text</option>
                    <option value="number">Number</option>
                    <option value="select">Select</option>
                    <option value="date">Date</option>
                    <option value="checkbox">Checkbox</option>
                </select>
            </div>
            <div class="col-md-6" id="optionsDiv" style="display:none;">
                <label class="form-label">Options (comma separated)</label>
                <input type="text" name="options" class="form-control" placeholder="Option1,Option2">
            </div>
            <div class="col-md-4">
                <label class="form-label">Required</label>
                <select name="is_required" class="form-select">
                    <option value="1">Yes</option>
                    <option value="0" selected>No</option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Sort Order</label>
                <input type="number" name="sort_order" class="form-control" value="0">
            </div>
            <div class="col-md-4">
                <label class="form-label">Status</label>
                <select name="is_active" class="form-select">
                    <option value="1" selected>Active</option>
                    <option value="0">Inactive</option>
                </select>
            </div>
        </div>
        <button class="btn btn-success mt-3">Save Field</button>
    </form>

    <!-- Existing Fields List -->
    <h5>Current Fields</h5>
    <ul class="list-group" id="field-list">
        @foreach($section->fields()->orderBy('sort_order')->get() as $field)
        <li class="list-group-item d-flex justify-content-between align-items-center" data-id="{{ $field->id }}">
            <span>
                <strong>{{ $field->field_label }}</strong> 
                <small class="text-muted">({{ $field->field_type }})</small>
            </span>
            <div>
                <a href="{{ route('admin.fields.edit_field', [$section, $field]) }}" class="btn btn-sm btn-primary">Edit</a>
                <form action="{{ route('admin.fields.destroy_field', [$section, $field]) }}" method="POST" class="d-inline">
                    @csrf
                    @method('DELETE')
                    <button class="btn btn-sm btn-danger" onclick="return confirm('Are you sure?')">Delete</button>
                </form>
            </div>
        </li>
        @endforeach
    </ul>
</div>
@endsection

@push('scripts')
<!-- jQuery UI for drag-drop -->
<script src="https://code.jquery.com/ui/1.13.2/jquery-ui.min.js"></script>
<script>
$(function() {
    // Show options input only for 'select' type
    $('#fieldType').on('change', function() {
        if ($(this).val() === 'select') {
            $('#optionsDiv').show();
        } else {
            $('#optionsDiv').hide();
        }
    });

    // Drag & drop sortable
    $('#field-list').sortable({
        placeholder: "ui-state-highlight",
        update: function(event, ui) {
            let order = $(this).sortable('toArray', { attribute: 'data-id' });
            $.post("{{ route('admin.fields.reorder_fields', $section) }}", {
                order: order,
                _token: "{{ csrf_token() }}"
            }).done(function() {
                console.log('Order updated');
            });
        }
    });
});
</script>

<style>
#field-list li {
    cursor: move;
}
.ui-state-highlight {
    height: 50px;
    background-color: #f0f0f0;
    border: 2px dashed #ccc;
    margin-bottom: 5px;
}
</style>
@endpush
