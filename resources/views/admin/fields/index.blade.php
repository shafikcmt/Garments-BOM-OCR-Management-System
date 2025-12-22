@extends('layouts.app')
@section('content')
<div class="container">
    <h4>Sections</h4>
    <a href="{{ route('admin.fields.create') }}" class="btn btn-primary mb-2">➕ Add Section</a>

    @foreach($sections as $section)
    <div class="card mb-2">
        <div class="card-header d-flex justify-content-between">
            <strong>{{ $section->name }}</strong> ({{ $section->role->name }})
            <div>
                <a href="{{ route('admin.fields.edit', $section) }}" class="btn btn-sm btn-warning">Edit</a>
                <a href="{{ route('admin.fields.create_field', $section) }}" class="btn btn-sm btn-success">Add Field</a>
                <form method="POST" action="{{ route('admin.fields.destroy', $section) }}" class="d-inline">
                    @csrf @method('DELETE')
                    <button class="btn btn-sm btn-danger" onclick="return confirm('Delete section?')">Delete</button>
                </form>
            </div>
        </div>
        <div class="card-body">
            @if($section->fields->count())
                <ul>
                    @foreach($section->fields as $field)
                        <li>{{ $field->field_label }} ({{ $field->field_type }})
                            <a href="{{ route('admin.fields.edit_field', [$section, $field]) }}" class="btn btn-sm btn-warning">Edit</a>
                        </li>
                    @endforeach
                </ul>
            @else
                <p>No fields added yet.</p>
            @endif
        </div>
    </div>
    @endforeach
</div>
@endsection
