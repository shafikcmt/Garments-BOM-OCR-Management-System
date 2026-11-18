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
    $incotermOptions = \App\Models\SupplierOption::names('incoterm');
    $shipModeOptions = \App\Models\SupplierOption::names('ship_mode');
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

        /* THE SHEET'S RENDERED WIDTH, derived rather than measured.
         *
         * .bf-sheet is `width: 198mm` scaled by CSS `zoom` — and zoom scales
         * layout, so the sheet occupies exactly 198mm x zoom on screen. That is
         * computable in CSS, which is why the chrome panels that must line up
         * with the paper need no JS resize listener: they read the same
         * --booking-preview-zoom the sheet does, off a shared ancestor
         * (#bookingPreviewContent, where the zoom buttons set it), so they
         * re-sync on their own the moment the zoom changes.
         *
         * Had the sheet used `transform: scale()` this would not work — a
         * transform paints at a different size than it lays out at, so the
         * rendered width could only be read back from the DOM.
         *
         * The 1.35 fallback matches the sheet's own default, which keeps the
         * Generated PO and Admin screens correct: they render this partial with
         * no zoom control, so the variable is never set there.
         */
        --po-sheet-rendered-width: calc(198mm * var(--booking-preview-zoom, 1.35));

        background: linear-gradient(180deg, #eef6fb 0%, #ffffff 100%);
        padding: 8px;
        border-radius: 12px;
        overflow: visible;
    }
    .booking-format-preview-box .bf-sheet {
        width: 198mm;
        /* DEFAULT 1.35, not 1. The sheet is a true-to-paper A4 rendering — 198mm
           wide with 7px body text — so at 1 it is the size the text would be on
           paper, which nobody can read on a screen. Users were reaching for the
           Zoom In button and stopping at 140% every single time, so that is now
           where it starts. Nothing about the design changes; only the scale it
           is first painted at. The zoom control still overrides this, and the
           print/PDF output never reads this variable at all. */
        zoom: var(--booking-preview-zoom, 1.35);
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
    /* The Required Ex-mill row only. The table is table-layout:fixed, so the
       label column is locked to the 20mm set by the first row and "REQUIRED
       EX-MILL" broke across two lines inside it. nowrap plus a slightly tighter
       size and tracking fits it on one line without touching the column width,
       which every other row depends on. */
    .booking-format-preview-box .bf-info-table th.bf-info-tight {
        white-space: nowrap;
        font-size: 6px;
        letter-spacing: -.01em;
    }
    /* The left half of the REQUIRED EX-MILL row. Fifteen fields in a
       two-per-row table always leave one half-cell empty, so this cannot be
       removed — REQUIRED EX-MILL lives in the other half of this very row.
       What it can stop doing is looking like a cell: without its top and
       bottom edges it reads as continuous space between the ATTN row and the
       E-MAIL row, rather than an empty box someone forgot to fill. The right
       edge stays, so REQUIRED EX-MILL keeps its own left border. */
    .booking-format-preview-box .bf-info-table td.bf-info-blank {
        background: #fff;
        border-top: 0;
        border-bottom: 0;
        border-left: 0;
    }
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
    /* THE EDIT PANEL — application chrome, not the printed sheet.
     *
     * It sits inside .booking-format-preview-box but is `no-print` and is never
     * part of the document the vendor receives. Everything below therefore uses
     * the module's shared tokens (--po-*), the same ones the filter card, KPI
     * cards and tables use, so the one screen a merchant actually types into
     * stops looking like a raw dev form bolted onto a polished module.
     *
     * NOTHING here touches .bf-* — the sheet's own styles are untouched.
     */
    .booking-format-preview-box .booking-preview-edit-panel {
        /* EXACTLY THE SHEET'S RENDERED WIDTH — see --po-sheet-rendered-width
         * above. Not 198mm (that ignores the zoom and left the panel narrower
         * than the document), and not 100% (that made it wider than the
         * document). Both edges now line up with the paper below it, at every
         * zoom level, with no JS.
         */
        width: var(--po-sheet-rendered-width);
        max-width: 100%;
        margin: 0 auto 12px;
        border: 1px solid var(--po-line, #D7E3ED);
        border-radius: var(--po-r-surface, 14px);
        background: var(--po-surface, #fff);
        padding: 18px;
        box-shadow: var(--po-shadow, 0 14px 34px rgba(11, 43, 60, .06));
    }
    /* Section heads carry the module's docket rail, so the panel reads as three
       groups — Vendor, Booking details, Instructions — instead of one flat run
       of fifteen fields. */
    .booking-format-preview-box .bp-section + .bp-section {
        margin-top: 18px;
        padding-top: 18px;
        border-top: 1px solid var(--po-line-soft, #EEF3F8);
    }
    .booking-format-preview-box .bp-section-head {
        position: relative;
        padding-left: 10px;
        margin-bottom: 12px;
        font-size: 10px;
        font-weight: 800;
        letter-spacing: .09em;
        text-transform: uppercase;
        color: var(--po-blue-deep, #004B70);
    }
    .booking-format-preview-box .bp-section-head::before {
        content: "";
        position: absolute;
        left: 0;
        top: 1px;
        bottom: 1px;
        width: 3px;
        border-radius: 2px;
        background: var(--po-blue, #006C9D);
    }
    /* Sub-cards for the two instruction columns, so each is a contained list
       rather than loose controls floating on the panel. */
    .booking-format-preview-box .bp-subcard {
        border: 1px solid var(--po-line, #D7E3ED);
        border-radius: var(--po-r-control, 10px);
        background: var(--po-paper, #F6F9FC);
        padding: 10px;
    }
    .booking-format-preview-box .bp-scroll { max-height: 232px; overflow-y: auto; }
    /* Suggested instructions: a real checkbox list. It replaces a
       <select multiple size="7"> whose options were clipped mid-word and which
       needed a "hold Ctrl/Cmd" instruction to operate. Same field name, so the
       POST is byte-identical. */
    .booking-format-preview-box .bp-check {
        display: flex;
        gap: 8px;
        align-items: flex-start;
        padding: 7px 8px;
        border-radius: 8px;
        cursor: pointer;
    }
    .booking-format-preview-box .bp-check + .bp-check { border-top: 1px solid var(--po-line-soft, #EEF3F8); }
    .booking-format-preview-box .bp-check:hover { background: var(--po-tint, #E0F2FE); }
    .booking-format-preview-box .bp-check input { margin-top: 2px; flex: 0 0 auto; }
    .booking-format-preview-box .bp-check span {
        font-size: 11.5px;
        line-height: 1.4;
        font-weight: 500;
        text-transform: none;
        letter-spacing: 0;
        color: var(--po-ink, #0B2B3C);
    }
    /* Current instructions: numbered rows. */
    .booking-format-preview-box .bp-note-index {
        flex: 0 0 auto;
        width: 20px;
        height: 20px;
        margin-top: 6px;
        border-radius: 999px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: var(--po-tint, #E0F2FE);
        color: var(--po-blue-deep, #004B70);
        font-size: 10px;
        font-weight: 900;
        font-variant-numeric: tabular-nums;
    }
    /* Remove is QUIET until you reach for it. Seven permanent red outline
       buttons down the panel made deletion the loudest thing on a screen whose
       job is editing text. */
    .booking-format-preview-box .bp-remove {
        flex: 0 0 auto;
        margin-top: 4px;
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 5px 10px;
        border-radius: var(--po-r-pill, 999px);
        border: 1px solid var(--po-line, #D7E3ED);
        background: var(--po-surface, #fff);
        color: var(--po-muted, #5B7383);
        font-size: 11px;
        font-weight: 800;
        line-height: 1;
        white-space: nowrap;
    }
    .booking-format-preview-box .bp-remove:hover,
    .booking-format-preview-box .bp-remove:focus {
        border-color: var(--po-danger-border, #FECACA);
        background: var(--po-danger-bg, #FEF2F2);
        color: var(--po-danger, #B91C1C);
    }
    .booking-format-preview-box .booking-preview-edit-panel label {
        font-size: 10px;
        font-weight: 800;
        color: var(--po-muted, #5B7383);
        letter-spacing: .09em;
        text-transform: uppercase;
        margin-bottom: 5px;
    }
    /* 40px controls on the module radius — the same height and shape as every
       field in the filter card, instead of the 34px default this panel had. */
    .booking-format-preview-box .booking-preview-edit-panel .form-control,
    .booking-format-preview-box .booking-preview-edit-panel .form-select {
        min-height: 40px;
        border-radius: var(--po-r-control, 10px);
        border-color: var(--po-line, #D7E3ED);
        color: var(--po-ink, #0B2B3C);
        font-size: 12px;
        font-weight: 600;
    }
    .booking-format-preview-box .booking-preview-edit-panel textarea.form-control { min-height: 0; }
    .booking-format-preview-box .booking-preview-edit-panel .form-control:focus,
    .booking-format-preview-box .booking-preview-edit-panel .form-select:focus {
        border-color: var(--po-blue, #006C9D);
        box-shadow: 0 0 0 3px rgba(0, 108, 157, .14);
    }
    .booking-format-preview-box .booking-preview-edit-panel .form-text {
        font-size: 10.5px;
        color: var(--po-muted, #5B7383);
    }
    .booking-format-preview-box .booking-preview-edit-panel .form-check-label { text-transform: none; letter-spacing: 0; font-size: 12px; font-weight: 600; }
    .booking-format-preview-box .booking-preview-note-row { display: flex; gap: 8px; align-items: flex-start; margin-bottom: 8px; }
    .booking-format-preview-box .booking-preview-note-row textarea { flex: 1; width: 100%; }
    .booking-format-preview-box .bp-note-body { flex: 1; min-width: 0; }
    /* Opt-in on a NEW line only: keep this wording in the shared library so the
       next booking can tick it from the suggestion list. Overrides the panel's
       uppercase micro-label rule, which is for field captions, not a sentence
       the user has to read. */
    .booking-format-preview-box .booking-preview-edit-panel label.bp-note-save {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        margin: 6px 0 0;
        font-size: 11px;
        font-weight: 600;
        letter-spacing: 0;
        text-transform: none;
        color: var(--po-muted, #5B7383);
        cursor: pointer;
    }
    .booking-format-preview-box .bp-note-save input { margin: 0; flex: 0 0 auto; }

    /* Tracks the sheet, same as the edit panel, so the whole column shares one
       pair of edges. */
    .booking-format-preview-box .booking-change-control-panel {
        width: var(--po-sheet-rendered-width);
        max-width: 100%;
        margin: 0 auto 12px;
        border: 1px solid #fecaca;
        border-radius:var(--gx-radius);
        background: linear-gradient(135deg, #fff7f7 0%, #ffffff 100%);
        padding: 12px;
        box-shadow: 0 14px 30px rgba(185, 28, 28, .08);
    }
    .booking-format-preview-box .booking-change-control-panel h6 { font-size: 13px; font-weight: 900; color: #991b1b; }
    .booking-format-preview-box .booking-change-table { margin-bottom: 0; }
    .booking-format-preview-box .booking-change-table th { font-size: 10px; color: #7f1d1d; text-transform: uppercase; letter-spacing: .05em; }
    .booking-format-preview-box .booking-change-table td { font-size: 11px; color: #334155; vertical-align: top; }
    .booking-format-preview-box .booking-history-mini { width: var(--po-sheet-rendered-width); max-width:100%; margin: 0 auto 12px; border: 1px solid var(--po-line, #D7E3ED); border-radius:var(--po-r-surface, 14px); background:#fff; padding: 10px 12px; }
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
            <span class="bf-batch-count"><i class="bi bi-collection me-1" aria-hidden="true"></i>Selected Orders: {{ $selectedOrderCount ?? count($batchRowIds) }}</span>
            <span class="bf-batch-note"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>One PO number will be generated for all selected orders.</span>
        </div>
    @endif
    @if($canEditThisPreview)
        <div class="bf-action-bar no-print">
            <button type="button" class="btn btn-outline-primary btn-sm booking-preview-edit-toggle">
                @if($editPanelOpen)
                    <i class="bi bi-eye me-1" aria-hidden="true"></i>Hide Edit
                @else
                    <i class="bi bi-pencil-square me-1" aria-hidden="true"></i>Edit Preview
                @endif
            </button>
        </div>
    @endif

    @if($canControlPo && $sourceChanges->isNotEmpty())
        <div class="booking-change-control-panel no-print">
            <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap mb-2">
                <div>
                    <h6 class="mb-1"><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>Source data changed after PO generation</h6>
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
            <div class="fw-bold text-slate-900"><i class="bi bi-clock-history me-1 text-primary" aria-hidden="true"></i>PO control history</div>
            @foreach($generationHistory->take(3) as $entry)
                <div class="history-line">{{ ucfirst(str_replace('_', ' ', $entry['action'] ?? 'generated')) }} @if(($entry['revision_no'] ?? 0) > 0) · R-{{ $entry['revision_no'] }}@endif · {{ $entry['changed_by_name'] ?? 'System' }} · {{ $entry['changed_at'] ?? '-' }} @if(! empty($entry['changes'])) · {{ count($entry['changes']) }} edited field(s) @endif</div>
            @endforeach
        </div>
    @endif

    @if($canEditThisPreview)
        <div class="booking-preview-edit-panel no-print {{ $editPanelOpen ? '' : 'd-none' }}">
            <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap mb-3">
                <div>
                    <h6 class="mb-1 fw-bold"><i class="bi bi-pencil-square me-1 text-primary" aria-hidden="true"></i>{{ $adminEditMode ? 'Edit PO Control Data' : ($regenerateMode ? 'Edit Before PO Re-generate' : 'Edit Vendor Details & Instructions') }}</h6>
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

            {{-- Section 1 of 3. The fields below are unchanged — only grouped,
                 so the panel reads as Vendor / Booking details / Instructions
                 rather than one undifferentiated run of inputs. --}}
            <div class="bp-section">
            <div class="bp-section-head">Vendor</div>
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
            </div>
            </div>

            {{-- Section 2 of 3. --}}
            <div class="bp-section">
            <div class="bp-section-head">Booking details</div>
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Date</label>
                    <input type="text" name="booking[date]" value="{{ $bookingData['date'] ?? '' }}"
                           class="form-control js-booking-date">
                </div>
                {{-- Required Ex-mill defaults to Date + 1 month. The two hooks
                     below are read by the delegated script the host page loads
                     (_ex-mill-autofill.blade.php): js-booking-ex-mill marks the
                     field, and data-ex-mill-touched records whether the user
                     has typed in it, so re-dating the PO never overwrites a
                     date somebody set on purpose. --}}
                <div class="col-md-3">
                    <label class="form-label">Required Ex-mill</label>
                    <input type="text" name="booking[required_ex_mill]"
                           value="{{ $bookingData['required_ex_mill'] ?? '' }}"
                           class="form-control js-booking-ex-mill"
                           data-ex-mill-touched="0"
                           placeholder="dd/mm/yyyy">
                    <div class="form-text">Defaults to Date + 1 month. Edit if the vendor deadline differs.</div>
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
            </div>{{-- /.row --}}
            </div>{{-- /.bp-section Booking details --}}

            {{-- Section 3 of 3. --}}
            <div class="bp-section">
            <div class="bp-section-head">Instructions</div>
            @php
                // The tick list is filtered to this PO's buyer AND to instructions
                // not already on the PO, so on a fresh preview it is usually empty
                // — every default is already under Current Instructions. An empty
                // box with a paragraph explaining why it is empty is clutter on a
                // panel that is already dense, so the whole column is dropped and
                // Current Instructions takes the full width instead.
                $hasSuggestions = count($instructionOptions) > 0;
            @endphp
            <div class="row g-3">
                @if($hasSuggestions)
                <div class="col-md-5">
                    <label class="form-label">Add extra suggested instructions</label>
                    {{-- A TICK LIST, not a <select multiple>. The old control clipped
                         every option mid-word and needed a "hold Ctrl/Cmd" note to
                         operate, which is a support call waiting to happen for
                         non-technical staff. Same field name and same values, so
                         the submitted payload is identical. --}}
                    <div class="bp-subcard bp-scroll">
                        @foreach($instructionOptions as $instruction)
                            <label class="bp-check">
                                <input type="checkbox" class="form-check-input" name="common_instruction_ids[]" value="{{ $instruction->id }}">
                                <span>{{ $instruction->instruction }}</span>
                            </label>
                        @endforeach
                    </div>
                    <div class="form-text">Tick any suggestion to add it to this booking. Shows global instructions plus this buyer's own. Admin manages this list.</div>
                </div>
                @endif
                <div class="{{ $hasSuggestions ? 'col-md-7' : 'col-12' }}">
                    <label class="form-label">Current instructions</label>
                    {{-- ONE way to add a line. There used to be a second "New
                         Instruction" box below with its own save-as-suggestion
                         checkbox; it did exactly what a blank row here does, minus
                         being able to add more than one, so the checkbox moved onto
                         the row and the duplicate box is gone. --}}
                    <div class="bp-subcard">
                        <div class="booking-preview-notes-list" data-next-index="{{ max(count($notes), 1) }}">
                            @forelse($notes as $note)
                                <div class="booking-preview-note-row">
                                    <span class="bp-note-index" aria-hidden="true">{{ $loop->iteration }}</span>
                                    <div class="bp-note-body">
                                        <textarea name="notes[{{ $loop->index }}]" rows="2" class="form-control" placeholder="Instruction text">{{ $note }}</textarea>
                                    </div>
                                    <button type="button" class="bp-remove booking-preview-remove-note"><i class="bi bi-x-lg" aria-hidden="true"></i>Remove</button>
                                </div>
                            @empty
                                <div class="booking-preview-note-row">
                                    <span class="bp-note-index" aria-hidden="true">1</span>
                                    <div class="bp-note-body">
                                        <textarea name="notes[0]" rows="2" class="form-control" placeholder="Instruction text"></textarea>
                                    </div>
                                    <button type="button" class="bp-remove booking-preview-remove-note"><i class="bi bi-x-lg" aria-hidden="true"></i>Remove</button>
                                </div>
                            @endforelse
                        </div>
                        <button type="button" class="btn btn-outline-primary btn-sm booking-preview-add-note mt-1"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add Instruction Line</button>
                    </div>
                    <div class="form-text">Edit any line, or add one. Tick <strong>Save as suggestion</strong> on a new line to keep it in the list Admin manages for future bookings.</div>
                </div>
            </div>{{-- /.row --}}
            </div>{{-- /.bp-section Instructions --}}
        </div>{{-- /.booking-preview-edit-panel --}}
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
                {{-- RIGHT COLUMN, directly under DATE, which it is derived from.
                     The left half is one blank spacer cell (colspan=2) rather
                     than an empty th+td pair: the empty th was painting the
                     header tint, so it read as a stray leftover box instead of
                     dead space. Still four columns wide, so no other row's
                     structure changes. --}}
                <tr>
                    <td class="bf-info-blank" colspan="2"></td>
                    <th class="bf-info-tight">REQUIRED EX-MILL</th>
                    <td>{{ $bookingData['required_ex_mill'] ?? '' }}</td>
                </tr>
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
                        <th style="width:10mm;">Fabric CW</th>
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
                            <td>{{ $item['fabric_cw'] ?? 'N/A' }}</td>
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

            <div class="bf-footer-note">Generated from HAPL Threadloop Supply Chain Booking Generate module - PO {{ $poNo }}</div>
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
                <i class="bi bi-info-circle" aria-hidden="true"></i>
                <span>{{ $footerHelp }}</span>
            </div>
            <div class="bf-action-footer-buttons">
                <button type="button" class="btn bf-btn-cancel booking-preview-cancel" data-bs-dismiss="modal" aria-label="Close preview">
                    <i class="bi bi-x-lg me-1" aria-hidden="true"></i>Cancel
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
