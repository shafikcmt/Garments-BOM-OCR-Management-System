@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
<div class="container-fluid">
    <div class="app-hero-card p-4 mb-4">
        <div class="d-flex align-items-center gap-3">
            <span class="app-stat-icon" style="width:54px;height:54px;border-radius:18px;font-size:23px;"><i class="bi bi-house"></i></span>
            <div>
                <div class="app-hero-eyebrow">Dashboard</div>
                <h2 class="app-hero-title mb-1">Welcome, {{ auth()->user()->name }}</h2>
                <p class="app-hero-copy mb-0">Use the sidebar to navigate to your workspace.</p>
            </div>
        </div>
    </div>
</div>
@endsection
