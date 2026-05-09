@extends('layouts.app')

@section('title', 'Add Booking Instruction')

@section('content')
<div class="container-fluid">
    <div class="card shadow-sm border-0">
        <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h5 class="mb-0">Add Booking Instruction</h5>
                <small class="text-muted">Create a default instruction or an extra suggestion for Supply Chain users.</small>
            </div>
            <a href="{{ route('admin.booking-instructions.index') }}" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i>Back
            </a>
        </div>
        <form method="POST" action="{{ route('admin.booking-instructions.store') }}">
            @csrf
            <div class="card-body">
                @include('admin.booking-instructions._form', ['instruction' => $instruction])
            </div>
            <div class="card-footer bg-white text-end">
                <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Save Instruction</button>
            </div>
        </form>
    </div>
</div>
@endsection
