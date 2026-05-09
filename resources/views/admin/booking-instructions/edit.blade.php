@extends('layouts.app')

@section('title', 'Edit Booking Instruction')

@section('content')
<div class="container-fluid">
    <div class="card shadow-sm border-0">
        <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h5 class="mb-0">Edit Booking Instruction</h5>
                <small class="text-muted">Update the instruction text, default/suggestion use, order and active status.</small>
            </div>
            <a href="{{ route('admin.booking-instructions.index') }}" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i>Back
            </a>
        </div>
        <form method="POST" action="{{ route('admin.booking-instructions.update', $instruction) }}">
            @csrf
            @method('PUT')
            <div class="card-body">
                @include('admin.booking-instructions._form', ['instruction' => $instruction])
            </div>
            <div class="card-footer bg-white text-end">
                <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Update Instruction</button>
            </div>
        </form>
    </div>
</div>
@endsection
