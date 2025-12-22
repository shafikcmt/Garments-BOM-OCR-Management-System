@extends('layouts.app')
@section('content')
<div class="container py-4">

    <h3 class="mb-3"><i class="bi bi-layout-text-sidebar-reverse"></i> Create Section</h3>

    <form method="POST" action="{{ route('admin.fields.store') }}" class="card p-3 shadow-sm bg-light rounded">
        @csrf

        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label">Section Name <span class="text-danger">*</span></label>
                <input type="text" name="name" class="form-control" placeholder="Enter Section Name">
            </div>

            <div class="col-md-6">
                <label class="form-label">Role <span class="text-danger">*</span></label>
                <select name="role_id" class="form-select">
                    @foreach($roles as $role)
                    <option value="{{ $role->id }}">{{ ucfirst($role->name) }}</option>
                    @endforeach
                </select>
            </div>

            <div class="col-md-12">
                <label class="form-label">Description</label>
                <textarea name="description" class="form-control" rows="2" placeholder="Optional description"></textarea>
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

        <div class="mt-3 d-flex justify-content-end">
            <button type="reset" class="btn btn-secondary me-2">Clear</button>
            <button type="submit" class="btn btn-success"><i class="bi bi-check-circle"></i> Save Section</button>
        </div>
    </form>
</div>
@endsection
