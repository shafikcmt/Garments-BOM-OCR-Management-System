@extends('layouts.app')

@section('content')
@php
    $list = fn ($values, $fallback = '-') => collect($values ?? [])->map(fn ($v) => trim((string) $v))->filter()->take(5)->implode(', ') ?: $fallback;
    $money = fn ($value) => number_format((float) $value, 2);
    $paymentRequired = $summary['earliest_payment_required_date'] ? optional($summary['earliest_payment_required_date'])->format('Y-m-d') : '-';
    $filters = [
        'Final Status' => $list($summary['final_statuses'] ?? []),
        'Vendor Type' => $list($summary['vendor_types'] ?? []),
        'Payment Term' => $list($summary['payment_terms'] ?? []),
        'Payment Status' => $list($summary['payment_statuses'] ?? []),
        'Vendor Name' => $list($summary['suppliers'] ?? [], $paymentRequest->supplier_name ?: '-'),
    ];
    $logoPath = public_path('images/humana-logo.png');
    $logoData = null;
    if (file_exists($logoPath)) {
        $logoData = 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath));
        if (function_exists('imagecreatefrompng') && function_exists('imagecrop')) {
            $src = @imagecreatefrompng($logoPath);
            if ($src) {
                $w = imagesx($src); $h = imagesy($src);
                $crop = @imagecrop($src, ['x' => 0, 'y' => max(0, (int) ($h * .22)), 'width' => $w, 'height' => min($h, (int) ($h * .58))]);
                if ($crop) {
                    imagesavealpha($crop, true);
                    ob_start(); imagepng($crop); $png = ob_get_clean();
                    $logoData = 'data:image/png;base64,' . base64_encode($png);
                    imagedestroy($crop);
                }
                imagedestroy($src);
            }
        }
    }
@endphp

<style>
    .pra-page { background:#f3f6fb; }
    .pra-toolbar { position:sticky; top:0; z-index:5; background:rgba(243,246,251,.94); backdrop-filter:blur(6px); padding-top:.75rem; }
    .pra-sheet { background:#fff; border:1px solid #cbd5e1; box-shadow:0 12px 26px rgba(15,23,42,.08); }
    .pra-accent { height:5px; background:linear-gradient(90deg,#0f172a,#1d4ed8,#38bdf8); }
    .pra-logo { height:48px; max-width:185px; object-fit:contain; }
    .pra-title { font-weight:800; letter-spacing:.02em; color:#111827; }
    .pra-request { color:#1d4ed8; font-weight:800; font-size:12px; }
    .pra-meta { font-size:12px; line-height:1.45; }
    .pra-line { border-top:1px solid #dbe3ee; border-bottom:1px solid #dbe3ee; background:#f8fafc; }
    .pra-filter-title { background:#e5e7eb; font-size:10px; font-weight:800; color:#111827; text-transform:uppercase; letter-spacing:.03em; }
    .pra-filter-value { font-size:11px; min-height:27px; }
    .pra-table { table-layout:fixed; width:100%; }
    .pra-table th { background:#1f2937; color:#fff; font-size:9px; font-weight:800; white-space:normal; text-transform:uppercase; letter-spacing:.02em; padding:.38rem .32rem; }
    .pra-table td { font-size:10px; padding:.32rem .34rem; line-height:1.25; word-break:break-word; }
    .pra-table tbody tr:nth-child(even) td { background:#f8fafc; }
    .pra-table .c-vendor { width:11%; }
    .pra-table .c-style { width:8%; }
    .pra-table .c-date { width:8%; }
    .pra-table .c-term { width:10%; }
    .pra-table .c-po { width:11%; }
    .pra-table .c-season { width:6%; }
    .pra-table .c-pi { width:11%; }
    .pra-table .c-type { width:8%; }
    .pra-table .c-status { width:9%; }
    .pra-table .c-amount { width:9%; }
    .pra-sign-line { border-top:1px solid #111827; padding-top:4px; font-weight:800; }
    .pra-note { font-size:15px; line-height:1.5; }
    .pra-check { color:#1d4ed8; font-size:12px; line-height:1.55; }
    @media print {
        @page { size: A4 landscape; margin: 7mm; }
        html, body { width: 297mm; min-height: 0 !important; background:#fff !important; overflow: visible !important; }
        body * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
        .no-print, aside, nav, header, .sidebar, .navbar, .pra-toolbar { display:none !important; }
        .pra-page, .container-fluid { background:#fff !important; padding:0 !important; margin:0 !important; max-width:none !important; width:100% !important; }
        .pra-sheet { box-shadow:none !important; border:0 !important; border-radius:0 !important; padding:0 !important; margin:0 !important; width:100% !important; page-break-inside:avoid; }
        .pra-accent { height:4px !important; }
        .pra-print-pad { padding:8px 10px 0 !important; }
        .pra-logo { height:45px !important; max-width:175px !important; }
        .pra-title { font-size:20px !important; margin-bottom:0 !important; }
        .pra-request, .pra-meta, .pra-line { font-size:10px !important; }
        .pra-filter-title { font-size:8px !important; padding:2px 4px !important; }
        .pra-filter-value { font-size:8px !important; min-height:18px !important; padding:2px 4px !important; }
        .table-responsive { overflow:visible !important; width:100% !important; }
        .pra-table { width:100% !important; table-layout:fixed !important; }
        .pra-table th { font-size:7.2px !important; padding:3px 2px !important; line-height:1.12 !important; }
        .pra-table td { font-size:7.2px !important; padding:3px 2px !important; line-height:1.12 !important; }
        .pra-sign-area { margin-top:22px !important; }
        .pra-note { font-size:12px !important; }
        .pra-check { font-size:9px !important; }
        .pra-bottom-area { margin-top:12px !important; }
    }
</style>

<div class="container-fluid py-3 pra-page">
    <div class="pra-toolbar d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 no-print">
        <div>
            <div class="text-uppercase text-primary fw-bold small" style="letter-spacing:.08em;">Payment Request</div>
            <h5 class="fw-bold mb-0">{{ $paymentRequest->request_no }}</h5>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="{{ route('supply_chain.payment_requests.index') }}" class="btn btn-light border rounded-pill"><i class="bi bi-arrow-left me-1"></i> Back</a>
            <button type="button" onclick="window.print()" class="btn btn-primary rounded-pill"><i class="bi bi-printer me-1"></i> Print</button>
            <a href="{{ route('supply_chain.payment_requests.download_pdf', $paymentRequest) }}" target="_blank" rel="noopener" class="btn btn-danger rounded-pill"><i class="bi bi-filetype-pdf me-1"></i> PDF Preview</a>
            <a href="{{ route('supply_chain.payment_requests.download_excel', $paymentRequest) }}" class="btn btn-success rounded-pill"><i class="bi bi-file-earmark-excel me-1"></i> Excel</a>
        </div>
    </div>

    @if(session('success'))
        <div class="alert alert-success rounded-4 border-0 shadow-sm no-print">{{ session('success') }}</div>
    @endif

    <div class="pra-sheet rounded-3 overflow-hidden">
        <div class="pra-accent"></div>
        <div class="pra-print-pad p-3">
            <div class="row align-items-center g-2 mb-2">
                <div class="col-3">
                    @if($logoData)
                        <img src="{{ $logoData }}" alt="Logo" class="pra-logo">
                    @endif
                </div>
                <div class="col-6 text-center">
                    <h4 class="pra-title mb-0">Payment Request Approval</h4>
                    <div class="pra-request">{{ $paymentRequest->request_no }}</div>
                </div>
                <div class="col-3 text-end fw-semibold pra-meta">
                    <div>Date: {{ optional($paymentRequest->created_at)->format('jS M-Y') }}</div>
                    <div>Payment Require Date: {{ $paymentRequired }}</div>
                </div>
            </div>

            <div class="row align-items-center g-0 mb-2 fw-bold pra-line py-1 px-2 rounded-2">
                <div class="col-4">Buyer: {{ $list($summary['buyers'] ?? [], $paymentRequest->buyer_name ?: '-') }}</div>
                <div class="col-4 text-center">Season: {{ $list($summary['seasons'] ?? [], $paymentRequest->season_name ?: '-') }}</div>
                <div class="col-4 text-end">Total PI Amount: ${{ $money($summary['total_pi_amount'] ?? 0) }}</div>
            </div>

            <div class="row g-1 mb-2">
                @foreach($filters as $label => $value)
                    <div class="col">
                        <div class="border h-100 bg-white">
                            <div class="pra-filter-title px-2 py-1 border-bottom">{{ $label }}</div>
                            <div class="pra-filter-value px-2 py-1">{{ $value ?: '-' }}</div>
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="table-responsive">
                <table class="table table-bordered align-middle pra-table mb-0">
                    <thead>
                        <tr>
                            <th class="c-vendor">Vendor Name</th>
                            <th class="c-style">Style</th>
                            <th class="c-date">PCD Required</th>
                            <th class="c-term">Payment Term</th>
                            <th class="c-po">Material PO Number</th>
                            <th class="c-season">Season</th>
                            <th class="c-pi">Material PI Number</th>
                            <th class="c-type">Material Type</th>
                            <th class="c-status">Payment Status</th>
                            <th class="c-date">Contract Shipment</th>
                            <th class="c-date">Committed Ex Mill</th>
                            <th class="c-amount text-end">PI Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($paymentRequest->items as $item)
                            <tr>
                                <td>{{ $item->supplier_name ?: '-' }}</td>
                                <td>{{ $item->style_name ?: '-' }}</td>
                                <td class="text-center">{{ data_get($item->data, 'pcd_required') ?: '-' }}</td>
                                <td>{{ $item->payment_term ?: '-' }}</td>
                                <td class="fw-bold text-primary">{{ $item->po_no ?: '-' }}</td>
                                <td>{{ $item->season_name ?: '-' }}</td>
                                <td class="fw-semibold">{{ $item->pi_number ?: '-' }}</td>
                                <td>{{ data_get($item->data, 'material_type') ?: '-' }}</td>
                                <td>{{ $item->payment_status ?: '-' }}</td>
                                <td class="text-center">{{ data_get($item->data, 'contract_shipment') ?: '-' }}</td>
                                <td class="text-center">{{ data_get($item->data, 'committed_ex_mill') ?: '-' }}</td>
                                <td class="text-end fw-bold">${{ $money($item->pi_amount) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="table-light fw-bold">
                            <td colspan="11">Grand Total</td>
                            <td class="text-end">${{ $money($summary['total_pi_amount'] ?? 0) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <div class="row g-3 mt-4 pra-sign-area">
                <div class="col-4"><div class="pra-sign-line">Prepared By</div><div class="small text-muted mt-1">Name: {{ optional($paymentRequest->createdBy)->name ?: '' }}</div></div>
                <div class="col-4"><div class="pra-sign-line">Checked By</div><div class="small text-muted mt-1">Name: {{ optional($paymentRequest->checkedBy)->name ?: '' }}</div></div>
                <div class="col-4"><div class="pra-sign-line">Approved By</div><div class="small text-muted mt-1">Name: {{ optional($paymentRequest->approvedBy)->name ?: '' }}</div></div>
            </div>

            <div class="row g-3 mt-3 pra-bottom-area">
                <div class="col-7 pra-note">
                    Buyer nominated supplier.<br>
                    No excess quantity has been booked.
                </div>
                <div class="col-5 pra-check">
                    <div>OCR Checked: ☐ Yes &nbsp;&nbsp; ☐ No</div>
                    <div>Nominated Supplier: ☐ Yes &nbsp;&nbsp; ☐ No</div>
                    <div>Checker Name: __________________</div>
                    <div>Date: __________________</div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
