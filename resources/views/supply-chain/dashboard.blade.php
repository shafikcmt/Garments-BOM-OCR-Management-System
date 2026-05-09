@extends('layouts.app')

@section('title', 'Supply Chain Dashboard')

@section('content')
<div class="container-fluid">
    <div class="card shadow-sm border-0">
        <div class="card-body">
            <h2 class="mb-2">Welcome, {{ auth()->user()->name }}</h2>
            <p class="text-muted mb-0">You are successfully logged in to Supply Chain Dashboard.</p>
        </div>
    </div>
</div>
@endsection