@extends('layouts.app')

@section('content')
@php
    $approvalRows = collect($approvalRows ?? []);
    $isPreview = (bool) ($isPreview ?? false);
    $bookingPoIds = collect($bookingPoIds ?? [])->filter()->unique()->values();
    $list = fn ($values, $fallback = '-') => collect($values ?? [])->map(fn ($v) => trim((string) $v))->filter()->take(5)->implode(', ') ?: $fallback;
    $money = fn ($value) => number_format((float) $value, 2);
    $paymentRequiredDate = $summary['earliest_payment_required_date'] ?? null;
    $paymentRequiredParts = $paymentRequiredDate
        ? [optional($paymentRequiredDate)->format('d'), optional($paymentRequiredDate)->format('m'), optional($paymentRequiredDate)->format('Y')]
        : ['--', '--', '----'];
    $paymentRequiredInput = $paymentRequiredInput ?? ($paymentRequiredDate ? optional($paymentRequiredDate)->format('Y-m-d') : now()->addDays(7)->toDateString());
    $logoPath = public_path('images/humana-logo.png');
    $logoData = null;
    if (file_exists($logoPath)) {
        $logoData = 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath));
    }
@endphp

<style>
    .pra-wrap { background:#f3f6fb; }
    .pra-toolbar { position:sticky; top:0; z-index:5; background:rgba(243,246,251,.94); backdrop-filter:blur(6px); padding-top:.75rem; }
    .pra-toolbar-card { background:#fff; border:1px solid #e4ebf7; border-radius:18px; padding:12px 14px; box-shadow:0 10px 28px rgba(15,23,42,.08); }
    .pra-back-btn { height:36px; display:inline-flex; align-items:center; gap:6px; border-radius:10px; padding:0 13px; font-weight:700; color:#0f172a; background:#fff; border:1px solid #d8e1ef; text-decoration:none; }
    .pra-back-btn:hover { color:#0b1d5b; background:#f8fbff; border-color:#b9c8df; }
    .pra-preview-badge { display:inline-flex; align-items:center; gap:6px; border-radius:999px; padding:8px 12px; background:#fff3cd; color:#7a4b00; font-size:12px; font-weight:800; }
    .pra-preview-help { color:#64748b; font-size:13px; font-weight:600; }
    .pra-date-panel { display:flex; align-items:flex-end; justify-content:flex-end; flex-wrap:wrap; gap:10px; }
    .pra-date-panel label { font-size:11px; font-weight:800; color:#0b1d5b; margin-bottom:5px; letter-spacing:.03em; }
    .pra-date-panel .form-control { height:36px; min-width:154px; border-radius:10px; border-color:#d4deef; font-size:13px; font-weight:600; }
    .pra-toolbar-btn { height:36px; display:inline-flex; align-items:center; justify-content:center; gap:6px; border-radius:10px; padding:0 14px; font-size:12px; font-weight:800; white-space:nowrap; }
    .pra-toolbar-btn-create { min-width:116px; }
    @media (max-width: 991.98px) { .pra-date-panel { justify-content:flex-start; } .pra-toolbar-card { align-items:flex-start !important; } }
    .pra-sheet { background:#fff; color:#000b6f; padding:26px 28px 34px; min-width:1120px; box-shadow:0 12px 36px rgba(15,23,42,.12); border:1px solid #e6ebf4; }
    .pra-logo-text { font-size:30px; line-height:.95; letter-spacing:.08em; font-weight:600; color:#000b6f; }
    .pra-logo-small { font-size:9px; letter-spacing:.06em; font-weight:700; margin-left:58px; }
    .pra-company { font-size:11px; letter-spacing:.08em; font-weight:700; margin-top:16px; }
    .pra-title { font-size:32px; line-height:1; font-weight:800; letter-spacing:.02em; color:#000b6f; text-align:center; }
    .pra-request-no { font-size:15px; margin-top:8px; text-align:center; color:#000b6f; letter-spacing:.04em; }
    .pra-date { font-size:13px; font-weight:700; text-align:right; color:#000b6f; }
    .pra-date-box { display:inline-flex; width:45px; height:34px; align-items:center; justify-content:center; border:1px solid #8da0d8; border-radius:3px; font-weight:800; margin:0 6px; }
    .pra-date-box.year { width:64px; }
    .pra-check-box { border:1px solid #4a5cb2; border-radius:3px; min-height:102px; padding:15px 16px; font-size:13px; color:#000b6f; }
    .pra-mini-box { display:inline-block; width:16px; height:16px; border:1px solid #91a1d0; vertical-align:middle; margin:0 7px 0 18px; }
    .pra-info { font-size:13px; line-height:2.05; font-weight:700; color:#000b6f; }
    .pra-note { font-size:12px; line-height:1.45; font-weight:700; color:#000b6f; }
    .pra-total { font-size:18px; font-weight:800; text-align:right; color:#000b6f; margin-bottom:10px; }
    .pra-table { color:#101828; border-color:#e5e7eb; table-layout:fixed; }
    .pra-table thead th { background:#000b6f; color:#fff; border-color:#33439e; font-size:11px; line-height:1.15; padding:12px 8px; text-align:center; vertical-align:middle; }
    .pra-table tbody td { font-size:12px; padding:12px 9px; border-color:#e7eaf1; vertical-align:middle; word-break:break-word; }
    .pra-table tfoot td { background:#eaf0fb; color:#000b6f; font-size:16px; font-weight:800; padding:13px 9px; border-color:#e7eaf1; }
    .c-vendor { width:12%; } .c-style { width:10%; } .c-date { width:9.5%; } .c-term { width:10%; } .c-po { width:11%; }
    .c-pi { width:12%; } .c-type { width:9%; } .c-comments { width:8%; } .c-amount { width:9%; }
    .pra-sign-area { margin-top:52px; color:#000b6f; }
    .pra-sign-title { font-size:13px; font-weight:800; margin-bottom:30px; }
    .pra-sign-text { font-size:13px; margin-bottom:30px; }
    .pra-sign-line { border-bottom:1px solid #000b6f; height:1px; }
    .pra-sign-sep { border-left:1px solid #8d9bd3; }
    @media print {
        body { background:#fff !important; }
        .pra-toolbar, .sidebar, nav, header { display:none !important; }
        .content-wrapper, main, .pra-wrap { margin:0 !important; padding:0 !important; background:#fff !important; }
        .pra-sheet { min-width:0; width:100%; box-shadow:none; border:0; padding:12px; }
        @page { size: A4 landscape; margin:8mm; }
    }
</style>

<div class="pra-wrap py-3">
    <div class="container-fluid">
        <div class="pra-toolbar mb-3">
            <div class="pra-toolbar-card d-flex flex-wrap justify-content-between align-items-center gap-3">
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <a href="{{ route('supply_chain.payment_requests.index') }}" class="pra-back-btn">← Back</a>
                    @if($isPreview)
                        <span class="pra-preview-badge"><i class="bi bi-eye"></i> Preview Mode</span>
                        <span class="pra-preview-help">Review the Payment Required Date, then click Create PRA to generate the approval.</span>
                    @endif
                </div>

                @if($isPreview)
                    <div class="pra-date-panel">
                        <form method="GET" action="{{ route('supply_chain.payment_requests.preview') }}" class="d-flex flex-wrap align-items-end gap-2 m-0">
                            @foreach($bookingPoIds as $bookingPoId)
                                <input type="hidden" name="booking_po_ids[]" value="{{ $bookingPoId }}">
                            @endforeach
                            <div>
                                <label for="paymentRequiredPreviewDate">Payment Require Date</label>
                                <input type="date" name="payment_required_date" id="paymentRequiredPreviewDate" value="{{ $paymentRequiredInput }}" class="form-control form-control-sm" required>
                            </div>
                            <button type="submit" class="btn btn-outline-primary pra-toolbar-btn">
                                <i class="bi bi-arrow-repeat"></i> Update Preview
                            </button>
                        </form>

                        <form method="POST" action="{{ route('supply_chain.payment_requests.store') }}" class="m-0">
                            @csrf
                            @foreach($bookingPoIds as $bookingPoId)
                                <input type="hidden" name="booking_po_ids[]" value="{{ $bookingPoId }}">
                            @endforeach
                            <input type="hidden" name="payment_required_date" id="paymentRequiredCreateDate" value="{{ $paymentRequiredInput }}">
                            <button type="submit" class="btn btn-success pra-toolbar-btn pra-toolbar-btn-create">
                                <i class="bi bi-check2-circle"></i> Create PRA
                            </button>
                        </form>
                    </div>
                @else
                    <div class="d-flex flex-wrap gap-2">
                        <button type="button" onclick="window.print()" class="btn btn-outline-primary rounded-pill">Print</button>
                        <a href="{{ route('supply_chain.payment_requests.download_pdf', $paymentRequest) }}" target="_blank" rel="noopener" class="btn btn-outline-danger rounded-pill">PDF Preview</a>
                        <a href="{{ route('supply_chain.payment_requests.download_excel', $paymentRequest) }}" class="btn btn-success rounded-pill">Excel Download</a>
                    </div>
                @endif
            </div>
        </div>

        <div class="pra-sheet mx-auto">
            <div class="row g-0 align-items-start mb-3">
                <div class="col-3">
                    @if($logoData)
                        <img src="{{ $logoData }}" alt="Humana" style="height:62px;max-width:190px;object-fit:contain;">
                    @else
                        <div class="pra-logo-text">HUMANA</div>
                        <div class="pra-logo-small">APPARELS PVT. LTD.</div>
                    @endif
                    <div class="pra-company">HUMANA APPARELS PVT. LTD.</div>
                </div>
                <div class="col-6 pt-1">
                    <div class="pra-title">Payment Request Approval</div>
                    <div class="pra-request-no">{{ $isPreview ? 'Preview - PR number will generate after Create' : $paymentRequest->request_no }}</div>
                </div>
                <div class="col-3">
                    <div class="pra-date mb-3">Date:&nbsp;&nbsp; {{ optional($paymentRequest->created_at)->format('jS M-Y') }}</div>
                    <div class="pra-date d-flex justify-content-end align-items-center flex-wrap">
                        <span>Payment Require Date:</span>
                        <span class="pra-date-box">{{ $paymentRequiredParts[0] }}</span><span>/</span>
                        <span class="pra-date-box">{{ $paymentRequiredParts[1] }}</span><span>/</span>
                        <span class="pra-date-box year">{{ $paymentRequiredParts[2] }}</span>
                    </div>
                </div>
            </div>

            <div class="row g-3 align-items-start mb-3">
                <div class="col-7">
                    <div class="pra-info mt-3">
                        <div>Buyer <span class="d-inline-block mx-4">:</span> {{ $list($summary['buyers'] ?? [], $paymentRequest->buyer_name ?: '-') }}</div>
                        <div>Season <span class="d-inline-block mx-3">:</span> {{ $list($summary['seasons'] ?? [], $paymentRequest->season_name ?: '-') }}</div>
                    </div>
                    <div class="pra-note mt-3">
                        * Buyer nominated supplier.<br>
                        &nbsp;&nbsp;No excess quantity has been booked.
                    </div>
                </div>
                <div class="col-5">
                    <div class="pra-check-box">
                        <div class="fw-bold mb-3">OCR Checked: <span class="pra-mini-box"></span> Yes <span class="pra-mini-box"></span> No</div>
                        <div class="mb-3">Checker Name <span class="mx-3">:</span> <span class="d-inline-block border-bottom" style="width:170px;"></span></div>
                        <div>Date <span class="mx-5">:</span> <span class="d-inline-block border-bottom" style="width:170px;"></span></div>
                    </div>
                </div>
            </div>

            <div class="pra-total">Total PI Amount: $ {{ $money($summary['total_pi_amount'] ?? 0) }}</div>

            <div class="table-responsive">
                <table class="table table-bordered align-middle pra-table mb-0">
                    <thead>
                        <tr>
                            <th class="c-vendor">Vendor Name</th>
                            <th class="c-style">Style</th>
                            <th class="c-date">PCD Required</th>
                            <th class="c-term">Payment Term</th>
                            <th class="c-po">Material PO Number</th>
                            <th class="c-pi">Material PI Number</th>
                            <th class="c-type">Material Type</th>
                            <th class="c-date">Contract Shipment</th>
                            <th class="c-date">Committed Ex Mill</th>
                            <th class="c-comments">Comments</th>
                            <th class="c-amount text-end">PI Amount (USD)</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($approvalRows as $row)
                            <tr>
                                <td>{{ $row['vendor_name'] ?: '-' }}</td>
                                <td>{{ $row['style'] ?: '-' }}</td>
                                <td class="text-center">{{ $row['pcd_required'] ?: '-' }}</td>
                                <td>{{ $row['payment_term'] ?: '-' }}</td>
                                <td>{{ $row['material_po_number'] ?: '-' }}</td>
                                <td>{{ $row['material_pi_number'] ?: '-' }}</td>
                                <td>{{ $row['material_type'] ?: '-' }}</td>
                                <td class="text-center">{{ $row['contract_shipment'] ?: '-' }}</td>
                                <td class="text-center">{{ $row['committed_ex_mill'] ?: '-' }}</td>
                                <td>{{ $row['comments'] ?: '(blank)' }}</td>
                                <td class="text-end fw-bold">$ {{ $money($row['pi_amount'] ?? 0) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="11" class="text-center py-5 text-muted">No payment request item found.</td></tr>
                        @endforelse
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="10">Grand Total</td>
                            <td class="text-end">$ {{ $money($summary['total_pi_amount'] ?? 0) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <div class="row g-0 pra-sign-area">
                <div class="col-4 pe-4">
                    <div class="pra-sign-title">Prepared By</div>
                    <div class="pra-sign-text">Signature &amp; Date</div>
                    <div class="pra-sign-line"></div>
                </div>
                <div class="col-4 px-4 pra-sign-sep">
                    <div class="pra-sign-title">Checked By</div>
                    <div class="pra-sign-text">Signature &amp; Date</div>
                    <div class="pra-sign-line"></div>
                </div>
                <div class="col-4 ps-4 pra-sign-sep">
                    <div class="pra-sign-title">Approved By</div>
                    <div class="pra-sign-text">Signature &amp; Date</div>
                    <div class="pra-sign-line"></div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@section('scripts')
@if($isPreview)
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const previewDate = document.getElementById('paymentRequiredPreviewDate');
        const createDate = document.getElementById('paymentRequiredCreateDate');

        if (previewDate && createDate) {
            previewDate.addEventListener('change', function () {
                createDate.value = previewDate.value;
            });
        }
    });
</script>
@endif
@endsection
