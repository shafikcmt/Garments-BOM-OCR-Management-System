@php
    $items = $bookingData['items'] ?? [];
    $notes = $bookingData['notes'] ?? [];
    $qtyNumber = function ($value) { return (float) str_replace([',', ' '], '', (string) ($value ?? 0)); };
    $lineTotalQty = function ($item) use ($qtyNumber) { return $qtyNumber($item['booking_qty'] ?? 0) + $qtyNumber($item['pp_qty'] ?? 0); };
    $totalQty = collect($items)->sum(fn ($item) => $lineTotalQty($item));
    $previewMode = $previewMode ?? false;
    $regenerateMode = $regenerateMode ?? false;
    $adminEditMode = $adminEditMode ?? false;
    $batchMode = $batchMode ?? false;
    $batchRowIds = $batchRowIds ?? [];
    $selectedOrderCount = $selectedOrderCount ?? null;
    $editPanelOpen = $editPanelOpen ?? false;
    $generateUrl = $generateUrl ?? null;
    $poNo = $bookingData['po_number'] ?? ($bookingPo->po_no ?? '');
    $revisionNo = max(0, (int) ($bookingData['revision_no'] ?? 0));
    $revisionLabel = $revisionNo > 0 ? 'R-' . $revisionNo : '';
    $instructionOptions = $instructionOptions ?? collect();
    $deliveryDestinationOptions = $deliveryDestinationOptions ?? collect();
    $bookingRoutePrefix = $bookingRoutePrefix ?? 'supply_chain.bookings';
    $canControlPo = $canControlPo ?? (auth()->user()?->hasRole('admin') ?? false);
    $canEditThisPreview = $previewMode && $generateUrl && (! $regenerateMode || $canControlPo);
    $deliveryDestinationName = trim((string) ($bookingData['delivery_destination_name'] ?? ''));
    $deliveryDestinationDetails = trim((string) ($bookingData['delivery_destination_details'] ?? ''));
    $hasDeliveryDestination = $deliveryDestinationName !== '' || $deliveryDestinationDetails !== '';
    $incotermOptions = ['FOB', 'CIF', 'CFR', 'Ex-Works'];
    $shipModeOptions = ['Sea', 'Air', 'Courier', 'Truck'];
    $selectedIncoterm = trim((string) ($bookingData['incoterm'] ?? ''));
    $selectedShipMode = trim((string) ($bookingData['ship_mode'] ?? ''));
    $previewFormUid = uniqid('bookingPreviewEdit');
    $sourceChanges = collect($bookingData['source_change_log'] ?? []);
    $generationHistory = collect($bookingData['generation_history'] ?? [])->reverse()->values();
@endphp

<style>
    .booking-format-preview-box {
        --hapl-blue: #006C9D;
        --hapl-blue-dark: #004b70;
        --hapl-border: #94a3b8;
        background: linear-gradient(180deg, #eef6fb 0%, #ffffff 100%);
        padding: 8px;
        border-radius: 12px;
        overflow: visible;
    }
    .booking-format-preview-box .bf-sheet {
        width: 198mm;
        zoom: var(--booking-preview-zoom, 1);
        max-width: 100%;
        min-height: 285mm;
        margin: 0 auto;
        background: #ffffff;
        border: 1px solid #d1d5db;
        box-shadow: 0 20px 55px rgba(15, 23, 42, .16);
        color: #111827;
        font-family: Arial, Helvetica, sans-serif;
        font-size: 7.2px;
        line-height: 1.2;
        overflow: hidden;
    }
    .booking-format-preview-box .bf-pad { padding: 0; }
    .booking-format-preview-box table { width: 100%; border-collapse: collapse; table-layout: fixed; }
    .booking-format-preview-box .bf-top-table { margin-bottom: 3mm; border-bottom: 1.4px solid var(--hapl-blue); }
    .booking-format-preview-box .bf-top-table td { border: 0; padding: 2.6mm 1.5mm 3mm; vertical-align: middle; }
    .booking-format-preview-box .bf-logo { width: 34mm; height: auto; display: block; }
    .booking-format-preview-box .bf-company-title {
        margin: 0;
        color: var(--hapl-blue-dark);
        font-size: 13.5px;
        line-height: 1.05;
        letter-spacing: 1.1px;
        font-weight: 900;
        text-transform: uppercase;
    }
    .booking-format-preview-box .bf-company-small { margin: 1.2px 0 0; color: #0f172a; font-size: 6.5px; line-height: 1.18; }
    .booking-format-preview-box .bf-badge {
        border: 1.5px solid var(--hapl-blue);
        border-radius: 7px;
        color: var(--hapl-blue);
        font-size: 10.5px;
        letter-spacing: .6px;
        font-weight: 900;
        text-align: center;
        padding: 4mm 2mm;
        text-transform: uppercase;
    }
    .booking-format-preview-box .bf-sub-title {
        margin: 0 0 1.5mm;
        color: #0f172a;
        font-size: 7px;
        font-weight: 800;
        text-align: center;
    }
    .booking-format-preview-box .bf-info-table { border: 1px solid var(--hapl-border); margin-bottom: 2mm; }
    .booking-format-preview-box .bf-info-table th,
    .booking-format-preview-box .bf-info-table td {
        border: 1px solid #d4dce7;
        padding: 1mm 1.1mm;
        height: 4.8mm;
        vertical-align: middle;
        overflow-wrap: anywhere;
    }
    .booking-format-preview-box .bf-info-table th {
        width: 20mm;
        background: #e0f2fe;
        color: var(--hapl-blue-dark);
        font-size: 7px;
        font-weight: 900;
        text-align: left;
        text-transform: uppercase;
    }
    .booking-format-preview-box .bf-info-table td { font-size: 7px; }
    .booking-format-preview-box .bf-style-value { font-size: 8px; font-weight: 900; color: var(--hapl-blue-dark); letter-spacing: .2px; }
    .booking-format-preview-box .bf-revision-pill {
        display: inline-block;
        margin-left: 2mm;
        padding: .7mm 1.7mm;
        border-radius: 999px;
        background: #fff7ed;
        border: 1px solid #fdba74;
        color: #9a3412;
        font-weight: 900;
        font-size: 5.8px;
        line-height: 1;
        white-space: nowrap;
        text-transform: uppercase;
    }
    .booking-format-preview-box .bf-line-style { font-weight: 900; color: var(--hapl-blue-dark); }
    .booking-format-preview-box .bf-consignee-table { border: 1px solid var(--hapl-border); margin-bottom: 2mm; }
    .booking-format-preview-box .bf-consignee-table th,
    .booking-format-preview-box .bf-consignee-table td { border: 1px solid var(--hapl-border); padding: 1.4mm; vertical-align: middle; }
    .booking-format-preview-box .bf-consignee-table th {
        width: 32mm;
        background: var(--hapl-blue);
        color: #fff;
        font-size: 7px;
        line-height: 1.15;
        text-align: left;
    }
    .booking-format-preview-box .bf-consignee-table td { white-space: pre-line; font-size: 7px; font-weight: 700; }
    .booking-format-preview-box .bf-table { table-layout: fixed; width: 100%; max-width: 100%; }
    .booking-format-preview-box .bf-table th,
    .booking-format-preview-box .bf-table td {
        box-sizing: border-box;
        border: 1px solid #8aa2b8;
        padding: .75mm .45mm;
        vertical-align: top;
        overflow-wrap: anywhere;
        word-break: break-word;
    }
    .booking-format-preview-box .bf-table th {
        background: var(--hapl-blue);
        color: #fff;
        border-color: var(--hapl-blue-dark);
        font-size: 5.7px;
        line-height: 1.08;
        font-weight: 900;
        text-align: center;
    }
    .booking-format-preview-box .bf-table td { font-size: 5.8px; line-height: 1.12; }
    .booking-format-preview-box .text-right { text-align: right; }
    .booking-format-preview-box .text-center { text-align: center; }
    .booking-format-preview-box .bf-grand-row td { background: #e0f2fe; color: #003f5f; font-weight: 900; }
    .booking-format-preview-box .bf-notes-box { border: 1px solid var(--hapl-border); margin-top: 2mm; }
    .booking-format-preview-box .bf-notes-box h3 {
        margin: 0;
        padding: 1.2mm 1.6mm;
        background: var(--hapl-blue);
        color: #fff;
        font-size: 8px;
        line-height: 1;
    }
    .booking-format-preview-box .bf-notes-box ol { margin: 1.5mm 3.5mm 2mm 5.5mm; padding: 0; }
    .booking-format-preview-box .bf-notes-box li { margin: 0 0 .7mm; font-size: 6.8px; }
    .booking-format-preview-box .bf-sign-table { width: 100%; margin-top: 8mm; table-layout: fixed; }
    .booking-format-preview-box .bf-sign-table td { border: 0; padding: 0 6mm; vertical-align: bottom; text-align: center; }
    .booking-format-preview-box .bf-sign-box { width: 100%; font-size: 7.2px; color: #111827; font-weight: 900; }
    .booking-format-preview-box .bf-sign-line { width: 46mm; margin: 9mm auto 0; border-top: 1px solid #111827; height: 1mm; }
    .booking-format-preview-box .bf-footer-note { margin-top: 2mm; font-size: 5.7px; color: #64748b; text-align: center; }
    .booking-format-preview-box .bf-action-bar {
        display: flex;
        justify-content: flex-end;
        gap: 8px;
        flex-wrap: wrap;
        margin-bottom: 12px;
    }
    .booking-format-preview-box .bf-batch-banner {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        flex-wrap: wrap;
        margin-bottom: 12px;
        padding: 10px 14px;
        border-radius: 12px;
        border: 1px solid #c7d2fe;
        background: linear-gradient(135deg, #eef2ff 0%, #faf5ff 100%);
        box-shadow: 0 8px 20px rgba(67, 56, 202, .08);
    }
    .booking-format-preview-box .bf-batch-count {
        display: inline-flex;
        align-items: center;
        font-size: 13px;
        font-weight: 900;
        color: #312e81;
    }
    .booking-format-preview-box .bf-batch-note {
        display: inline-flex;
        align-items: center;
        font-size: 12.5px;
        font-weight: 700;
        color: #4338ca;
    }
    .booking-format-preview-box .bf-action-footer {
        position: sticky;
        bottom: 0;
        z-index: 6;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        flex-wrap: wrap;
        margin: 14px -8px -8px;
        padding: 12px 16px;
        background: rgba(255, 255, 255, .97);
        backdrop-filter: blur(8px);
        border-top: 1px solid #d7e1ec;
        border-radius: 0 0 12px 12px;
        box-shadow: 0 -12px 26px rgba(15, 23, 42, .09);
    }
    .booking-format-preview-box .bf-action-footer-help {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        color: #475569;
        font-size: 12.5px;
        font-weight: 600;
        line-height: 1.3;
    }
    .booking-format-preview-box .bf-action-footer-help i { color: var(--hapl-blue); font-size: 15px; flex: 0 0 auto; }
    .booking-format-preview-box .bf-action-footer-buttons { display: inline-flex; align-items: center; gap: 10px; flex-wrap: wrap; }
    .booking-format-preview-box .bf-btn-cancel {
        border: 1px solid #d6deea;
        background: #ffffff;
        color: #475569;
        font-weight: 700;
        border-radius: 11px;
        padding: 9px 18px;
        display: inline-flex;
        align-items: center;
    }
    .booking-format-preview-box .bf-btn-cancel:hover { background: #f1f5f9; color: #1e293b; border-color: #c3cfde; }
    .booking-format-preview-box .bf-btn-cancel:focus-visible { outline: 3px solid rgba(100, 116, 139, .3); outline-offset: 2px; }
    .booking-format-preview-box .bf-btn-generate {
        border: 0;
        border-radius: 11px;
        padding: 9px 22px;
        font-weight: 800;
        color: #ffffff;
        background: linear-gradient(135deg, #4f46e5, #312e81);
        box-shadow: 0 12px 24px rgba(79, 70, 229, .26);
        display: inline-flex;
        align-items: center;
        transition: transform .15s ease, filter .15s ease, box-shadow .15s ease;
    }
    .booking-format-preview-box .bf-btn-generate:hover { color: #fff; filter: brightness(1.06); transform: translateY(-1px); }
    .booking-format-preview-box .bf-btn-generate:focus-visible { outline: 3px solid rgba(79, 70, 229, .4); outline-offset: 2px; }
    .booking-format-preview-box .bf-btn-generate:disabled { opacity: .8; cursor: progress; transform: none; box-shadow: none; filter: none; }
    @media (max-width: 575px) {
        .booking-format-preview-box .bf-action-footer { flex-direction: column; align-items: stretch; text-align: center; }
        .booking-format-preview-box .bf-action-footer-help { justify-content: center; }
        .booking-format-preview-box .bf-action-footer-buttons { justify-content: stretch; }
        .booking-format-preview-box .bf-action-footer-buttons .btn { flex: 1; justify-content: center; }
    }
    .booking-format-preview-box .booking-preview-edit-panel {
        width: 198mm;
        max-width: 100%;
        margin: 0 auto 12px;
        border: 1px solid #cbd5e1;
        border-radius: 14px;
        background: #ffffff;
        padding: 14px;
        box-shadow: 0 14px 32px rgba(15, 23, 42, .08);
    }
    .booking-format-preview-box .booking-preview-edit-panel label {
        font-size: 11px;
        font-weight: 800;
        color: #334155;
        text-transform: uppercase;
        letter-spacing: .04em;
    }
    .booking-format-preview-box .booking-preview-edit-panel .form-control,
    .booking-format-preview-box .booking-preview-edit-panel .form-select { border-radius: 10px; font-size: 12px; }
    .booking-format-preview-box .booking-preview-edit-panel .form-check-label { text-transform: none; letter-spacing: 0; font-size: 12px; }
    .booking-format-preview-box .booking-preview-note-row { display: flex; gap: 8px; align-items: flex-start; margin-bottom: 8px; }
    .booking-format-preview-box .booking-preview-note-row textarea { flex: 1; }

    .booking-format-preview-box .booking-change-control-panel {
        width: 198mm;
        max-width: 100%;
        margin: 0 auto 12px;
        border: 1px solid #fecaca;
        border-radius: 14px;
        background: linear-gradient(135deg, #fff7f7 0%, #ffffff 100%);
        padding: 12px;
        box-shadow: 0 14px 30px rgba(185, 28, 28, .08);
    }
    .booking-format-preview-box .booking-change-control-panel h6 { font-size: 13px; font-weight: 900; color: #991b1b; }
    .booking-format-preview-box .booking-change-table { margin-bottom: 0; }
    .booking-format-preview-box .booking-change-table th { font-size: 10px; color: #7f1d1d; text-transform: uppercase; letter-spacing: .05em; }
    .booking-format-preview-box .booking-change-table td { font-size: 11px; color: #334155; vertical-align: top; }
    .booking-format-preview-box .booking-history-mini { width: 198mm; max-width:100%; margin: 0 auto 12px; border: 1px solid #e2e8f0; border-radius: 14px; background:#fff; padding: 10px 12px; }
    .booking-format-preview-box .booking-history-mini .history-line { color:#64748b; font-size:11px; }
    @media (max-width: 767px) {
        .booking-format-preview-box { padding: 6px; }
        .booking-format-preview-box .bf-sheet { max-width: none; }
    }
</style>

<div class="booking-format-preview-box">
@if($canEditThisPreview)
    <form class="booking-preview-edit-form">
@endif
    @if($batchMode)
        <div class="bf-batch-banner no-print" role="status">
            <span class="bf-batch-count"><i class="bi bi-collection me-1"></i>Selected Orders: {{ $selectedOrderCount ?? count($batchRowIds) }}</span>
            <span class="bf-batch-note"><i class="bi bi-info-circle me-1"></i>One PO number will be generated for all selected orders.</span>
        </div>
    @endif
    <div class="bf-action-bar no-print">
        @if($canEditThisPreview)
            <button type="button" class="btn btn-outline-primary btn-sm booking-preview-edit-toggle">
                @if($editPanelOpen)
                    <i class="bi bi-eye me-1"></i>Hide Edit
                @else
                    <i class="bi bi-pencil-square me-1"></i>Edit Preview
                @endif
            </button>
        @elseif(! $previewMode && isset($bookingPo) && $bookingPo?->exists)
            <a href="{{ route($bookingRoutePrefix . '.print', $bookingPo) }}" target="_blank" class="btn btn-outline-secondary btn-sm"><i class="bi bi-printer me-1"></i>Print</a>
            <a href="{{ route($bookingRoutePrefix . '.download', $bookingPo) }}" target="_blank" class="btn btn-outline-success btn-sm"><i class="bi bi-filetype-pdf me-1"></i>PDF</a>
            <a href="{{ route($bookingRoutePrefix . '.download_excel', $bookingPo) }}" target="_blank" class="btn btn-outline-success btn-sm"><i class="bi bi-file-earmark-excel me-1"></i>Excel</a>
        @endif
    </div>

    @if($canControlPo && $sourceChanges->isNotEmpty())
        <div class="booking-change-control-panel no-print">
            <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap mb-2">
                <div>
                    <h6 class="mb-1"><i class="bi bi-exclamation-triangle me-1"></i>Source data changed after PO generation</h6>
                    <div class="small text-muted">Check before/after values, then re-generate PO if the latest data should update this booking.</div>
                </div>
                <span class="badge rounded-pill text-bg-danger">{{ $sourceChanges->count() }} field(s)</span>
            </div>
            <div class="table-responsive">
                <table class="table table-sm booking-change-table">
                    <thead><tr><th>Field</th><th>Before</th><th>After</th></tr></thead>
                    <tbody>
                        @foreach($sourceChanges->take(10) as $change)
                            <tr>
                                <td class="fw-bold">{{ $change['label'] ?? '-' }}</td>
                                <td>{{ ($change['before'] ?? '') !== '' ? $change['before'] : 'Blank' }}</td>
                                <td>{{ ($change['after'] ?? '') !== '' ? $change['after'] : 'Blank' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    @if($canControlPo && ! $previewMode && $generationHistory->isNotEmpty())
        <div class="booking-history-mini no-print">
            <div class="fw-bold text-slate-900"><i class="bi bi-clock-history me-1 text-primary"></i>PO control history</div>
            @foreach($generationHistory->take(3) as $entry)
                <div class="history-line">{{ ucfirst(str_replace('_', ' ', $entry['action'] ?? 'generated')) }} @if(($entry['revision_no'] ?? 0) > 0) · R-{{ $entry['revision_no'] }}@endif · {{ $entry['changed_by_name'] ?? 'System' }} · {{ $entry['changed_at'] ?? '-' }} @if(! empty($entry['changes'])) · {{ count($entry['changes']) }} edited field(s) @endif</div>
            @endforeach
        </div>
    @endif

    @if($canEditThisPreview)
        <div class="booking-preview-edit-panel no-print {{ $editPanelOpen ? '' : 'd-none' }}">
            <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap mb-3">
                <div>
                    <h6 class="mb-1 fw-bold"><i class="bi bi-pencil-square me-1 text-primary"></i>{{ $adminEditMode ? 'Edit PO Control Data' : ($regenerateMode ? 'Edit Before PO Re-generate' : 'Edit Vendor Details & Instructions') }}</h6>
                    <div class="small text-muted">
                        @if($adminEditMode)
                            Admin edit mode: update PO data, delivery details, item values, and instructions without changing the PO number.
                        @elseif($regenerateMode)
                            Review latest supply-chain data first. Change anything needed, then click Re-generate PO to confirm. The PO number will remain unchanged.
                        @else
                            Edited vendor name, contact, email, address, incoterm and ship mode will update the vendor database after PO generation.
                        @endif
                    </div>
                </div>
            </div>

            <input type="hidden" name="booking[supplier]" value="{{ $bookingData['supplier'] ?? '' }}">

            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">TO / Vendor Name</label>
                    <input type="text" name="booking[to]" value="{{ $bookingData['to'] ?? '' }}" class="form-control">
                </div>
                <div class="col-md-3">
                    <label class="form-label">ATTN</label>
                    <input type="text" name="booking[attn]" value="{{ $bookingData['attn'] ?? '' }}" class="form-control">
                </div>
                <div class="col-md-3">
                    <label class="form-label">E-MAIL</label>
                    <input type="email" name="booking[email]" value="{{ $bookingData['email'] ?? '' }}" class="form-control">
                </div>
                <div class="col-12">
                    <label class="form-label">Supplier Address</label>
                    <textarea name="booking[address]" rows="2" class="form-control" placeholder="Supplier address">{{ $bookingData['address'] ?? '' }}</textarea>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Date</label>
                    <input type="text" name="booking[date]" value="{{ $bookingData['date'] ?? '' }}" class="form-control">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Buyer</label>
                    <input type="text" name="booking[buyer]" value="{{ $bookingData['buyer'] ?? '' }}" class="form-control">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Season</label>
                    <input type="text" name="booking[season]" value="{{ $bookingData['season'] ?? '' }}" class="form-control">
                </div>
                <div class="col-md-3">
                    <label class="form-label">From</label>
                    <input type="text" name="booking[from]" value="{{ $bookingData['from'] ?? '' }}" class="form-control">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Item Type</label>
                    <input type="text" name="booking[item_type]" value="{{ $bookingData['item_type'] ?? '' }}" class="form-control">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Incoterm</label>
                    <select name="booking[incoterm]" class="form-select">
                        <option value="">Select incoterm if required</option>
                        @foreach($incotermOptions as $incotermOption)
                            <option value="{{ $incotermOption }}" @selected($selectedIncoterm === $incotermOption)>{{ $incotermOption }}</option>
                        @endforeach
                        @if($selectedIncoterm !== '' && ! in_array($selectedIncoterm, $incotermOptions, true))
                            <option value="{{ $selectedIncoterm }}" selected>{{ $selectedIncoterm }}</option>
                        @endif
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Ship Mode</label>
                    <select name="booking[ship_mode]" class="form-select">
                        <option value="">Select ship mode if required</option>
                        @foreach($shipModeOptions as $shipModeOption)
                            <option value="{{ $shipModeOption }}" @selected($selectedShipMode === $shipModeOption)>{{ $shipModeOption }}</option>
                        @endforeach
                        @if($selectedShipMode !== '' && ! in_array($selectedShipMode, $shipModeOptions, true))
                            <option value="{{ $selectedShipMode }}" selected>{{ $selectedShipMode }}</option>
                        @endif
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Style No</label>
                    <input type="text" name="booking[order_style_no]" value="{{ $bookingData['order_style_no'] ?? '' }}" class="form-control fw-bold">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Tolerance %</label>
                    <input type="text" name="booking[tolerance]" value="{{ $bookingData['tolerance'] ?? '' }}" class="form-control">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Consignee / Bill To</label>
                    <textarea name="booking[consignee]" rows="4" class="form-control">{{ $bookingData['consignee'] ?? '' }}</textarea>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Delivery / Ship To Dropdown</label>
                    <select name="booking[delivery_destination_id]" class="form-select booking-delivery-destination-select">
                        <option value="">Not applicable / use consignee only</option>
                        @foreach($deliveryDestinationOptions as $destination)
                            <option value="{{ $destination->id }}"
                                    data-title="{{ $destination->title }}"
                                    data-details="{{ $destination->details }}"
                                    @selected((string)($bookingData['delivery_destination_id'] ?? '') === (string)$destination->id)>
                                {{ $destination->title }}
                            </option>
                        @endforeach
                    </select>
                    <input type="hidden" name="booking[delivery_destination_name]" value="{{ $deliveryDestinationName }}" class="booking-delivery-destination-name">
                    <textarea name="booking[delivery_destination_details]" rows="4" class="form-control mt-2 booking-delivery-destination-details" placeholder="Select or write delivery / ship to details here.">{{ $deliveryDestinationDetails }}</textarea>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Best Regards Name</label>
                    <input type="text" name="booking[best_regards]" value="{{ $bookingData['best_regards'] ?? '' }}" class="form-control">
                </div>
            </div>

            <hr class="my-4">

            <div class="row g-3">
                <div class="col-md-5">
                    <label class="form-label">Add Extra Suggested Instructions</label>
                    <select name="common_instruction_ids[]" class="form-select" multiple size="7">
                        @foreach($instructionOptions as $instruction)
                            <option value="{{ $instruction->id }}">{{ $instruction->instruction }}</option>
                        @endforeach
                    </select>
                    <div class="form-text">Only admin active suggestion instructions show here. Hold Ctrl/Cmd to select multiple; selected suggestions will be added to this booking.</div>
                </div>
                <div class="col-md-7">
                    <label class="form-label">Current Instructions</label>
                    <div class="booking-preview-notes-list">
                        @forelse($notes as $note)
                            <div class="booking-preview-note-row">
                                <textarea name="notes[]" rows="2" class="form-control" placeholder="Instruction text">{{ $note }}</textarea>
                                <button type="button" class="btn btn-outline-danger btn-sm booking-preview-remove-note" title="Remove"><i class="bi bi-x-lg"></i></button>
                            </div>
                        @empty
                            <div class="booking-preview-note-row">
                                <textarea name="notes[]" rows="2" class="form-control" placeholder="Instruction text"></textarea>
                                <button type="button" class="btn btn-outline-danger btn-sm booking-preview-remove-note" title="Remove"><i class="bi bi-x-lg"></i></button>
                            </div>
                        @endforelse
                    </div>
                    <button type="button" class="btn btn-outline-primary btn-sm booking-preview-add-note"><i class="bi bi-plus-lg me-1"></i>Add Instruction Line</button>
                </div>
                <div class="col-12">
                    <label class="form-label">New Instruction</label>
                    <textarea name="new_instruction" rows="2" class="form-control" placeholder="Write a new instruction to add with this booking"></textarea>
                    <div class="form-check mt-2">
                        <input class="form-check-input" type="checkbox" value="1" name="save_new_instruction" id="saveNewInstruction{{ $previewFormUid }}">
                        <label class="form-check-label" for="saveNewInstruction{{ $previewFormUid }}">Save this new instruction as an extra suggestion for future bookings</label>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <div class="bf-sheet">
        <div class="bf-pad">
            <table class="bf-top-table">
                <tr>
                    <td style="width:38mm;"><img class="bf-logo" src="{{ asset('images/humana-logo.png') }}" alt="Humana Apparels Logo"></td>
                    <td>
                        <h1 class="bf-company-title">HUMANA APPARELS PVT. LTD.</h1>
                        <p class="bf-company-small">Bill &amp; Ship To - Humana Apparels Private Limited</p>
                        <p class="bf-company-small">Momin Nagar, Gorai, Mirzapur, Tangail - 1941, Bangladesh</p>
                    </td>
                    <td style="width:44mm;"><div class="bf-badge">PO/WO/ Booking</div></td>
                </tr>
            </table>

            <table class="bf-info-table">
                <tr><th>TO</th><td>{{ $bookingData['to'] ?? '' }}</td><th>PO/WO/ NUMBER</th><td>{{ $poNo }} @if($revisionLabel)<span class="bf-revision-pill">{{ $revisionLabel }}</span>@endif</td></tr>
                <tr><th>ATTN</th><td>{{ $bookingData['attn'] ?? '' }}</td><th>DATE</th><td>{{ $bookingData['date'] ?? '' }}</td></tr>
                <tr><th>E-MAIL</th><td>{{ $bookingData['email'] ?? '' }}</td><th>BUYER</th><td>{{ $bookingData['buyer'] ?? '' }}</td></tr>
                <tr><th>ADDRESS</th><td>{{ $bookingData['address'] ?? '' }}</td><th>SEASON</th><td>{{ $bookingData['season'] ?? '' }}</td></tr>
                <tr><th>FROM</th><td>{{ $bookingData['from'] ?? '' }}</td><th>STYLE NO</th><td class="bf-style-value">{{ $bookingData['order_style_no'] ?? '' }}</td></tr>
                <tr><th>INCOTERM</th><td>{{ $bookingData['incoterm'] ?? '' }}</td><th>ITEM TYPE</th><td>{{ $bookingData['item_type'] ?? '' }}</td></tr>
                <tr><th>SHIP MODE</th><td>{{ $bookingData['ship_mode'] ?? '' }}</td><th>TOLERANCE %</th><td>{{ $bookingData['tolerance'] ?? '' }}</td></tr>
            </table>

            <table class="bf-consignee-table">
                <tr>
                    <th>Consignee / Bill To</th>
                    @if($hasDeliveryDestination)
                        <td>{{ $bookingData['consignee'] ?? '' }}</td>
                        <th>Delivery / Ship To</th>
                        <td>@if($deliveryDestinationName)<strong>{{ $deliveryDestinationName }}</strong>
@endif{{ $deliveryDestinationDetails }}</td>
                    @else
                        <td colspan="3">{{ $bookingData['consignee'] ?? '' }}</td>
                    @endif
                </tr>
            </table>

            <table class="bf-table">
                <thead>
                    <tr>
                        <th style="width:5mm;">SL</th>
                        <th style="width:19mm;">Style No</th>
                        <th style="width:17mm;">Item</th>
                        <th style="width:38mm;">Description</th>
                        <th style="width:12mm;">Color</th>
                        <th style="width:9mm;">Size</th>
                        <th style="width:10mm;">Width</th>
                        <th style="width:19mm;">Supplier / Article</th>
                        <th style="width:13mm;">Booking Qty</th>
                        <th style="width:9mm;">PP Qty</th>
                        <th style="width:12mm;">Total Qty</th>
                        <th style="width:7mm;">UOM</th>
                        <th style="width:20mm;">Remarks</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($items as $index => $item)
                        <tr>
                            <td class="text-center">{{ $index + 1 }}</td>
                            <td class="bf-line-style">{{ $item['style_order'] ?? '' }}</td>
                            <td>{{ $item['item_name'] ?? '' }}</td>
                            <td>{{ $item['description'] ?? '' }}</td>
                            <td>{{ $item['color'] ?? '' }}</td>
                            <td>{{ $item['size'] ?? 'N/A' }}</td>
                            <td>{{ $item['width'] ?? 'N/A' }}</td>
                            <td>{{ $item['supplier_article'] ?? '' }}</td>
                            <td class="text-right">{{ $item['booking_qty'] ?? '' }}</td>
                            <td class="text-right">{{ $item['pp_qty'] ?? '' }}</td>
                            <td class="text-right">{{ number_format($lineTotalQty($item), 2) }}</td>
                            <td class="text-center">{{ $item['uom'] ?? '' }}</td>
                            <td>{{ $item['remarks'] ?? '' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td class="text-center" colspan="13">No item found.</td>
                        </tr>
                    @endforelse
                    <tr class="bf-grand-row">
                        <td colspan="10" class="text-right">Grand Total</td>
                        <td class="text-right">{{ $totalQty ? number_format($totalQty, 2) : '' }}</td>
                        <td colspan="2"></td>
                    </tr>
                </tbody>
            </table>

            <div class="bf-notes-box">
                <h3>Notes / Instructions</h3>
                <ol>
                    @foreach($notes as $note)
                        @if(trim((string) $note) !== '')
                            <li>{{ $note }}</li>
                        @endif
                    @endforeach
                </ol>
            </div>

            <table class="bf-sign-table">
                <tr>
                    <td>
                        <div class="bf-sign-box">
                            <div>Prepared By</div>
                            <div class="bf-sign-line"></div>
                        </div>
                    </td>
                    <td>
                        <div class="bf-sign-box">
                            <div>Checked By</div>
                            <div class="bf-sign-line"></div>
                        </div>
                    </td>
                    <td>
                        <div class="bf-sign-box">
                            <div>Approved By</div>
                            <div class="bf-sign-line"></div>
                        </div>
                    </td>
                </tr>
            </table>

            <div class="bf-footer-note">Generated from HAPL OCR Supply Chain Booking Generate module - PO {{ $poNo }}</div>
        </div>
    </div>

    @if($canEditThisPreview)
        @php
            $generateLabel = $adminEditMode
                ? 'Save PO Edit'
                : ($regenerateMode ? 'Re-generate PO' : ($batchMode ? 'Generate PO for Selected Orders' : 'Generate PO'));
            $generateIcon = $regenerateMode ? 'bi-arrow-repeat' : 'bi-check2-circle';
            $loadingLabel = $adminEditMode ? 'Saving PO...' : ($regenerateMode ? 'Re-generating PO...' : 'Generating PO...');
            $footerHelp = $batchMode
                ? 'One PO number will be generated for all selected orders.'
                : 'Please review all booking details before generating PO.';
        @endphp
        <div class="bf-action-footer no-print">
            <div class="bf-action-footer-help">
                <i class="bi bi-info-circle"></i>
                <span>{{ $footerHelp }}</span>
            </div>
            <div class="bf-action-footer-buttons">
                <button type="button" class="btn bf-btn-cancel booking-preview-cancel" data-bs-dismiss="modal" aria-label="Close preview">
                    <i class="bi bi-x-lg me-1"></i>Cancel
                </button>
                <button type="button"
                        class="btn bf-btn-generate preview-generate-po-btn"
                        data-url="{{ $generateUrl }}"
                        data-regenerate="{{ $regenerateMode ? '1' : '0' }}"
                        data-edit="{{ $adminEditMode ? '1' : '0' }}"
                        data-batch="{{ $batchMode ? '1' : '0' }}"
                        @if($batchMode) data-rows="{{ implode(',', $batchRowIds) }}" @endif
                        data-loading-label="{{ $loadingLabel }}"
                        aria-label="{{ $generateLabel }}">
                    <span class="bf-btn-generate-content"><i class="bi {{ $generateIcon }} me-1"></i>{{ $generateLabel }}</span>
                </button>
            </div>
        </div>
    @endif
@if($canEditThisPreview)
    </form>
@endif
</div>
