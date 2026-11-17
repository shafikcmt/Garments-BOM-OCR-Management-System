@extends('layouts.app')

@section('title', 'CS Signature Settings')

@section('content')
<div class="container-fluid">
    <x-breadcrumb :items="[
        ['label' => 'Admin', 'url' => route('admin.dashboard')],
        ['label' => 'Settings'],
        ['label' => 'CS Signatures'],
    ]" />

    <div class="app-hero-card p-4 mb-4">
        <div class="d-flex align-items-center gap-3">
            <span class="app-stat-icon" style="width:46px;height:46px;border-radius:15px;font-size:20px;"><i class="bi bi-pen" aria-hidden="true"></i></span>
            <div>
                <div class="app-hero-eyebrow">Supply Chain / Settings</div>
                <h3 class="app-hero-title mb-0">CS Signatures</h3>
                <p class="app-hero-copy mb-0">The signature boxes printed at the bottom of a General Stock Comparative Statement. A new sheet starts with these; each saved sheet keeps its own copy, so changing them here never alters a sheet already made.</p>
            </div>
        </div>
    </div>

    @if(session('success'))
        <div class="alert alert-success border-0 shadow-sm rounded-3">{{ session('success') }}</div>
    @endif
    @if($errors->any())
        <div class="alert alert-danger border-0 shadow-sm rounded-3"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif

    <form method="POST" action="{{ route('admin.cs-signatures.update') }}" class="card gx-stock-card mb-3">
        @csrf
        <div class="gx-stock-card-body">
            <h5 class="mb-1">Signature Boxes</h5>
            <p class="text-muted small mb-3">Printed left to right in this order. Up to {{ $max }} boxes. Leave a title empty to drop that box.</p>

            <div class="table-responsive">
                <table class="table align-middle mb-3">
                    <thead>
                        <tr><th style="width:50px;">#</th><th>Title printed</th><th>Name &amp; date filled from</th></tr>
                    </thead>
                    <tbody>
                        @foreach(range(0, $max - 1) as $i)
                            @php($box = $boxes[$i] ?? ['label' => '', 'role' => 'blank'])
                            <tr>
                                <td>{{ $i + 1 }}</td>
                                <td><input type="text" name="boxes[{{ $i }}][label]" value="{{ $box['label'] ?? '' }}" maxlength="60" class="form-control" placeholder="Not printed"></td>
                                <td>
                                    <select name="boxes[{{ $i }}][role]" class="form-select">
                                        @foreach($roles as $key => $text)
                                            <option value="{{ $key }}" @selected(($box['role'] ?? 'blank') === $key)>{{ $text }}</option>
                                        @endforeach
                                    </select>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="d-flex gap-2 flex-wrap">
                <button type="submit" class="btn btn-primary"><i class="bi bi-check2 me-1" aria-hidden="true"></i>Save Signature Boxes</button>
                <button type="submit" name="reset" value="1" class="btn btn-outline-secondary" onclick="return confirm('Go back to the standard three boxes?')"><i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>Use Standard Three</button>
            </div>
        </div>
    </form>
</div>
@endsection
