@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
<div class="container-fluid">
    <div class="card shadow-sm border-0">
        <div class="card-body">
            <h3 class="mb-2">Welcome, {{ auth()->user()->name }}</h3>
            <p class="text-muted mb-0">You are successfully logged in.</p>
        </div>
    </div>
</div>
@endsection