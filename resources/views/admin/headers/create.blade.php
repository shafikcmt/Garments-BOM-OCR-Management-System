@extends('layouts.app')

@section('title', 'Create Header')

@section('content')
<style>
    .header-form-page {
        --hf-border: #e8edf5;
        --hf-text: #667085;
        --hf-heading: #1f2a44;
        --hf-card: #ffffff;
        --hf-soft: #f8fbff;
    }

    .header-form-page .hero-card,
    .header-form-page .form-shell,
    .header-form-page .section-card {
        background: var(--hf-card);
        border: 1px solid var(--hf-border);
        border-radius: 16px;
        box-shadow: 0 8px 22px rgba(31, 42, 68, 0.05);
    }

    .header-form-page .hero-card {
        background: linear-gradient(135deg, #f8fbff 0%, #eef4ff 100%);
        border-color: #e3ebfb;
        padding: 18px 20px;
    }

    .header-form-page .hero-title {
        font-size: 1.45rem;
        font-weight: 700;
        margin-bottom: 4px;
        color: var(--hf-heading);
    }

    .header-form-page .hero-text {
        margin: 0;
        color: var(--hf-text);
        font-size: 0.92rem;
    }

    .header-form-page .form-shell {
        overflow: hidden;
    }

    .header-form-page .form-shell .card-body {
        padding: 18px;
    }

    .header-form-page .form-label {
        font-weight: 600;
        font-size: 0.87rem;
        color: var(--hf-heading);
        margin-bottom: 6px;
    }

    .header-form-page .form-control,
    .header-form-page .form-select,
    .header-form-page textarea.form-control {
        border-radius: 10px;
        border-color: #dbe3f0;
        min-height: 40px;
        box-shadow: none;
        font-size: 0.92rem;
    }

    .header-form-page textarea.form-control {
        min-height: 140px;
    }

    .header-form-page .form-control:focus,
    .header-form-page .form-select:focus,
    .header-form-page textarea.form-control:focus {
        border-color: #92b2ff;
        box-shadow: 0 0 0 0.15rem rgba(55, 106, 195, 0.12);
    }

    .header-form-page .form-text,
    .header-form-page small.text-muted {
        font-size: 0.77rem;
        color: var(--hf-text) !important;
        line-height: 1.4;
    }

    .header-form-page .section-card {
        padding: 14px;
        height: 100%;
        background: #fcfdff;
    }

    .header-form-page .section-title {
        font-size: 0.98rem;
        font-weight: 700;
        color: var(--hf-heading);
        margin-bottom: 4px;
    }

    .header-form-page .section-subtitle {
        font-size: 0.8rem;
        color: var(--hf-text);
        margin-bottom: 14px;
    }

    .header-form-page .check-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 10px;
    }

    .header-form-page .check-item {
        border: 1px solid var(--hf-border);
        background: var(--hf-soft);
        border-radius: 12px;
        padding: 10px 12px;
        min-height: 56px;
        display: flex;
        align-items: center;
    }

    .header-form-page .form-check {
        margin: 0;
        width: 100%;
    }

    .header-form-page .form-check-label {
        font-weight: 600;
        font-size: 0.86rem;
        color: var(--hf-heading);
    }

    .header-form-page .form-check-input {
        margin-top: 0.15rem;
    }

    .header-form-page .form-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        justify-content: flex-end;
        padding-top: 16px;
        border-top: 1px solid var(--hf-border);
        margin-top: 18px;
    }

    .header-form-page .btn {
        border-radius: 10px;
        font-weight: 600;
    }

    .header-form-page .btn-primary {
        box-shadow: 0 8px 18px rgba(55, 106, 195, 0.16);
    }

    @media (max-width: 767.98px) {
        .header-form-page .hero-card,
        .header-form-page .form-shell .card-body {
            padding: 15px;
        }

        .header-form-page .hero-title {
            font-size: 1.25rem;
        }

        .header-form-page .check-grid {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="container-fluid py-2 px-2 px-md-3 header-form-page">
    <div class="hero-card mb-3">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
            <div>
                <h3 class="hero-title">Create Header</h3>
                <p class="hero-text">New header add korun. Input, formula, ba conditional field easily configure korte parben.</p>
            </div>
            <a href="{{ route('admin.headers.index') }}" class="btn btn-outline-secondary">Back to List</a>
        </div>
    </div>

    <div class="card form-shell border-0">
        <div class="card-body">
            <form method="POST" action="{{ route('admin.headers.store') }}">
                @csrf
                @include('admin.headers.form')

                <div class="form-actions">
                    <a href="{{ route('admin.headers.index') }}" class="btn btn-outline-secondary px-3">Back</a>
                    <button type="submit" class="btn btn-primary px-4">Save Header</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
