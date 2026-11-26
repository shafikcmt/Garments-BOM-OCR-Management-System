@extends('layouts.app')

@section('title', 'PRA Sheet ' . $paymentRequest->request_no)

@section('content')
<div class="container-fluid">
    <x-breadcrumb :items="[
        ['label' => 'PRA Approvals', 'url' => route('pra_departmental.all')],
        ['label' => $paymentRequest->request_no, 'url' => route('pra_departmental.show', $paymentRequest)],
        ['label' => 'PRA Sheet'],
    ]" />

    <div class="card border-0 shadow-sm" style="border-radius:14px;overflow:hidden;">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 px-3 py-2 border-bottom bg-white">
            <div>
                <div class="fw-bold">Payment Request Approval</div>
                <div class="small text-muted">{{ $paymentRequest->request_no }} &middot; {{ $paymentRequest->supplier_name ?: 'Supplier' }} &middot; Read-only copy</div>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <a href="{{ route('pra_departmental.show', $paymentRequest) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Back to PRA</a>
                <a href="{{ route('pra_departmental.sheet_pdf', $paymentRequest) }}" target="_blank" rel="noopener" class="btn btn-sm btn-primary"><i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>Open / Print PDF</a>
            </div>
        </div>
        <iframe src="{{ route('pra_departmental.sheet_pdf', $paymentRequest) }}#view=FitH" title="PRA sheet {{ $paymentRequest->request_no }}" style="width:100%;height:calc(100vh - 230px);min-height:560px;border:0;background:#f1f5f9;"></iframe>
    </div>
</div>
@endsection
