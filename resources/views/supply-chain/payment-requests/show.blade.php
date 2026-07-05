@extends('layouts.app')

@section('content')
@php
    $approvalRows = collect($approvalRows ?? []);
    $isPreview = (bool) ($isPreview ?? false);
    $budgetCheck = $budgetCheck ?? ['exceeded' => false, 'lines' => []];
    $canOverrideBudget = (bool) ($canOverrideBudget ?? false);
    $bookingPoIds = collect($bookingPoIds ?? [])->filter()->unique()->values();
    $list = fn ($values, $fallback = '-') => collect($values ?? [])->map(fn ($v) => trim((string) $v))->filter()->take(5)->implode(', ') ?: $fallback;
    $money = fn ($value) => number_format((float) $value, 2);
    $paymentRequiredDate = $summary['earliest_payment_required_date'] ?? null;
    if (! $paymentRequiredDate) {
        $snapshotRequiredDate = data_get($paymentRequest->data ?? [], 'payment_required_date');
        $paymentRequiredDate = $snapshotRequiredDate ? \Illuminate\Support\Carbon::parse($snapshotRequiredDate) : null;
    }
    $paymentRequiredInput = $paymentRequiredInput ?? ($paymentRequiredDate ? optional($paymentRequiredDate)->format('Y-m-d') : \App\Support\PaymentRequestSettings::defaultPaymentRequiredDate()->toDateString());
    $signatureBlocks = \App\Support\PaymentRequestSettings::signatureBlocks(false);
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
    .pra-toolbar-btn { height:36px; display:inline-flex; align-items:center; justify-content:center; gap:6px; border-radius:10px; padding:0 14px; font-size:12px; font-weight:800; white-space:nowrap; transition:transform .12s ease, box-shadow .12s ease; }
    .pra-toolbar-btn:not(:disabled):hover { transform:translateY(-1px); box-shadow:0 6px 14px rgba(15,23,42,.14); }
    .pra-toolbar-btn:disabled { opacity:.6; cursor:not-allowed; box-shadow:none; transform:none; }
    .pra-toolbar-btn i { font-size:14px; line-height:1; }
    .pra-toolbar-btn-update { min-width:120px; }
    .pra-toolbar-btn-create { min-width:120px; }
    @media (max-width: 991.98px) { .pra-date-panel { justify-content:flex-start; } .pra-toolbar-card { align-items:flex-start !important; } }
    .pra-sheet { background:#fff; color:#000b6f; padding:26px 28px 34px; min-width:1120px; box-shadow:0 12px 36px rgba(15,23,42,.12); border:1px solid #e6ebf4; }
    .pra-logo-text { font-size:30px; line-height:.95; letter-spacing:.08em; font-weight:600; color:#000b6f; }
    .pra-logo-small { font-size:9px; letter-spacing:.06em; font-weight:700; margin-left:58px; }
    .pra-company { font-size:11px; letter-spacing:.08em; font-weight:700; margin-top:16px; }
    .pra-title { font-size:32px; line-height:1; font-weight:800; letter-spacing:.02em; color:#000b6f; text-align:center; }
    .pra-request-no { font-size:15px; margin-top:8px; text-align:center; color:#000b6f; letter-spacing:.04em; }
    .pra-date { font-size:13px; font-weight:700; text-align:right; color:#000b6f; }
    .pra-date-value { font-weight:800; white-space:nowrap; margin-left:6px; }
    .pra-check-box { border:1px solid #4a5cb2; border-radius:3px; min-height:102px; padding:15px 16px; font-size:13px; color:#000b6f; }
    .pra-mini-box { display:inline-block; width:16px; height:16px; border:1px solid #91a1d0; vertical-align:middle; margin:0 7px 0 18px; }
    .pra-info { font-size:13px; line-height:2.05; font-weight:700; color:#000b6f; }
    .pra-note { font-size:12px; line-height:1.45; font-weight:700; color:#000b6f; }
    .pra-total { font-size:18px; font-weight:800; text-align:right; color:#000b6f; margin-bottom:10px; }
    .pra-table { color:#101828; border-color:#e5e7eb; table-layout:fixed; width:100%; }
    .pra-table thead th { background:#000b6f; color:#fff; border-color:#33439e; font-size:10px; line-height:1.2; padding:8px 5px; text-align:center; vertical-align:middle; word-break:break-word; overflow-wrap:break-word; }
    .pra-table tbody td { font-size:11px; padding:8px 6px; border-color:#e7eaf1; vertical-align:top; word-break:break-word; overflow-wrap:break-word; }
    .pra-table tfoot td { background:#eaf0fb; color:#000b6f; font-size:14px; font-weight:800; padding:10px 6px; border-color:#e7eaf1; }
    .pra-table td.text-end, .pra-table th.text-end, .pra-table tfoot td.text-end { white-space:nowrap; }
    .c-vendor { width:13%; } .c-style { width:9%; } .c-pcd { width:9%; } .c-term { width:9%; } .c-po { width:11%; }
    .c-pi { width:11%; } .c-type { width:8%; } .c-cship { width:9%; } .c-exmill { width:9%; } .c-amount { width:12%; }
    .pra-sign-area { margin-top:52px; color:#000b6f; }
    .pra-sign-title { font-size:13px; font-weight:800; margin-bottom:30px; }
    .pra-sign-text { font-size:13px; margin-bottom:30px; }
    .pra-sign-line { border-bottom:1px solid #000b6f; height:1px; }
    .pra-sign-sep { border-left:1px solid #8d9bd3; }
    .pra-sign-img { max-height:54px; max-width:100%; object-fit:contain; display:block; margin-bottom:6px; }
    .pra-sign-meta { font-size:12px; line-height:1.35; margin-top:6px; color:#000b6f; }
    .pra-sign-meta .pra-sign-name { font-weight:800; }
    @media print {
        body { background:#fff !important; }
        .pra-toolbar, .sidebar, nav, header { display:none !important; }
        .content-wrapper, main, .pra-wrap { margin:0 !important; padding:0 !important; background:#fff !important; }
        .pra-sheet { min-width:0; width:100%; box-shadow:none; border:0; padding:12px; }
        .table-responsive { overflow:visible !important; }
        .pra-table { width:100% !important; table-layout:fixed; }
        .pra-table thead th { font-size:8.5px; padding:4px 3px; }
        .pra-table tbody td { font-size:8.5px; padding:4px 3px; }
        .pra-table tfoot td { font-size:10px; padding:5px 3px; }
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
                            <button type="submit" class="btn btn-outline-primary pra-toolbar-btn pra-toolbar-btn-update" title="Update the Payment Require Date and refresh the preview">
                                <i class="bi bi-arrow-repeat"></i> Update Date
                            </button>
                        </form>

                        <button type="button" class="btn btn-success pra-toolbar-btn pra-toolbar-btn-create" data-bs-toggle="modal" data-bs-target="#createPraModal" title="Create the Payment Request Approval">
                            <i class="bi bi-check2-circle"></i> Create PRA
                        </button>
                    </div>
                @else
                    <div class="d-flex flex-wrap gap-2">
                        <button type="button" onclick="window.print()" class="btn btn-outline-primary rounded-pill">Print</button>
                        <a href="{{ route('supply_chain.payment_requests.download_pdf', $paymentRequest) }}" target="_blank" rel="noopener" class="btn btn-outline-danger rounded-pill">PDF Preview</a>
                        <a href="{{ route('supply_chain.payment_requests.download_excel', $paymentRequest) }}" class="btn btn-success rounded-pill">Excel Download</a>
                        <button type="button" class="btn btn-primary rounded-pill" data-bs-toggle="modal" data-bs-target="#sendEmailModal" title="Email this PRA as a PDF attachment">
                            <i class="bi bi-envelope me-1"></i> Send Email
                        </button>
                    </div>
                @endif
            </div>
        </div>

        @if($isPreview && !empty($budgetCheck['lines']))
            @php $money2 = fn ($v) => number_format((float) $v, 2); @endphp
            <div class="mx-auto mb-3" style="max-width:1120px;">
                @if($budgetCheck['exceeded'])
                    <div class="alert alert-danger border-0 shadow-sm rounded-3 mb-0">
                        <div class="d-flex align-items-start gap-2">
                            <i class="bi bi-exclamation-octagon-fill fs-5"></i>
                            <div class="flex-grow-1">
                                <div class="fw-bold mb-1">Style budget exceeded — creating this PRA is blocked.</div>
                                <ul class="mb-0 ps-3 small">
                                    @foreach(collect($budgetCheck['lines'])->where('over', true) as $line)
                                        <li>
                                            <strong>{{ $line['style'] }}</strong>: budget ${{ $money2($line['budget']) }},
                                            already committed ${{ $money2($line['consumed']) }},
                                            this PRA adds ${{ $money2($line['new']) }}
                                            → total ${{ $money2($line['projected']) }}
                                            (<span class="fw-bold">over by ${{ $money2($line['over_by']) }}</span>).
                                        </li>
                                    @endforeach
                                </ul>
                                @unless($canOverrideBudget)
                                    <div class="small mt-1">Ask an authorised user to override, or update the style budget.</div>
                                @endunless
                            </div>
                        </div>
                    </div>
                @else
                    <div class="alert alert-success border-0 shadow-sm rounded-3 mb-0 py-2 small">
                        <i class="bi bi-check-circle me-1"></i> Within style budget for all
                        {{ collect($budgetCheck['lines'])->count() }} budgeted style(s).
                    </div>
                @endif
            </div>
        @endif

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
                    <div class="pra-date">
                        Payment Require Date:<span class="pra-date-value">{{ $paymentRequiredDate ? optional($paymentRequiredDate)->format('jS M-Y') : '-' }}</span>
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
                            <th class="c-vendor">Vendor</th>
                            <th class="c-style">Style</th>
                            <th class="c-pcd">PCD Date</th>
                            <th class="c-term">Pay Term</th>
                            <th class="c-po">PO No.</th>
                            <th class="c-pi">PI No.</th>
                            <th class="c-type">Type</th>
                            <th class="c-cship">C. Shipment</th>
                            <th class="c-exmill">Ex Mill</th>
                            <th class="c-amount text-end">PI Amt (USD)</th>
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
                                <td class="text-end fw-bold">$ {{ $money($row['pi_amount'] ?? 0) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="10" class="text-center py-5 text-muted">No payment request item found.</td></tr>
                        @endforelse
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="9">Grand Total</td>
                            <td class="text-end">$ {{ $money($summary['total_pi_amount'] ?? 0) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <div class="row g-0 pra-sign-area">
                @foreach($signatureBlocks as $i => $sign)
                    <div class="col-4 {{ $i === 0 ? 'pe-4' : ($i === 2 ? 'ps-4 pra-sign-sep' : 'px-4 pra-sign-sep') }}">
                        <div class="pra-sign-title">{{ $sign['title'] }}</div>
                        @if($sign['src'])
                            <img src="{{ $sign['src'] }}" alt="{{ $sign['title'] }} signature" class="pra-sign-img">
                            <div class="pra-sign-line"></div>
                            <div class="pra-sign-meta">
                                @if($sign['name'])<div class="pra-sign-name">{{ $sign['name'] }}</div>@endif
                                @if($sign['designation'])<div>{{ $sign['designation'] }}</div>@endif
                                <div>Signature &amp; Date</div>
                            </div>
                        @else
                            <div class="pra-sign-text">Signature &amp; Date</div>
                            <div class="pra-sign-line"></div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>

        @if($isPreview)
            {{-- Create PRA + send for approval --}}
            <div class="modal fade" id="createPraModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content" style="border-radius:14px;">
                        <form method="POST" action="{{ route('supply_chain.payment_requests.store') }}">
                            @csrf
                            @foreach($bookingPoIds as $bookingPoId)
                                <input type="hidden" name="booking_po_ids[]" value="{{ $bookingPoId }}">
                            @endforeach
                            <input type="hidden" name="payment_required_date" id="paymentRequiredCreateDate" value="{{ $paymentRequiredInput }}">
                            <div class="modal-header">
                                <h5 class="modal-title"><i class="bi bi-check2-circle text-success me-1"></i> Create Payment Request Approval</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                @if($budgetCheck['exceeded'])
                                    <div class="alert alert-danger small">
                                        <div class="fw-bold mb-1"><i class="bi bi-exclamation-octagon me-1"></i> Style budget exceeded</div>
                                        <div>Over budget for: {{ collect($budgetCheck['lines'])->where('over', true)->pluck('style')->implode(', ') }}.</div>
                                    </div>
                                    @if($canOverrideBudget)
                                        <div class="form-check mb-2">
                                            <input class="form-check-input" type="checkbox" name="budget_override" value="1" id="budgetOverrideChk">
                                            <label class="form-check-label fw-semibold" for="budgetOverrideChk">Override the budget block (authorised)</label>
                                        </div>
                                        <label class="form-label fw-semibold small">Override reason <span class="text-danger">*</span></label>
                                        <textarea name="budget_override_reason" rows="2" class="form-control mb-3" maxlength="1000" placeholder="Why is exceeding the budget justified?">{{ old('budget_override_reason') }}</textarea>
                                    @else
                                        <p class="small text-danger mb-3">You are not authorised to override. Ask an authorised user or update the style budget.</p>
                                    @endif
                                @endif
                                <p class="small text-muted mb-3">Optionally route this PRA for checking and approval. If nothing is selected, the PRA is saved without an approval request.</p>
                                @if(($approverPool ?? collect())->isEmpty())
                                    <div class="alert alert-info small mb-0">No active approvers configured. The PRA will be created without an approval request.</div>
                                @else
                                    @if(($checkerPool ?? collect())->isNotEmpty())
                                        <label class="form-label fw-semibold">Send for check to <span class="text-muted small fw-normal">(optional)</span></label>
                                        <select name="checker_id" class="form-select mb-1">
                                            <option value="">— No checker (send straight to approvers) —</option>
                                            @foreach($checkerPool as $checker)
                                                <option value="{{ $checker->id }}">{{ $checker->name }} ({{ $checker->email }})</option>
                                            @endforeach
                                        </select>
                                        <div class="form-text mb-3">The checker reviews first. Approvers are notified only after the check is complete.</div>
                                    @endif

                                    <label class="form-label fw-semibold">Send for approval to</label>
                                    <div class="d-flex flex-column gap-2" style="max-height:200px;overflow-y:auto;">
                                        @foreach($approverPool as $approver)
                                            <label class="d-flex align-items-center gap-2 border rounded-3 px-3 py-2 mb-0" style="cursor:pointer;">
                                                <input type="checkbox" class="form-check-input mt-0" name="approver_ids[]" value="{{ $approver->id }}">
                                                <span>{{ $approver->name }} <span class="text-muted small">({{ $approver->email }})</span></span>
                                            </label>
                                        @endforeach
                                    </div>
                                    <div class="form-text">All selected approvers must approve before the PRA is marked as Approved.</div>
                                @endif
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" class="btn btn-success" {{ ($budgetCheck['exceeded'] && !$canOverrideBudget) ? 'disabled' : '' }}><i class="bi bi-check2-circle me-1"></i> Create PRA</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        @endif

        @unless($isPreview)
            @if(($approvalProgress['has_flow'] ?? false))
                @php
                    $stateBadge = [
                        'approved' => 'bg-success-subtle text-success',
                        'rejected' => 'bg-danger-subtle text-danger',
                        'pending_approval' => 'bg-warning-subtle text-warning-emphasis',
                        'pending_check' => 'bg-info-subtle text-info-emphasis',
                    ];
                    $decisionBadge = [
                        'approved' => 'bg-success-subtle text-success',
                        'rejected' => 'bg-danger-subtle text-danger',
                        'pending' => 'bg-warning-subtle text-warning-emphasis',
                    ];
                    $isCreator = auth()->id() === $paymentRequest->created_by;
                @endphp
                <div class="mx-auto mt-3" style="max-width:1120px;">
                    <div class="card border-0 shadow-sm rounded-3">
                        <div class="card-body p-4">
                            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                                <h6 class="fw-bold mb-0"><i class="bi bi-shield-check me-1"></i> Approval Status</h6>
                                <span class="badge rounded-pill {{ $stateBadge[$approvalProgress['state']] ?? 'bg-secondary-subtle text-secondary' }}">{{ $approvalProgress['label'] }}</span>
                            </div>
                            <div class="row g-2">
                                @foreach($currentApprovals as $approval)
                                    <div class="col-12 col-md-6 col-xl-4">
                                        <div class="d-flex justify-content-between align-items-start gap-2 border rounded-3 px-3 py-2">
                                            <div class="min-w-0">
                                                <div class="fw-semibold small text-slate-900">
                                                    {{ optional($approval->approver)->name ?? '—' }}
                                                    <span class="badge bg-light text-muted border ms-1 fw-normal">{{ $approval->stage === \App\Models\PraApproval::STAGE_CHECK ? 'Checker' : 'Approver' }}</span>
                                                </div>
                                                @if($approval->comment)<div class="small text-muted">{{ $approval->comment }}</div>@endif
                                            </div>
                                            <span class="badge rounded-pill {{ $decisionBadge[$approval->status] ?? 'bg-secondary-subtle text-secondary' }}">{{ ucfirst($approval->status) }}</span>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                            @if($approvalProgress['state'] === 'rejected' && $isCreator)
                                <div class="mt-3">
                                    <a href="{{ route('supply_chain.payment_requests.my_status') }}" class="btn btn-sm btn-primary rounded-pill px-3">
                                        <i class="bi bi-arrow-repeat me-1"></i> Resubmit from My PRA Status
                                    </a>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            @endif

            <div class="mx-auto mt-3" style="max-width:1120px;">
                @if($emailLogs->isEmpty())
                    <div class="card border-0 shadow-sm rounded-3">
                        <div class="card-body p-4">
                            <h6 class="fw-bold mb-2"><i class="bi bi-clock-history me-1"></i> Sent Emails</h6>
                            <p class="text-muted small mb-0">No emails have been sent for this PRA yet.</p>
                        </div>
                    </div>
                @else
                    @include('partials.email-history', [
                        'emailLogs' => $emailLogs,
                        'composeModalId' => 'sendEmailModal',
                        'composeEditorId' => 'emailBodyEditor',
                        'composeInputId' => 'emailBodyInput',
                        'attachmentName' => 'PAYMENT_REQUEST_APPROVAL_' . $paymentRequest->request_no . '.pdf',
                    ])
                @endif
            </div>
        @endunless
    </div>
</div>

@unless($isPreview)
<div class="modal fade" id="sendEmailModal" tabindex="-1" aria-labelledby="sendEmailModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content" style="border-radius:14px;overflow:hidden;">
            <form method="POST" action="{{ route('supply_chain.payment_requests.email', $paymentRequest) }}"
                  style="display:flex;flex-direction:column;min-height:0;overflow:hidden;">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title" id="sendEmailModalLabel"><i class="bi bi-envelope me-1"></i> Send Payment Request Approval</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" style="flex:1 1 auto;overflow-y:auto;min-height:0;">
                    <div class="alert alert-info border-0 small py-2">
                        <i class="bi bi-paperclip me-1"></i> The official PRA PDF (<strong>{{ $paymentRequest->request_no }}</strong>) will be attached automatically.
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">From</label>
                        <input type="email" name="from" class="form-control" maxlength="255"
                               value="{{ old('from', $emailDefaults['from']) }}"
                               placeholder="your@email.com">
                        <div class="form-text">Used as the Reply-To address. Pre-filled with your email; you can change it.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">To <span class="text-danger">*</span></label>
                        <input type="text" name="to" class="form-control" required value="{{ old('to', $emailDefaults['to']) }}"
                               placeholder="name@example.com, another@example.com">
                        <div class="form-text">Separate multiple recipients with commas.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Cc</label>
                        <input type="text" name="cc" class="form-control" value="{{ old('cc', $emailDefaults['cc']) }}"
                               placeholder="optional, comma separated">
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Subject <span class="text-danger">*</span></label>
                        <input type="text" name="subject" class="form-control" maxlength="255" required
                               value="{{ old('subject', $emailDefaults['subject']) }}">
                    </div>

                    <div class="mb-1">
                        <label class="form-label fw-semibold">Message <span class="text-danger">*</span></label>
                        <div class="btn-toolbar mb-2" role="toolbar" aria-label="Formatting">
                            <div class="btn-group btn-group-sm" role="group">
                                <button type="button" class="btn btn-light border" data-rt-cmd="bold" title="Bold"><i class="bi bi-type-bold"></i></button>
                                <button type="button" class="btn btn-light border" data-rt-cmd="italic" title="Italic"><i class="bi bi-type-italic"></i></button>
                                <button type="button" class="btn btn-light border" data-rt-cmd="underline" title="Underline"><i class="bi bi-type-underline"></i></button>
                                <button type="button" class="btn btn-light border" data-rt-cmd="insertUnorderedList" title="Bullet list"><i class="bi bi-list-ul"></i></button>
                            </div>
                        </div>
                        <div id="emailBodyEditor" class="form-control" contenteditable="true"
                             style="height:220px;max-height:40vh;overflow-y:auto;font-size:14px;line-height:1.6;">{!! old('body', $emailDefaults['body']) !!}</div>
                        <textarea name="body" id="emailBodyInput" class="d-none" required>{{ old('body', $emailDefaults['body']) }}</textarea>
                        <div class="form-text">Pre-filled from the admin template. Edit the text directly — formatting is kept in the email.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-send me-1"></i> Send</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endunless
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

@unless($isPreview)
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const editor = document.getElementById('emailBodyEditor');
        const input = document.getElementById('emailBodyInput');

        if (editor && input) {
            const sync = function () { input.value = editor.innerHTML.trim(); };
            editor.addEventListener('input', sync);
            sync();

            document.querySelectorAll('[data-rt-cmd]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    editor.focus();
                    document.execCommand(btn.dataset.rtCmd, false, null);
                    sync();
                });
            });

            const form = input.closest('form');
            if (form) {
                form.addEventListener('submit', sync);

                // Enter inside single-line fields (From/To/Cc/Subject) must not
                // submit the form — only an explicit Send click submits. The
                // Message editor is contenteditable, so Enter keeps inserting
                // newlines there as normal.
                form.querySelectorAll('input[type="email"], input[type="text"]').forEach(function (field) {
                    field.addEventListener('keydown', function (e) {
                        if (e.key === 'Enter') {
                            e.preventDefault();
                        }
                    });
                });
            }
        }
    });
</script>
@endunless

@if(! $isPreview && $errors->any() && old('to') !== null)
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const modalEl = document.getElementById('sendEmailModal');
        if (modalEl && window.bootstrap) {
            bootstrap.Modal.getOrCreateInstance(modalEl).show();
        }
    });
</script>
@endif
@endsection
