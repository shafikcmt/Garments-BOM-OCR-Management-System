@extends('layouts.app')

@section('title', 'Create Role')

@section('content')
<div class="container-fluid">
    <div class="card shadow-sm border-0">
        <div class="card-body">
            <h3 class="mb-3">Create Role</h3>
            <form action="{{ route('admin.roles.store') }}" method="POST">
                @csrf
                @include('admin.roles.form')
                <button type="submit" class="btn btn-primary">Save</button>
                <a href="{{ route('admin.roles.index') }}" class="btn btn-secondary">Back</a>
            </form>
        </div>
    </div>
</div>
@endsection