@extends('layouts.app')

@section('content')
@php
    $approvalRows = collect($approvalRows ?? []);
    $list = fn ($values, $fallback = '-') => collect($values ?? [])->map(fn ($v) => trim((string) $v))->filter()->take(5)->implode(', ') ?: $fallback;
    $money = fn ($value) => number_format((float) $value, 2);
    $paymentRequiredDate = $summary['earliest_payment_required_date'] ?? null;
    $paymentRequiredParts = $paymentRequiredDate
        ? [optional($paymentRequiredDate)->format('d'), optional($paymentRequiredDate)->format('m'), optional($paymentRequiredDate)->format('Y')]
        : ['--', '--', '----'];
    $logoPath = public_path('images/humana-logo.png');
    $logoData = null;
    if (file_exists($logoPath)) {
        $logoData = 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath));
    }
@endphp

<style>
    .pra-wrap { background:#f3f6fb; }
    .pra-toolbar { position:sticky; top:0; z-index:5; background:rgba(243,246,251,.94); backdrop-filter:blur(6px); padding-top:.75rem; }
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
        <div class="pra-toolbar d-flex justify-content-between align-items-center gap-2 mb-3">
            <div>
                <a href="{{ route('supply_chain.payment_requests.index') }}" class="btn btn-light border rounded-pill">← Back</a>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <button type="button" onclick="window.print()" class="btn btn-outline-primary rounded-pill">Print</button>
                <a href="{{ route('supply_chain.payment_requests.download_pdf', $paymentRequest) }}" target="_blank" rel="noopener" class="btn btn-outline-danger rounded-pill">PDF Preview</a>
                <a href="{{ route('supply_chain.payment_requests.download_excel', $paymentRequest) }}" class="btn btn-success rounded-pill">Excel Download</a>
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
                    <div class="pra-request-no">{{ $paymentRequest->request_no }}</div>
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
