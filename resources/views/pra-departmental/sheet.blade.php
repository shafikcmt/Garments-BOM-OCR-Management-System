@extends('layouts.app')

@section('title', 'PRA Sheet ' . $paymentRequest->request_no)

@section('styles')
<style>
    .pra-sheet-bar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; padding: 14px 18px; background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; box-shadow: 0 1px 3px rgba(15,23,42,.05); }
    .pra-sheet-actions { display: flex; gap: 8px; flex-wrap: wrap; }
    .pra-sheet-btn { display: inline-flex; align-items: center; gap: 6px; height: 36px; padding: 0 16px; border-radius: 9px; border: 1px solid #cbd5e1; background: #fff; color: #334155; font-size: 13px; font-weight: 700; text-decoration: none; cursor: pointer; }
    .pra-sheet-btn:hover { background: #f1f5f9; color: #0f172a; }
    .pra-sheet-btn.is-primary { background: #006C9D; border-color: #006C9D; color: #fff; }
    .pra-sheet-btn.is-primary:hover { background: #005a84; color: #fff; }
    .pra-sheet-stage { margin-top: 16px; padding: 22px 12px 30px; background: #e8edf3; border-radius: 14px; overflow-x: auto; }
    .pra-sheet-holder { margin: 0 auto; background: #fff; box-shadow: 0 18px 40px rgba(15,23,42,.16); }
    .pra-sheet-frame { display: block; width: 1123px; border: 0; background: #fff; transform-origin: top left; }
</style>
@endsection

@section('content')
<div class="container-fluid">
    <x-breadcrumb :items="[
        ['label' => 'PRA Approvals', 'url' => route('pra_departmental.all')],
        ['label' => $paymentRequest->request_no, 'url' => route('pra_departmental.show', $paymentRequest)],
        ['label' => 'PRA Sheet'],
    ]" />

    <div class="pra-sheet-bar">
        <div>
            <div class="fw-bold">Payment Request Approval</div>
            <div class="small text-muted">{{ $paymentRequest->request_no }} &middot; {{ $paymentRequest->supplier_name ?: 'Supplier' }} &middot; Read-only copy</div>
        </div>
        <div class="pra-sheet-actions">
            <a href="{{ route('pra_departmental.show', $paymentRequest) }}" class="pra-sheet-btn"><i class="bi bi-arrow-left" aria-hidden="true"></i>Back to PRA</a>
            <a href="{{ route('pra_departmental.sheet_pdf', $paymentRequest) }}" target="_blank" rel="noopener" class="pra-sheet-btn"><i class="bi bi-file-earmark-pdf" aria-hidden="true"></i>Download PDF</a>
            <button type="button" class="pra-sheet-btn is-primary" id="praSheetPrint"><i class="bi bi-printer" aria-hidden="true"></i>Print</button>
        </div>
    </div>

    {{-- The sheet is laid out for A4 landscape (1123px). It is shown on paper, scaled up
         to fill the width so the small print is readable. --}}
    <div class="pra-sheet-stage" id="praSheetStage">
        <div class="pra-sheet-holder" id="praSheetHolder">
            <iframe id="praSheetFrame" class="pra-sheet-frame" src="{{ route('pra_departmental.sheet_html', $paymentRequest) }}" title="PRA sheet {{ $paymentRequest->request_no }}"></iframe>
        </div>
    </div>
</div>
@endsection

@section('scripts')
<script>
(function () {
    const stage = document.getElementById('praSheetStage');
    const holder = document.getElementById('praSheetHolder');
    const frame = document.getElementById('praSheetFrame');
    const PAGE_W = 1123;

    function fit() {
        const available = stage.clientWidth - 24;
        const scale = Math.max(0.6, Math.min(available / PAGE_W, 1.7));
        let height = 794;
        try { height = Math.max(794, frame.contentDocument.documentElement.scrollHeight + 20); } catch (e) {}
        frame.style.height = height + 'px';
        frame.style.transform = 'scale(' + scale + ')';
        holder.style.width = (PAGE_W * scale) + 'px';
        holder.style.height = (height * scale) + 'px';
    }

    frame.addEventListener('load', fit);
    window.addEventListener('resize', fit);
    document.getElementById('praSheetPrint').addEventListener('click', function () {
        frame.contentWindow.focus();
        frame.contentWindow.print();
    });
    fit();
})();
</script>
@endsection
