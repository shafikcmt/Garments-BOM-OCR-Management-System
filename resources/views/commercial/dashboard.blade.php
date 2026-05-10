@extends('layouts.app')

@section('title', 'Commercial Dashboard')

@section('content')
<div class="container-fluid">
    <div class="app-hero-card p-4 p-lg-5 mb-4">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div class="d-flex align-items-center gap-3">
                <span class="app-stat-icon" style="width:52px;height:52px;border-radius:18px;font-size:22px;"><i class="bi bi-briefcase"></i></span>
                <div>
                    <div class="app-hero-eyebrow">Commercial</div>
                    <h2 class="app-hero-title">Welcome, {{ auth()->user()->name }}</h2>
                    <p class="app-hero-copy mb-0">Work with commercial documents and OCR updates.</p>
                </div>
            </div>
            <a href="{{ route('commercial.workspace') }}" class="btn btn-primary px-4 d-inline-flex align-items-center gap-2">
                <i class="bi bi-box-arrow-up-right"></i>Open Workspace
            </a>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-md-4">
            <div class="app-stat-card p-3 h-100">
                <div class="d-flex align-items-center gap-3">
                    <span class="app-stat-icon"><i class="bi bi-shield-check"></i></span>
                    <div>
                        <div class="app-stat-label">Access</div>
                        <div class="fw-bold text-slate-900">Role-based workspace</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="app-stat-card p-3 h-100">
                <div class="d-flex align-items-center gap-3">
                    <span class="app-stat-icon"><i class="bi bi-file-earmark-text"></i></span>
                    <div>
                        <div class="app-stat-label">OCR</div>
                        <div class="fw-bold text-slate-900">Document workflow</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="app-stat-card p-3 h-100">
                <div class="d-flex align-items-center gap-3">
                    <span class="app-stat-icon"><i class="bi bi-lightning-charge"></i></span>
                    <div>
                        <div class="app-stat-label">Status</div>
                        <div class="fw-bold text-slate-900">Ready to work</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
