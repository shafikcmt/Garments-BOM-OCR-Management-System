@extends('layouts.app')

@section('title', 'Order Information')

{{-- Tom Select, for the Add New Order dialog's Buyer/Style/Season dropdowns.
     Same library and same pinned version the Booking Preview filters use — one
     searchable-dropdown pattern in the app, not two. The script is loaded in
     @section('scripts') below, which the layout yields inside <body>, so it is
     parsed before the DOMContentLoaded handler that initialises it. --}}
@section('styles')
<link href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.bootstrap5.min.css" rel="stylesheet">
@endsection

@section('scripts')
<script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>
@endsection

@section('content')
@php
    // Each entry is the field's own distribution, not a bare string — see
    // ExcelFileController::buildOrderInfo(). A box with one distinct value
    // renders as it always did; one with several renders a count and a filter,
    // because the first row's value does not speak for the whole file.
    $blankOrderInfoBox = [
        'value' => '',
        'header_id' => null,
        'options' => [],
        'distinct' => 0,
        'noun' => '',
        'filter_value' => '',
    ];

    $orderInfo = $orderInfo ?? [
        'Buyer Name' => $blankOrderInfoBox,
        'Season Name' => $blankOrderInfoBox,
        'Style Name' => $blankOrderInfoBox,
        'Contract Number' => $blankOrderInfoBox,
        'Contract Shipment Date' => $blankOrderInfoBox,
    ];

    $updateQueryString = http_build_query(request()->only('page', 'per_page', 'search', 'filters'));
    $globalSearchValue = request()->query('search', '');
    $filterValues = (array) request()->query('filters', []);

    $roleColorClasses = [
        'merchant' => 'role-merchant',
        'commercial' => 'role-commercial',
        'supply-chain' => 'role-supply-chain',
        'supply_chain' => 'role-supply-chain',
        'supplychain' => 'role-supply-chain',
        'production' => 'role-production',
        'accounts' => 'role-accounts',
        'account' => 'role-accounts',
        'store' => 'role-store',
    ];

    $normalizeRoleSlug = function ($roleName) {
        $roleSlug = strtolower(trim((string) $roleName));
        $roleSlug = preg_replace('/[^a-z0-9]+/', '-', $roleSlug);

        return trim($roleSlug, '-');
    };

    $headerRoleClass = function ($header) use ($roleColorClasses, $normalizeRoleSlug) {
        $roleSlug = $normalizeRoleSlug(optional($header->ownerRole)->name ?? 'default');

        return $roleColorClasses[$roleSlug] ?? 'role-default';
    };

    $currentUserRoleSlugs = auth()->check()
        ? auth()->user()->roles->pluck('name')
            ->map($normalizeRoleSlug)
            ->filter()
            ->flatMap(function ($roleSlug) {
                $aliases = [$roleSlug];

                if ($roleSlug === 'account') {
                    $aliases[] = 'accounts';
                }

                if ($roleSlug === 'accounts') {
                    $aliases[] = 'account';
                }

                if (in_array($roleSlug, ['supply-chain', 'supplychain'], true)) {
                    $aliases[] = 'supply-chain';
                    $aliases[] = 'supplychain';
                }

                return $aliases;
            })
            ->unique()
            ->values()
        : collect();

    $shouldAutoScrollToUserColumns = $globalSearchValue === '' && count($filterValues) === 0;

    // Keep this outside @json(...complex expression...) because Blade can break
    // when arrow functions return arrays inside @json().
    $sheetHeadersForJs = $headers->map(function ($header) use ($normalizeRoleSlug) {
        $ownerRoleName = optional($header->ownerRole)->name;

        return [
            'id' => $header->id,
            'header_key' => $header->header_key,
            'header_name' => $header->header_name,
            'owner_role' => $ownerRoleName,
            'owner_role_slug' => $normalizeRoleSlug($ownerRoleName),
            'field_type' => $header->field_type,
            'value_mode' => $header->value_mode ?? 'input',
        ];
    })->values();
@endphp

<style>

    .notification-highlight-cell {
    background: #fff3cd !important;
    box-shadow: inset 0 0 0 2px #f59e0b;
    animation: notificationPulse 1.4s ease-in-out 3;
}

.notification-highlight-cell input,
.notification-highlight-cell .excel-readonly-cell {
    background: #fff7df !important;
    border-color: #f59e0b !important;
}

.notification-highlight-alert {
    border: 1px solid #fde68a;
    background: #fffbeb;
    color: #92400e;
    border-radius: 10px;
    font-size: 13px;
    font-weight: 600;
}

@keyframes notificationPulse {
    0% { box-shadow: inset 0 0 0 2px #f59e0b; }
    50% { box-shadow: inset 0 0 0 4px #fbbf24; }
    100% { box-shadow: inset 0 0 0 2px #f59e0b; }
}


    .draft-restored-cell {
        background: #ecfdf5 !important;
        box-shadow: inset 0 0 0 2px #10b981;
        animation: draftRestorePulse 1.4s ease-in-out 3;
    }

    .draft-restored-cell input,
    .draft-restored-cell .excel-readonly-cell {
        background: #f0fdf4 !important;
        border-color: #10b981 !important;
    }

    .draft-restore-toast {
        position: fixed;
        right: 24px;
        bottom: 24px;
        z-index: 10000;
        max-width: 360px;
        border-radius: 12px;
        padding: 12px 16px;
        background: linear-gradient(135deg, #059669, #0f766e);
        color: #fff;
        box-shadow: 0 18px 40px rgba(15, 23, 42, 0.25);
        font-size: 13px;
        font-weight: 700;
        opacity: 0;
        transform: translateY(8px);
        pointer-events: none;
        transition: opacity .2s ease, transform .2s ease;
    }

    .draft-restore-toast.show {
        opacity: 1;
        transform: translateY(0);
    }

@keyframes draftRestorePulse {
    0% { box-shadow: inset 0 0 0 2px #10b981; }
    50% { box-shadow: inset 0 0 0 4px #34d399; }
    100% { box-shadow: inset 0 0 0 2px #10b981; }
}

    .calculated-cell {
        background: #f8fafc;
    }

    .excel-readonly-cell {
        min-width: 132px;
        min-height: 28px;
        padding: 3px 6px;
        border-radius: 6px;
        display: flex;
        align-items: center;
        line-height: 1.2;
        font-size: 12px;
    }

    .excel-calculated-value {
        background: #f4f8ff;
        border: 1px solid #dbe6f5;
        color: #1e3a8a;
        font-weight: 600;
    }

    .formula-live-updated {
        box-shadow: inset 0 0 0 1px rgba(96, 165, 250, 0.25);
    }

    .excel-data-table .role-column {
        border-left: 1px solid #edf2f7;
    }

    .excel-data-table thead th.role-column {
        color: #0f172a;
    }

    .excel-data-table tbody td.role-column {
        background-clip: padding-box;
        transition: background-color 0.18s ease, box-shadow 0.18s ease;
    }

    .excel-data-table tbody tr:nth-child(even) td.role-column {
        background-clip: padding-box;
    }

    .role-merchant { background-color: #f8fbff !important; }
    .role-commercial { background-color: #fffaf6 !important; }
    .role-supply-chain { background-color: #f6fcf8 !important; }
    .role-production { background-color: #fbf9ff !important; }
    .role-accounts { background-color: #fff8f8 !important; }
    .role-store { background-color: #f5fcfb !important; }
    .role-default { background-color: #fafcff !important; }

    .excel-data-table tbody td.calculated-cell {
        background: linear-gradient(180deg, #f7fbff, #eef5ff) !important;
    }

    .excel-data-table thead th.formula-header-cell {
        background: linear-gradient(180deg, #f7fbff, #edf4ff) !important;
        border-bottom: 1px solid #d9e6f5;
    }

    .header-title {
        font-weight: 700;
        line-height: 1.2;
        margin-bottom: 4px;
        font-size: 12px;
        letter-spacing: -0.01em;
    }

    .header-meta {
        display: flex;
        flex-wrap: wrap;
        justify-content: center;
        gap: 4px;
    }

    .role-chip,
    .formula-chip {
        display: inline-flex;
        align-items: center;
        border-radius: 999px;
        padding: 2px 7px;
        font-size: 9px;
        font-weight: 700;
        line-height: 1.1;
        border: 1px solid rgba(15, 23, 42, 0.06);
        box-shadow: 0 1px 1px rgba(15, 23, 42, 0.02);
    }

    .role-chip {
        background: rgba(255, 255, 255, 0.9);
        color: #475569;
    }

    .formula-chip {
        background: linear-gradient(180deg, #4f8df8, #2f6fe8);
        color: #fff;
        border-color: #3f7ceb;
    }
    .order-summary-card {
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        background: #fff;
        box-shadow: 0 2px 8px rgba(15,23,42,.05);
        overflow: hidden;
    }

    .order-summary-top {
        background: #f8fafc;
        border-bottom: 1px solid #e9eef5;
        padding: 8px 14px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 8px;
        min-height: 44px;
    }

    .order-summary-meta {
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 6px;
    }

    .order-meta-chip {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 3px 8px;
        border-radius: 999px;
        font-size: 11px;
        font-weight: 700;
        white-space: nowrap;
    }

    .order-meta-chip-label {
        color: #94a3b8;
        font-size: 10px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: .05em;
    }

    .order-meta-chip-value {
        color: #0f172a;
    }

    .order-meta-chip-status {
        background: #f0fdf4;
        color: #15803d;
        border: 1px solid #bbf7d0;
    }

    /* One department's progress on this order, inline in the meta row so it
       costs no vertical space on an already dense screen. */
    .order-dept-progress {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 3px 10px;
        border-radius: 999px;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        font-size: 11px;
        line-height: 1.4;
    }

    .order-dept-name { font-weight: 700; color: #0f172a; }
    .order-dept-count { color: #64748b; white-space: nowrap; }

    .order-dept-bar {
        display: inline-block;
        width: 76px;
        height: 6px;
        border-radius: 999px;
        background: #e2e8f0;
        overflow: hidden;
    }

    .order-dept-bar-fill {
        display: block;
        height: 100%;
        border-radius: 999px;
        background: #2563eb;
    }

    .order-dept-percent { font-weight: 700; color: #1d4ed8; }

    @media (max-width: 767.98px) {
        .order-dept-bar { display: none; }
    }

    .order-meta-chip-locked {
        background: #fef2f2;
        color: #b91c1c;
        border: 1px solid #fecaca;
    }

    .order-summary-grid {
        display: flex;
        flex-wrap: wrap;
        gap: 0;
        border-top: 1px solid #f1f5f9;
    }

    .order-info-box {
        flex: 1 1 160px;
        padding: 8px 14px;
        border-right: 1px solid #f1f5f9;
        min-width: 0;
    }

    .order-info-box:last-child {
        border-right: 0;
    }

    .order-info-label {
        font-size: 10px;
        font-weight: 700;
        color: #94a3b8;
        text-transform: uppercase;
        letter-spacing: .04em;
        margin-bottom: 2px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    /* Matched to .order-info-count-figure below, so a box carries the same
       visual weight whether it holds one value or a count of many. Before, a
       12px value sat beside a bolder count and the row read as two different
       kinds of box. */
    .order-info-value {
        font-size: 15px;
        font-weight: 800;
        line-height: 1.1;
        letter-spacing: -.01em;
        color: var(--oi-ink, #0B2B3C);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    /* The module palette, imported rather than re-picked — same approach the
       Add New Order dialog above takes, so these boxes stay part of the PO
       module's system instead of becoming a second one. Literal fallbacks keep
       them correct on screens where the theme partial is not loaded. */
    .order-summary-grid,
    .order-info-dropdown {
        --oi-blue: var(--po-blue, #006C9D);
        --oi-ink: var(--po-ink, #0B2B3C);
        --oi-muted: var(--po-muted, #5B7383);
        --oi-line: var(--po-line, #D7E3ED);
        --oi-line-soft: var(--po-line-soft, #EEF3F8);
        --oi-paper: var(--po-paper, #F6F9FC);
    }

    /* The varied-field boxes: a count where the single value used to sit, and
     * a filter under it. The figure carries the weight and the noun recedes,
     * so the box reads as "6 — styles" at a glance and still lines up with the
     * plain bold value in the Buyer Name box beside it. */
    .order-info-count {
        display: flex;
        align-items: baseline;
        gap: 4px;
        margin-bottom: 4px;
        min-width: 0;
    }

    .order-info-count-figure {
        font-size: 15px;
        font-weight: 800;
        line-height: 1.1;
        color: var(--oi-ink, #0B2B3C);
        letter-spacing: -.01em;
    }

    .order-info-count-noun {
        font-size: 11px;
        font-weight: 600;
        color: var(--oi-muted, #5B7383);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    /* Tom Select replaces the <select> with its own markup, so the compact
     * sizing has to be restated for the control it builds. */
    .order-info-box .order-info-filter,
    .order-info-box .ts-wrapper .ts-control {
        font-size: 11px;
        min-height: 28px;
        padding: 2px 22px 2px 8px;
        border-radius: var(--po-r-control, 10px);
        border: 1px solid var(--oi-line, #D7E3ED);
        background: var(--oi-paper, #F6F9FC);
        color: var(--oi-ink, #0B2B3C);
        font-weight: 600;
        line-height: 22px;
        box-shadow: none;
    }

    .order-info-box .ts-wrapper {
        min-height: 28px;
    }

    .order-info-box .ts-wrapper.dropdown-active .ts-control,
    .order-info-box .ts-wrapper.focus .ts-control {
        border-color: var(--oi-blue, #006C9D);
        background: #fff;
        box-shadow: 0 0 0 3px rgba(0, 108, 157, .14);
    }

    /* An applied filter is a state the merchant should be able to see without
       reading the value: tinted control, not a plain one. */
    .order-info-box .ts-wrapper.has-items .ts-control {
        background: #fff;
        border-color: var(--oi-blue, #006C9D);
        color: var(--oi-blue, #006C9D);
    }

    /* ---- The panel itself -------------------------------------------------
     * Parented to <body> (see the JS), because .order-summary-card is
     * overflow:hidden and clipped 163 of its 170 pixels. So it is styled from
     * the root, not from inside .order-info-box. */
    .order-info-dropdown {
        font-size: 11px;
        z-index: 2000;
        min-width: 210px;
        border: 1px solid var(--oi-line, #D7E3ED);
        border-radius: var(--po-r-control, 10px);
        box-shadow: var(--po-shadow, 0 14px 34px rgba(11, 43, 60, .06));
        overflow: hidden;
        margin-top: 4px;
    }

    .order-info-dropdown .ts-dropdown-content {
        max-height: 280px;
        padding: 4px;
    }

    .order-info-dropdown .option {
        border-radius: 7px;
        padding: 0;
        margin-bottom: 1px;
    }

    .order-info-dropdown .oi-opt {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        padding: 6px 8px;
        min-width: 0;
    }

    .order-info-dropdown .oi-opt-name {
        font-weight: 600;
        color: var(--oi-ink, #0B2B3C);
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    /* The row count is the reason this list exists — it is what tells the
       merchant how much of the file each value accounts for. */
    .order-info-dropdown .oi-opt-count {
        flex-shrink: 0;
        font-size: 10px;
        font-weight: 700;
        color: var(--oi-muted, #5B7383);
        background: var(--oi-line-soft, #EEF3F8);
        border-radius: var(--po-r-pill, 999px);
        padding: 1px 7px;
        font-variant-numeric: tabular-nums;
    }

    .order-info-dropdown .oi-opt-all {
        padding: 6px 8px;
        font-weight: 600;
        color: var(--oi-muted, #5B7383);
    }

    .order-info-dropdown .option.active .oi-opt-name,
    .order-info-dropdown .option.active .oi-opt-all {
        color: #fff;
    }

    .order-info-dropdown .option.active {
        background: var(--oi-blue, #006C9D);
    }

    .order-info-dropdown .option.active .oi-opt-count {
        background: rgba(255, 255, 255, .22);
        color: #fff;
    }

    .order-info-dropdown .no-results {
        padding: 8px;
        color: var(--oi-muted, #5B7383);
    }

    /* ==== WORKSHEET SURFACE ==============================================
     * Retoned onto the PO Generation module's tokens so the worksheet reads as
     * part of the same product: one blue (#006C9D, the booking sheet's own),
     * three radii, one shadow. Literal fallbacks throughout — this screen must
     * not depend on that module's theme partial being loaded.
     *
     * Structure is untouched: the sticky header, the sticky first column, the
     * filter row's 42px offset and the z-index ladder all still work exactly as
     * they did. Only colour, radius, spacing and type changed.
     */
    .excel-work-card {
        border: 1px solid var(--po-line, #D7E3ED);
        border-radius: var(--po-r-surface, 14px);
        box-shadow: var(--po-shadow, 0 14px 34px rgba(11, 43, 60, .06));
        overflow: hidden;
        background: var(--po-surface, #fff);
    }

    .excel-table-wrap {
        overflow: auto;
        max-height: 72vh;
        background: #fff;
        border-top: 1px solid var(--po-line-soft, #EEF3F8);
    }

    .excel-data-table {
        min-width: 1780px;
        margin-bottom: 0;
        font-size: 12px;
        border-color: var(--po-line-soft, #EEF3F8);
    }

    /* The booking sheet's own tint band, as on every other table in the app. */
    .excel-data-table thead th {
        position: sticky;
        top: 0;
        z-index: 3;
        background: var(--po-tint, #E0F2FE);
        color: var(--po-blue-deep, #004B70);
        vertical-align: middle;
        text-align: center;
        border-bottom: 1px solid var(--po-line, #D7E3ED);
        min-width: 145px;
        padding: 7px 8px;
        white-space: normal;
        box-shadow: inset 0 -1px 0 var(--po-line, #D7E3ED);
    }

    .excel-data-table thead th .header-title {
        font-size: 10px;
        font-weight: 800;
        letter-spacing: .06em;
        text-transform: uppercase;
        line-height: 1.25;
    }

    .excel-data-table thead tr.filter-row th {
        top: 46px;
        z-index: 2;
        background: #ffffff;
        padding: 4px 5px;
        min-width: 145px;
        box-shadow: inset 0 -1px 0 var(--po-line-soft, #EEF3F8);
    }

    .excel-data-table thead th:first-child,
    .excel-data-table thead tr.filter-row th:first-child,
    .excel-data-table tbody td:first-child {
        position: sticky;
        left: 0;
        z-index: 4;
        background: #fff;
        min-width: 78px;
        width: 78px;
        text-align: center;
        font-weight: 700;
    }

    .excel-data-table thead th:first-child {
        background: var(--po-tint, #E0F2FE);
    }

    .excel-data-table thead tr.filter-row th:first-child {
        background: #fff;
    }

    .excel-data-table tbody td {
        vertical-align: middle;
        background: #fff;
        padding: 2px 4px;
        border-color: #edf2f7;
    }

    .excel-data-table tbody tr:nth-child(even) td {
        background: #fcfcfd;
    }

    .excel-data-table tbody tr:hover td {
        background-color: #f8fbff;
    }

    .excel-data-table tbody tr:hover td.role-merchant { background-color: #f2f8ff !important; }
    .excel-data-table tbody tr:hover td.role-commercial { background-color: #fff5eb !important; }
    .excel-data-table tbody tr:hover td.role-supply-chain { background-color: #eefaf3 !important; }
    .excel-data-table tbody tr:hover td.role-production { background-color: #f7f3ff !important; }
    .excel-data-table tbody tr:hover td.role-accounts { background-color: #fff1f1 !important; }
    .excel-data-table tbody tr:hover td.role-store { background-color: #eefbf8 !important; }
    .excel-data-table tbody tr:hover td.role-default { background-color: #f6faff !important; }

    /* One cell height and radius across the grid, and a focus ring in the
       module blue so the active cell is obvious on a 128-column sheet. */
    .excel-data-table input.form-control {
        min-width: 132px;
        border-radius: 8px;
        padding: 4px 7px;
        font-size: 12px;
        height: 30px;
        border-color: var(--po-line, #D7E3ED);
        background: rgba(255, 255, 255, 0.96);
    }

    .excel-data-table input.form-control:focus {
        border-color: var(--po-blue, #006C9D);
        box-shadow: 0 0 0 3px rgba(0, 108, 157, .14);
    }

    .sheet-pagination {
        display: flex;
        flex-wrap: wrap;
        justify-content: space-between;
        align-items: center;
        gap: 10px;
        padding: 10px 14px;
        background: #ffffff;
        border-top: 1px solid #edf2f7;
    }

    .sheet-pagination .page-status {
        font-size: 12px;
        color: #64748b;
        font-weight: 600;
    }

    .sheet-pager {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 5px;
    }

    .sheet-pager .pager-btn,
    .sheet-pager .pager-page,
    .sheet-pager .pager-ellipsis {
        min-width: 32px;
        height: 30px;
        padding: 5px 9px;
        border-radius: 8px;
        border: 1px solid #dbe3ec;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 12px;
        line-height: 1;
        text-decoration: none;
        background: #fff;
        color: #334155;
        font-weight: 700;
    }

    .sheet-pager .pager-btn {
        min-width: 82px;
    }

    .sheet-pager a:hover {
        background: #f8fbff;
        border-color: #b8c7da;
        color: #1d4ed8;
    }

    .sheet-pager .active {
        background: #1d4ed8;
        border-color: #1d4ed8;
        color: #fff;
    }

    .sheet-pager .disabled {
        color: #94a3b8;
        background: #f8fafc;
        pointer-events: none;
        cursor: not-allowed;
    }

    .sheet-pager .pager-ellipsis {
        border-color: transparent;
        background: transparent;
        min-width: 24px;
        padding-left: 3px;
        padding-right: 3px;
    }

    .sheet-pagination svg {
        width: 14px !important;
        height: 14px !important;
    }

    .filter-input {
        min-width: 128px;
        border-radius: 8px;
        border: 1px solid var(--po-line, #D7E3ED);
        font-size: 12px;
        height: 30px;
        padding: 3px 6px;
        background: #fff;
    }
    .filter-input:focus {
        border-color: var(--po-blue, #006C9D);
        box-shadow: 0 0 0 3px rgba(0, 108, 157, .14);
    }

    /* ==== WORKSHEET TOOLBAR =============================================
     * The grid below was retoned onto the module tokens in an earlier pass;
     * this bar was the block left on hardcoded hex, which is why it read as
     * flatter than everything around it. Same aliasing pattern as the Order
     * Information boxes and the Add New Order dialog: import the palette,
     * keep literal fallbacks for screens without the theme partial.
     *
     * Nothing here touches the grid — the sticky ladder (z-index 2/3/4), the
     * filter row's top:46px offset and the min-width rules are all below and
     * untouched.
     */
    .server-search-bar {
        --tb-blue: var(--po-blue, #006C9D);
        --tb-ink: var(--po-ink, #0B2B3C);
        --tb-muted: var(--po-muted, #5B7383);
        --tb-line: var(--po-line, #D7E3ED);
        --tb-line-soft: var(--po-line-soft, #EEF3F8);

        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
        padding: 10px 14px;
        /* The tint the original rule asked for. It was overridden by a
           `background: #ffffff` on the very next line, so the bar had been
           rendering flat white and sitting on the grid with no separation. */
        background: var(--po-paper, #F6F9FC);
        border-top: 1px solid var(--tb-line-soft, #EEF3F8);
        border-bottom: 1px solid var(--tb-line, #D7E3ED);
    }

    .server-search-left {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 6px;
    }

    /* One height and one radius for every control on this bar, matching the
       Order Information controls above it. */
    .server-search-bar .form-control,
    .server-search-bar .form-select,
    .server-search-bar .btn {
        height: 28px;
        min-height: 28px;
        font-size: 12px;
        border-radius: var(--po-r-control, 10px);
        padding-top: 0;
        padding-bottom: 0;
        display: inline-flex;
        align-items: center;
    }

    .server-search-input {
        width: min(280px, 60vw);
        border: 1px solid var(--tb-line, #D7E3ED);
        color: var(--tb-ink, #0B2B3C);
        background: #fff;
    }

    .server-search-bar .form-control:focus,
    .server-search-bar .form-select:focus {
        border-color: var(--tb-blue, #006C9D);
        box-shadow: 0 0 0 3px rgba(0, 108, 157, .14);
    }

    .search-help-text {
        font-size: 10px;
        color: var(--tb-muted, #5B7383);
        font-weight: 700;
    }

    .header-find-tools {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: flex-end;
        gap: 6px;
        margin-left: auto;
        /* Reads as its own cluster rather than more controls in a row. */
        padding-left: 12px;
        border-left: 1px solid var(--tb-line, #D7E3ED);
    }

    .header-find-label {
        font-size: 10px;
        font-weight: 800;
        color: var(--tb-muted, #5B7383);
        text-transform: uppercase;
        letter-spacing: .04em;
    }

    .header-find-select {
        width: min(240px, 54vw);
        border: 1px solid var(--tb-line, #D7E3ED);
        color: var(--tb-ink, #0B2B3C);
        background: #fff;
    }

    /* Tom Select builds its own control, so the bar's sizing is restated for
       it — same as the Order Information boxes. */
    .header-find-tools .ts-wrapper {
        width: min(240px, 54vw);
        min-height: 28px;
    }

    .header-find-tools .ts-wrapper .ts-control {
        min-height: 28px;
        height: 28px;
        font-size: 12px;
        padding: 0 22px 0 8px;
        border-radius: var(--po-r-control, 10px);
        border: 1px solid var(--tb-line, #D7E3ED);
        color: var(--tb-ink, #0B2B3C);
        line-height: 26px;
        box-shadow: none;
    }

    .header-find-tools .ts-wrapper.dropdown-active .ts-control,
    .header-find-tools .ts-wrapper.focus .ts-control {
        border-color: var(--tb-blue, #006C9D);
        box-shadow: 0 0 0 3px rgba(0, 108, 157, .14);
    }

    /* Parented to <body> (see the JS), so it is styled from the root rather
       than from inside .header-find-tools. z-index clears the sticky header
       band below. */
    .header-find-dropdown {
        z-index: 2000;
        font-size: 12px;
        border: 1px solid var(--tb-line, #D7E3ED);
        border-radius: var(--po-r-control, 10px);
        box-shadow: var(--po-shadow, 0 14px 34px rgba(11, 43, 60, .06));
        margin-top: 4px;
    }

    .header-find-dropdown .ts-dropdown-content {
        max-height: 300px;
        padding: 4px;
    }

    .header-find-dropdown .option {
        border-radius: 7px;
        padding: 6px 8px;
        color: var(--tb-ink, #0B2B3C);
    }

    .header-find-dropdown .option.active {
        background: var(--tb-blue, #006C9D);
        color: #fff;
    }

    .header-find-dropdown .no-results {
        padding: 8px;
        color: var(--tb-muted, #5B7383);
    }

    /* Re-pointed at the module blue. This was #2563eb — a different blue from
       every other accent on the page, so the "you jumped here" highlight was
       the one thing on screen not speaking the module's palette. */
    .active-header-column {
        position: relative;
        box-shadow: inset 0 0 0 2px var(--po-blue, #006C9D) !important;
        background-color: var(--po-tint, #E0F2FE) !important;
    }

    .active-header-column input,
    .active-header-column .excel-readonly-cell {
        border-color: var(--po-blue, #006C9D) !important;
        background: #ffffff !important;
    }

    @media (max-width: 992px) {
        .header-find-tools {
            width: 100%;
            justify-content: flex-start;
            margin-left: 0;
        }

        .search-help-text {
            width: 100%;
        }
    }

    .table-toolbar {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        align-items: center;
        justify-content: space-between;
        padding: 12px 14px;
        border-bottom: 1px solid var(--po-line-soft, #EEF3F8);
        background: linear-gradient(180deg, #FFFFFF 0%, var(--po-paper, #F6F9FC) 100%);
        min-height: 56px;
    }

    /* One control height for every button in the toolbar, so Add / Duplicate /
       Save line up instead of each finding its own size. */
    .table-toolbar .btn {
        min-height: 36px;
        border-radius: var(--po-r-control, 10px);
        font-weight: 700;
        font-size: 12px;
    }
    .table-toolbar .btn-primary {
        background: var(--po-blue, #006C9D);
        border-color: var(--po-blue, #006C9D);
    }
    .table-toolbar .btn-primary:hover,
    .table-toolbar .btn-primary:focus {
        background: var(--po-blue-deep, #004B70);
        border-color: var(--po-blue-deep, #004B70);
    }
    .table-toolbar .btn-outline-primary {
        color: var(--po-blue, #006C9D);
        border-color: var(--po-line, #D7E3ED);
    }
    .table-toolbar .btn-outline-primary:hover:not(:disabled) {
        background: var(--po-blue, #006C9D);
        border-color: var(--po-blue, #006C9D);
        color: #fff;
    }
    .table-toolbar .section-title {
        font-size: 15px;
        font-weight: 800;
        letter-spacing: -.01em;
        color: var(--po-ink, #0B2B3C);
        margin: 0;
    }

    /* Pagination as pills, matching the module's lists. */
    .sheet-pagination .page-link {
        border-radius: var(--po-r-control, 10px) !important;
        border-color: var(--po-line, #D7E3ED);
        color: var(--po-blue, #006C9D);
        font-weight: 800;
        font-size: 12px;
    }
    .sheet-pagination .page-item.active .page-link {
        background: var(--po-blue, #006C9D);
        border-color: var(--po-blue, #006C9D);
        color: #fff;
    }

    .table-toolbar-left {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
        align-items: center;
    }

    .section-title {
        font-size: 13px;
        font-weight: 700;
        color: #0f172a;
        margin: 0;
        white-space: nowrap;
    }

    .paste-note {
        font-size: 11px;
        color: #94a3b8;
        margin: 0;
        display: none;
    }

    /* --- Row duplication controls -------------------------------------------
     * Colours are the PO Generation module's tokens, with literal fallbacks so
     * this screen does not depend on that module's theme partial being loaded.
     */
    .row-pick {
        display: inline-flex;
        align-items: center;
        margin-right: 6px;
        vertical-align: middle;
        cursor: pointer;
    }
    .row-pick .form-check-input { cursor: pointer; margin: 0; }

    /* A ticked row is tinted across its whole width, so a multi-row selection
       is readable while scrolled far to the right of a very wide sheet. */
    .excel-data-table tbody tr.row-is-selected > td {
        background: var(--po-tint, #E0F2FE) !important;
    }

    /* --- Persisted duplicate marker -----------------------------------------
     * Driven by excel_rows.duplicated_at, so it survives a refresh, pagination
     * and a different session — which a CSS-only highlight never could.
     *
     * Amber, not blue: blue already means "selected" on this grid, and a
     * duplicate is a state that wants attention rather than one the user just
     * chose. The rail is on the sticky first column, so it stays on screen no
     * matter how far right the sheet is scrolled.
     */
    /* Pale 2px rail, not 3px solid amber. With ten duplicates on screen the old
       weight read as an alarm rather than a note. */
    .excel-data-table tbody tr.row-is-duplicate > td:first-child {
        box-shadow: inset 2px 0 0 #FCD9A5;
    }

    /* A dot plus light text, no pill and no border. */
    .row-duplicate-badge {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        margin-top: 3px;
        padding: 0;
        background: transparent;
        border: 0;
        color: var(--po-warn, #B45309);
        font-size: 9px;
        font-weight: 700;
        letter-spacing: .02em;
        line-height: 1.3;
        white-space: nowrap;
    }
    .row-duplicate-badge::before {
        content: "";
        flex: 0 0 auto;
        width: 5px;
        height: 5px;
        border-radius: 50%;
        background: var(--po-warn, #F59E0B);
    }
    /* Continuation of a run: the dot and the × stay, the repeated word goes. */
    .row-duplicate-badge.is-run .row-duplicate-badge-text { display: none; }
    .row-duplicate-badge-clear {
        border: 0;
        background: transparent;
        color: inherit;
        font-size: 12px;
        line-height: 1;
        padding: 0 0 0 2px;
        cursor: pointer;
        opacity: .65;
    }
    .row-duplicate-badge-clear:hover { opacity: 1; }
    .row-duplicate-badge-clear:focus-visible {
        outline: 2px solid var(--po-warn, #F59E0B);
        outline-offset: 1px;
    }

    /* The few contract-level cells a new order normally changes. Marked, never
       blanked — the row is a full copy and the merchant edits what differs. */
    /* Soft tint plus an inset left accent, instead of a full amber border and a
       2px outer glow. Still unmistakable against its neighbours, but it no
       longer competes with the data when several duplicates are on screen. */
    /* TIER 1 — "always": a new order cannot reuse this value. Amber, the
       stronger of the two, because the copied value is wrong until changed. */
    .excel-data-table tbody td.cell-needs-update input.form-control,
    .excel-data-table tbody td.cell-needs-update .excel-readonly-cell {
        border-color: #FCD9A5 !important;
        background: #FFFBF4 !important;
        box-shadow: inset 2px 0 0 var(--po-warn, #F59E0B);
    }
    /* TIER 2 — "likely": worth a glance, often correct as copied. Deliberately
       quieter — a 1px rail and a barely-there tint — so that scanning a
       duplicated row the eye lands on the amber cells first and these second.
       Same shape, less weight: the two tiers must read as one family. */
    .excel-data-table tbody td.cell-check-value input.form-control,
    .excel-data-table tbody td.cell-check-value .excel-readonly-cell {
        border-color: #E4E7EC !important;
        background: #FCFCFD !important;
        box-shadow: inset 1px 0 0 #C4C9D2;
    }
    /* The per-row Delete, on the narrow set of rows that qualify. Sized and
       toned like the duplicate badge beside it rather than like a button, so a
       column that has to stay scannable does not gain a red block. */
    .row-delete-btn {
        display: inline-flex;
        align-items: center;
        gap: 3px;
        margin-top: 3px;
        padding: 1px 6px;
        font-size: 9px;
        font-weight: 700;
        line-height: 1.5;
        border-radius: var(--po-r-pill, 999px);
        border: 1px solid var(--po-danger-border, #FECACA);
        background: var(--po-danger-bg, #FEF2F2);
        color: var(--po-danger, #B91C1C);
        cursor: pointer;
    }

    .row-delete-btn:hover,
    .row-delete-btn:focus {
        background: var(--po-danger, #B91C1C);
        border-color: var(--po-danger, #B91C1C);
        color: #fff;
    }

    /* ---- Duplicate Rows dialog: the placement choice --------------------- */
    #duplicateRowsModal .ao-place {
        border: 0;
        padding: 0;
        margin: 0 0 12px;
    }

    #duplicateRowsModal .ao-place-option {
        display: flex;
        align-items: flex-start;
        gap: 9px;
        padding: 9px 11px;
        margin-bottom: 6px;
        border: 1px solid var(--ao-line, #D7E3ED);
        border-radius: var(--po-r-control, 10px);
        background: #fff;
        cursor: pointer;
    }

    #duplicateRowsModal .ao-place-option:has(input:checked) {
        border-color: var(--ao-blue, #006C9D);
        box-shadow: 0 0 0 3px rgba(0, 108, 157, .12);
    }

    #duplicateRowsModal .ao-place-title {
        display: block;
        font-size: 12.5px;
        font-weight: 700;
        color: var(--ao-ink, #0B2B3C);
    }

    #duplicateRowsModal .ao-place-hint {
        display: block;
        font-size: 11px;
        color: var(--ao-muted, #5B7383);
        margin-top: 1px;
    }

    #duplicateRowsModal .ao-note-inline {
        display: flex;
        align-items: flex-start;
        gap: 6px;
        margin-top: 8px;
        font-size: 11px;
        color: var(--ao-muted, #5B7383);
    }

    /* ---- Add New Order dialog -------------------------------------------
       The two tiers again, on form inputs this time. Same colours and the same
       inset-rail shape as the grid cells above, deliberately: a merchant who
       has learned "amber means I must change this" on the sheet must not have
       to learn it a second time in the dialog. Kept next to the grid rules so
       the two cannot drift apart unnoticed. */
    #duplicateRowsModal .ao-input-always {
        border-color: #FCD9A5;
        background: #FFFBF4;
        box-shadow: inset 2px 0 0 var(--po-warn, #F59E0B);
    }
    #duplicateRowsModal .ao-input-likely {
        border-color: #E4E7EC;
        background: #FCFCFD;
        box-shadow: inset 1px 0 0 #C4C9D2;
    }
    #duplicateRowsModal .ao-input:focus {
        border-color: var(--po-blue, #006C9D);
        box-shadow: 0 0 0 3px rgba(0, 108, 157, .14);
    }
    /* The module palette, imported rather than re-picked. These are the same
       tokens the PO module defines in _po-module-theme; declaring them here
       with the module's own values means this dialog stays part of that system
       instead of becoming a second one, and the fallbacks below keep it correct
       on screens where the theme partial is not loaded. */
    #duplicateRowsModal {
        --ao-blue: var(--po-blue, #006C9D);
        --ao-blue-deep: var(--po-blue-deep, #004B70);
        --ao-tint: var(--po-tint, #E0F2FE);
        --ao-ink: var(--po-ink, #0B2B3C);
        --ao-muted: var(--po-muted, #5B7383);
        --ao-line: var(--po-line, #D7E3ED);
        --ao-line-soft: var(--po-line-soft, #EEF3F8);
        --ao-paper: var(--po-paper, #F6F9FC);
    }

    /* SEARCH PANEL — tinted, bordered, visually one thing. The flat white box
       gave the controls and the results equal weight, so neither read as a
       step. */
    #duplicateRowsModal .ao-panel {
        background: var(--ao-paper);
        border: 1px solid var(--ao-line);
        border-radius: 10px;
        padding: 14px;
    }
    #duplicateRowsModal .ao-step-head {
        display: flex;
        align-items: center;
        margin-bottom: 12px;
    }
    #duplicateRowsModal .ao-step-chip {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 4px 12px 4px 4px;
        border-radius: 999px;
        background: var(--ao-tint);
        color: var(--ao-blue-deep);
        font-size: 12px;
        font-weight: 700;
    }
    #duplicateRowsModal .ao-step-num {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 22px;
        height: 22px;
        border-radius: 50%;
        background: var(--ao-blue);
        color: #fff;
        font-size: 12px;
        font-weight: 700;
        flex: 0 0 auto;
    }
    #duplicateRowsModal .ao-label {
        display: block;
        font-size: 11px;
        font-weight: 700;
        letter-spacing: .02em;
        text-transform: uppercase;
        color: var(--ao-muted);
        margin-bottom: 4px;
    }
    #duplicateRowsModal .ao-label-hint {
        text-transform: none;
        letter-spacing: 0;
        font-weight: 600;
        color: var(--ao-blue);
    }
    /* The primary field is wider AND heavier, because "which style" is the
       question this dialog actually asks. */
    #duplicateRowsModal .ao-field-primary .form-select {
        font-size: 14px;
        font-weight: 600;
        min-height: 40px;
    }
    #duplicateRowsModal .ao-actions {
        display: flex;
        gap: 8px;
        align-items: center;
        margin-top: 12px;
        padding-top: 12px;
        border-top: 1px solid var(--ao-line);
    }

    /* The count note, as guidance rather than grey body text. */
    #duplicateRowsModal .ao-note {
        display: flex;
        align-items: center;
        gap: 8px;
        margin: 12px 0 0;
        padding: 8px 12px;
        border-radius: 8px;
        background: var(--ao-tint);
        border: 1px solid var(--ao-line);
        color: var(--ao-blue-deep);
        font-size: 12px;
    }

    /* RESULTS — its own card, so the boundary between "what I asked" and "what
       came back" is visible. */
    #duplicateRowsModal .ao-results-card {
        margin-top: 12px;
        border: 1px solid var(--ao-line);
        border-radius: 10px;
        overflow: hidden;
    }
    /* Scrolls on its own, so the dialog keeps a stable height whether a search
       returns two rows or twenty-five. */
    #duplicateRowsModal .ao-results {
        max-height: 320px;
        overflow-y: auto;
    }
    #duplicateRowsModal .ao-results thead th {
        position: sticky;
        top: 0;
        z-index: 1;
        background: var(--ao-line-soft);
        border-bottom: 1px solid var(--ao-line);
        font-size: 10px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .04em;
        color: var(--ao-muted);
        white-space: nowrap;
    }
    #duplicateRowsModal .ao-results tbody tr:nth-child(even) > td { background: #FBFCFE; }
    #duplicateRowsModal .ao-results tbody tr:hover > td { background: var(--ao-tint); }
    #duplicateRowsModal .ao-results td { font-size: 12px; color: var(--ao-ink); }
    #duplicateRowsModal .ao-col-row { width: 64px; }
    #duplicateRowsModal .ao-col-action { width: 118px; }
    /* Row and contract numbers line up digit-for-digit, which is how a merchant
       scans a column of codes. */
    #duplicateRowsModal .ao-num {
        font-variant-numeric: tabular-nums;
        text-align: right;
        color: var(--ao-muted);
    }
    #duplicateRowsModal .ao-contract { font-variant-numeric: tabular-nums; }
    /* One action per row, so it is filled rather than an outline competing with
       five columns of text. */
    #duplicateRowsModal .ao-pick {
        background: var(--ao-blue);
        border-color: var(--ao-blue);
        color: #fff;
        font-size: 11px;
        font-weight: 600;
        white-space: nowrap;
    }
    #duplicateRowsModal .ao-pick:hover,
    #duplicateRowsModal .ao-pick:focus {
        background: var(--ao-blue-deep);
        border-color: var(--ao-blue-deep);
        color: #fff;
    }
    #duplicateRowsModal .ao-empty > td { background: #fff !important; }
    #duplicateRowsModal .ao-empty-state {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 8px;
        padding: 28px 12px;
        color: var(--ao-muted);
        font-size: 12px;
    }
    #duplicateRowsModal .ao-empty-state .bi { font-size: 20px; opacity: .5; }

    /* Tom Select, sized to match the Bootstrap controls beside it rather than
       its own default. Same library and version the Booking Preview filters
       use; if the CDN fails these rules simply style a native <select>. */
    #duplicateRowsModal .ts-wrapper { margin: 0; }
    #duplicateRowsModal .ts-control {
        min-height: 31px;
        border-color: var(--ao-line);
        border-radius: 6px;
        font-size: 12px;
        padding: 4px 8px;
    }
    #duplicateRowsModal .ao-field-primary .ts-control {
        min-height: 40px;
        font-size: 14px;
        font-weight: 600;
    }
    #duplicateRowsModal .ts-wrapper.focus .ts-control {
        border-color: var(--ao-blue);
        box-shadow: 0 0 0 3px rgba(0, 108, 157, .14);
    }
    #duplicateRowsModal .ts-dropdown { font-size: 12px; border-color: var(--ao-line); }
    #duplicateRowsModal .ts-dropdown .active { background: var(--ao-tint); color: var(--ao-blue-deep); }
    #duplicateRowsModal .ts-dropdown .no-results { padding: 8px 12px; color: var(--ao-muted); }
    #duplicateRowsModal .ao-source-strip {
        background: #F8FAFC;
        border: 1px solid #EEF0F3;
        border-radius: 6px;
        padding: 8px 10px;
        font-size: 12px;
        margin-bottom: 12px;
    }
    #duplicateRowsModal .ao-group { margin-top: 14px; }
    #duplicateRowsModal .ao-group-head {
        display: flex;
        align-items: center;
        gap: 6px;
        font-size: 12px;
        font-weight: 600;
        color: #334155;
        margin-bottom: 6px;
    }
    #duplicateRowsModal .ao-tier-dot {
        width: 8px; height: 8px; border-radius: 2px; flex: 0 0 auto;
    }
    #duplicateRowsModal .ao-tier-always { background: var(--po-warn, #F59E0B); }
    #duplicateRowsModal .ao-tier-likely { background: #C4C9D2; }

    /* Focus still wins, so editing the cell reads normally. */
    .excel-data-table tbody td.cell-needs-update input.form-control:focus,
    .excel-data-table tbody td.cell-check-value input.form-control:focus {
        border-color: var(--po-blue, #006C9D) !important;
        box-shadow: 0 0 0 3px rgba(0, 108, 157, .14);
    }

    .custom-success-alert {
        border: 0;
        border-radius: 12px;
        background: linear-gradient(135deg, #dcfce7, #bbf7d0);
        color: #166534;
        box-shadow: 0 8px 24px rgba(22, 101, 52, 0.12);
    }

    .draft-modal-overlay {
        position: fixed;
        inset: 0;
        background: rgba(15, 23, 42, 0.45);
        display: none;
        align-items: center;
        justify-content: center;
        z-index: 9999;
        padding: 20px;
        backdrop-filter: blur(2px);
    }

    .draft-modal-overlay.show {
        display: flex;
    }

    .draft-modal-box {
        width: 100%;
        max-width: 520px;
        border-radius: 20px;
        overflow: hidden;
        background: #ffffff;
        box-shadow: 0 25px 70px rgba(15, 23, 42, 0.30);
        animation: modalPop .22s ease-out;
    }

    @keyframes modalPop {
        from {
            opacity: 0;
            transform: translateY(10px) scale(.98);
        }
        to {
            opacity: 1;
            transform: translateY(0) scale(1);
        }
    }

    .draft-modal-header {
        background: linear-gradient(135deg, #1d4ed8, #0f766e);
        color: #fff;
        padding: 18px 22px;
    }

    .draft-modal-title {
        font-size: 20px;
        font-weight: 700;
        margin: 0;
    }

    .draft-modal-subtitle {
        margin: 6px 0 0;
        font-size: 13px;
        opacity: .92;
    }

    .draft-modal-body {
        padding: 22px;
    }

    .draft-modal-body p {
        margin-bottom: 10px;
        color: #334155;
        font-size: 14px;
    }

    .draft-modal-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        justify-content: flex-end;
        padding: 0 22px 22px;
    }

    .btn-soft-secondary {
        background: #f1f5f9;
        color: #334155;
        border: 1px solid #cbd5e1;
    }

    .btn-soft-secondary:hover {
        background: #e2e8f0;
        color: #0f172a;
    }

    .btn-gradient-primary {
        background: linear-gradient(135deg, #2563eb, #0f766e);
        border: none;
        color: #fff;
    }

    .btn-gradient-primary:hover {
        color: #fff;
        opacity: .95;
    }

    .btn-gradient-warning {
        background: linear-gradient(135deg, #f59e0b, #f97316);
        border: none;
        color: #fff;
    }

    .btn-gradient-warning:hover {
        color: #fff;
        opacity: .95;
    }

    @media (max-width: 1200px) {
        .order-summary-grid {
            grid-template-columns: repeat(2, minmax(150px, 1fr));
        }
    }

    @media (max-width: 768px) {
        .order-summary-grid {
            grid-template-columns: 1fr;
        }

        .draft-modal-actions {
            flex-direction: column;
        }

        .draft-modal-actions .btn {
            width: 100%;
        }
    }

    .po-row-locked { background: #fff7ed !important; }
    .file-row-locked { background: #f8fafc !important; }
    .po-row-lock-badge,
    .file-row-lock-badge { display: inline-flex; align-items: center; gap: 4px; margin-top: 4px; padding: 3px 7px; border-radius: 999px; background: #fee2e2; color: #b91c1c; font-size: 10px; font-weight: 800; }
    .file-row-lock-badge { background: #e0f2fe; color: #075985; }
</style>

<div class="container-fluid">
    @if(session('success'))
        <div id="pageSuccessAlert" class="alert custom-success-alert py-2 px-3 mb-3">
            {{ session('success') }}
        </div>
    @endif

    @if(session('warning'))
        <div class="alert alert-warning py-2 px-3 mb-3 rounded-3">
            {{ session('warning') }}
        </div>
    @endif

    @if($isFileLockedForUser ?? false)
        <div class="alert alert-warning py-2 px-3 mb-3 rounded-3">
            <div class="fw-bold"><i class="bi bi-lock-fill me-1" aria-hidden="true"></i>This workspace file is locked for your user or role.</div>
            <div class="small">You can view this file, but you cannot edit, update, paste changes, or add rows until admin unlocks it for you.</div>
            @if(!empty($fileLockInfo['reason'] ?? ''))
                <div class="small mt-1"><strong>Reason:</strong> {{ $fileLockInfo['reason'] }}</div>
            @endif
        </div>
    @endif

    {{-- A second, independent reason this file can be read-only: it belongs to
         another buyer. Shown separately from the admin lock above because the
         cause and the remedy are different ones. --}}
    @if($isBuyerLockedForUser ?? false)
        <div class="alert alert-warning py-2 px-3 mb-3 rounded-3">
            <div class="fw-bold"><i class="bi bi-bag-check me-1" aria-hidden="true"></i>This file belongs to another buyer.</div>
            <div class="small">You can view it in full, but only the buyer's own team can edit cells or add rows.</div>
        </div>
    @endif

    @if(!empty($highlightBatchId) && isset($highlightedCellKeys) && $highlightedCellKeys->count() > 0)
    <div class="alert notification-highlight-alert py-2 px-3 mb-3">
        Merchant updated {{ $highlightedCellKeys->count() }} cell(s). Highlighted cells show the specific changes from this notification.
    </div>
    @endif

    @if($canAddRow ?? false)
        <form method="POST" action="{{ route('uploaded-files.rows.store', $excelFile->id) }}" id="add-row-form" class="d-none">
            @csrf
        </form>

        {{-- The Add New Order dialog. Placed HERE, outside the worksheet form,
             because it carries a form of its own and nesting one form inside
             another is invalid HTML — the browser drops the inner one and the
             dialog would silently post nothing. --}}
        @include('shared.excel-files._add-order-modal')

        {{-- Row ids are injected as hidden inputs just before submit, so one
             form serves the multi-select toolbar action. --}}
        <form method="POST" action="{{ route('uploaded-files.rows.duplicate', $excelFile->id) }}" id="duplicate-rows-form" class="d-none">
            @csrf
        </form>

        {{-- Duplicating a whole style. Carries the three style FILTER VALUES,
             never row ids: the server re-resolves the group itself, so this
             form cannot be edited to reach rows outside the style on screen.
             Rendered only when all three filters are set — which is the same
             condition that shows the button. --}}
        @if(! empty($styleMatchValues))
            <form method="POST" action="{{ route('uploaded-files.rows.duplicate_matching', $excelFile->id) }}" id="duplicate-matching-form" class="d-none">
                @csrf
                @foreach($styleMatchValues as $styleHeaderKey => $styleValue)
                    <input type="hidden" name="filters[{{ $styleMatchHeaderIds[$styleHeaderKey] ?? '' }}]" value="{{ $styleValue }}">
                @endforeach
            </form>
        @endif

        {{-- The action is rewritten per row before submit, so one form serves
             every "clear duplicate marker" button on the page. --}}
        {{-- One form for every row's Delete, action rewritten per click — the
             same approach the dismiss-badge form takes, so 150 rows do not each
             render their own form. --}}
        <form method="POST" id="delete-row-form" class="d-none">
            @csrf
            @method('DELETE')
        </form>

        {{-- Delete Selected. POST rather than DELETE because it carries a list;
             the action itself is the same one the per-row button performs. --}}
        <form method="POST" id="delete-selected-form" class="d-none"
              action="{{ route('uploaded-files.rows.destroy_many', $excelFile->id) }}">
            @csrf
        </form>

        <form method="POST" id="dismiss-duplicate-form" class="d-none">
            @csrf
            @method('PATCH')
        </form>
    @endif

    <div class="order-summary-card mb-2">
        <div class="order-summary-top">
            <div class="order-summary-meta">
                <span class="fw-bold text-slate-900 me-1" style="font-size:13px;">Order Information</span>
                {{-- Batch No and Row count were removed from this bar: both are
                     internal upload detail that told an end user nothing about
                     the order. The columns are untouched and still read
                     elsewhere — only these two chips are gone. --}}
                <span class="order-meta-chip order-meta-chip-status"><span class="order-meta-chip-label">Status</span><span class="order-meta-chip-value">{{ ucfirst($excelFile->status ?? 'pending') }}</span></span>
                @if($excelFile->is_locked)
                    <span class="order-meta-chip order-meta-chip-locked"><i class="bi bi-lock-fill me-1" aria-hidden="true"></i>{{ $fileLockInfo['summary'] ?? $excelFile->lockScopeLabel() }}</span>
                @endif

                {{-- The signed-in user's OWN department only. Admin/Management
                     own no required columns, so this is simply absent for them
                     and no cross-department figure is ever rendered here. --}}
                @if(!empty($myDepartmentProgress))
                    @php
                        $dept = $myDepartmentProgress;
                        $deptPercent = min(100, (float) $dept['percent']);
                    @endphp
                    <span class="order-dept-progress" role="group"
                          aria-label="Your department progress on this order">
                        <span class="order-dept-name">{{ $dept['label'] }}</span>
                        <span class="order-dept-count">{{ number_format($dept['filled']) }}/{{ number_format($dept['expected']) }} required fields</span>
                        <span class="order-dept-bar" aria-hidden="true">
                            <span class="order-dept-bar-fill" style="width: {{ $deptPercent }}%;"></span>
                        </span>
                        <span class="order-dept-percent">{{ $dept['percent'] }}%</span>
                        <span class="badge {{ \App\Services\DepartmentActivityService::statusTone($dept['status']) }}">
                            {{ \App\Services\DepartmentActivityService::statusLabel($dept['status']) }}
                        </span>
                    </span>
                @endif
            </div>
            <div class="d-flex gap-2 flex-shrink-0 d-print-none">
                <a href="{{ url()->previous() }}" class="btn btn-outline-secondary btn-sm" style="min-height:32px;font-size:12px;"><i class="bi bi-arrow-left" aria-hidden="true"></i><span class="ms-1">Back</span></a>

                {{-- Taking the whole original workbook away is wider than
                     reading a sheet on screen, so it follows download()'s own
                     gate rather than being offered to anyone who can open the
                     page. --}}
                @if($canDownloadFile ?? true)
                    <a href="{{ route('uploaded-files.download', $excelFile->id) }}"
                       class="btn btn-outline-secondary btn-sm" style="min-height:32px;font-size:12px;"
                       title="Download the file exactly as it was uploaded">
                        <i class="bi bi-download" aria-hidden="true"></i><span class="ms-1">Original</span>
                    </a>
                @endif

                {{-- The panel below names staff and shows the edit timeline, so
                     it is withheld on a locked file. The controller does not
                     load $activityLog at all in that case — this only hides the
                     button that would open an already-empty panel. --}}
                @if(($canViewHistory ?? true) && ($activityTotal ?? 0) > 0)
                    <button type="button" class="btn btn-outline-secondary btn-sm" style="min-height:32px;font-size:12px;"
                            data-bs-toggle="collapse" data-bs-target="#fileActivityLog"
                            aria-expanded="false" aria-controls="fileActivityLog">
                        <i class="bi bi-clock-history" aria-hidden="true"></i><span class="ms-1">History ({{ $activityTotal }})</span>
                    </button>
                @endif

                {{-- Delete is deliberately absent here. It now lives only in
                     the Existing Files list, so removing a whole order is a
                     decision taken from the list of orders rather than a red
                     button sitting beside Back on the sheet you are editing.
                     $canDeleteFile is still resolved by the controller and
                     destroy() still enforces the same three checks. --}}
            </div>
        </div>
        <div class="order-summary-grid">
            @foreach($orderInfo as $label => $box)
                @php
                    $box = is_array($box) ? $box + $blankOrderInfoBox : $blankOrderInfoBox;
                    $isVaried = $box['header_id'] !== null && count($box['options']) > 1;
                    $boxId = 'orderInfoFilter' . \Illuminate\Support\Str::slug($label);
                @endphp

                <div class="order-info-box">
                    <div class="order-info-label">{{ $label }}</div>

                    @if($isVaried)
                        {{-- The file carries several of these, so naming one of
                             them here would describe a fraction of the rows.
                             The count is the honest summary; the dropdown turns
                             it into the way to narrow the sheet to one. --}}
                        <div class="order-info-count">
                            <span class="order-info-count-figure">{{ number_format($box['distinct']) }}</span>
                            <span class="order-info-count-noun">{{ $box['noun'] }}</span>
                        </div>

                        {{-- The counts ride as data rather than inside the
                             option text, so the dropdown can render the value
                             and its row count as two separate things. The
                             option label still carries "(77)" for the case
                             where Tom Select does not load and the browser
                             draws a plain <select>. --}}
                        <select class="form-select form-select-sm order-info-filter"
                                id="{{ $boxId }}"
                                data-order-info-filter
                                data-header-id="{{ $box['header_id'] }}"
                                data-order-info-counts="{{ json_encode(collect($box['options'])->pluck('count', 'value')) }}"
                                aria-label="Filter the sheet by {{ $label }}">
                            <option value="">All {{ $box['noun'] }}</option>
                            @foreach($box['options'] as $option)
                                <option value="{{ $option['value'] }}"
                                        @selected($box['filter_value'] === $option['value'])>
                                    {{ $option['value'] }} ({{ number_format($option['count']) }})
                                </option>
                            @endforeach
                        </select>
                    @else
                        <div class="order-info-value" title="{{ $box['value'] ?: '-' }}">{{ $box['value'] ?: '-' }}</div>
                    @endif
                </div>
            @endforeach
        </div>

        {{-- Every cell edit has been recorded since the workspace was built.
             Six departments write to the same sheet, so this answers the
             question that follows a surprising value: who changed it. --}}
        @if(($activityTotal ?? 0) > 0)
            @if($canViewHistory ?? true)
            <div class="collapse mt-3" id="fileActivityLog">
                <div class="gx-activity">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <span class="fw-semibold" style="font-size:13px;">Change history</span>
                        <span class="text-muted" style="font-size:11px;">
                            Showing the latest {{ min(50, $activityTotal) }} of {{ number_format($activityTotal) }}
                        </span>
                    </div>

                    <div class="gx-activity-scroll">
                        <table class="table table-sm mb-0" style="font-size:12px;">
                            <thead>
                                <tr class="text-muted text-uppercase" style="font-size:10px;">
                                    <th>When</th><th>Who</th><th>Field</th><th>Change</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($activityLog as $entry)
                                    @php
                                        $isEdit = in_array($entry->action, ['updated', 'created'], true);
                                        $from = trim((string) $entry->old_value);
                                        $to = trim((string) $entry->new_value);
                                    @endphp
                                    <tr>
                                        <td class="text-nowrap text-muted">{{ $entry->created_at?->diffForHumans() }}</td>
                                        <td class="text-nowrap">{{ $entry->user->name ?? 'System' }}</td>
                                        <td>{{ $entry->header->header_name ?? ucfirst(str_replace('_', ' ', $entry->action)) }}</td>
                                        <td>
                                            @if($isEdit)
                                                @if($from !== '')
                                                    <span class="text-danger text-decoration-line-through">{{ Str::limit($from, 28) }}</span>
                                                    <i class="bi bi-arrow-right mx-1 text-muted" aria-hidden="true"></i>
                                                @endif
                                                <span class="text-success fw-semibold">{{ $to !== '' ? Str::limit($to, 28) : '(cleared)' }}</span>
                                            @else
                                                <span class="badge bg-secondary-subtle text-secondary">{{ str_replace('_', ' ', $entry->action) }}</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            @endif
        @endif
    </div>

    {{-- autocomplete="off" here as well as on each cell. The form-level flag is
         the layer that covers any input added to this sheet later without one
         of its own — a new cell input is exactly the thing a browser would
         start refilling silently. --}}
    <form method="POST" action="{{ route('uploaded-files.update', $excelFile->id) }}{{ $updateQueryString ? '?' . $updateQueryString : '' }}" id="excelFileForm" autocomplete="off">
        @csrf
        @method('PUT')

        {{-- How many cell inputs this form intends to send. Set by JS at submit
             time and deliberately placed FIRST, before the sheet, so it always
             survives PHP's max_input_vars truncation. The server compares it
             with what actually arrived and refuses to report success on a
             short payload — the failure that used to silently lose edits. --}}
        <input type="hidden" name="cells_count" id="cellsCountField" value="">

        <div class="excel-work-card">
            <div class="table-toolbar">
                <div class="table-toolbar-left">
                    <h4 class="section-title">Excel Work Sheet</h4>
                    <p class="paste-note">Multiple row/column paste supported.</p>
                </div>

                <div class="d-flex gap-2 flex-wrap align-items-center">
                    @if($canAddRow ?? false)
                        <button type="button" id="addRowButton" class="btn btn-outline-primary btn-sm">
                            Add New Row
                        </button>
                        {{-- THE duplicate action, and now the only general one.
                             "Add New Order" used to sit here as a second entry
                             point: a two-step dialog whose first step made the
                             merchant re-find, through four filter boxes, a row
                             the grid already lets them tick — and which then
                             produced exactly what this button produces. Its
                             genuinely useful half, editing the key fields before
                             the copy is written, is now the "Edit key fields
                             first" choice in this dialog, and is reachable for
                             any ticked row rather than only a filter-found one.

                             Disabled until something is ticked; the count comes
                             from the selection so the dialog can name it. --}}
                        {{-- HIDDEN until something is ticked, not disabled. A
                             greyed "(0)" is a control the merchant has to read
                             and dismiss on every page load, for an action that
                             is not available yet; the toolbar is shorter and
                             says more when it only shows what can be done. --}}
                        <button type="button" id="duplicateSelectedButton" class="btn btn-primary btn-sm d-none"
                                data-bs-toggle="modal" data-bs-target="#duplicateRowsModal">
                            <i class="bi bi-copy me-1" aria-hidden="true"></i>Duplicate Selected (<span id="duplicateSelectedCount">0</span>)
                        </button>
                        {{-- Appears with its sibling, and counts only the rows
                             that can ACTUALLY go — the selection is made for
                             duplication, so it routinely holds rows nothing may
                             delete. Naming the real number here means the
                             confirm and the result agree with the button. --}}
                        <button type="button" id="deleteSelectedButton" class="btn btn-outline-danger btn-sm d-none">
                            <i class="bi bi-trash me-1" aria-hidden="true"></i>Delete Selected (<span id="deleteSelectedCount">0</span>)
                        </button>
                        {{-- Filtering is server-side, so a page holds at most
                             per_page rows of a possibly larger result set.
                             Ticking "all visible" therefore selects the PAGE;
                             this offers the rest of the match. --}}
                        <button type="button" id="selectAllMatchesButton" class="btn btn-link btn-sm p-0 ms-1 d-none">
                            Select all <span id="selectAllMatchesCount">0</span> matching rows
                        </button>

                        {{-- Duplicating a whole style. Offered only once all
                             three style filters (Buyer, Style, Season) are set,
                             because a partial style key is how a group quietly
                             grows to include another season's rows.

                             The count is the EXACT-match count from the server,
                             not the number of rows on screen — the ordinary
                             filter matches substrings, this action does not.
                             Where the two differ the note below says so. --}}
                        @if(! empty($styleMatchValues) && count($styleMatchRowIds) > 0)
                            <button type="button"
                                    id="duplicateMatchingButton"
                                    class="btn btn-outline-primary btn-sm"
                                    data-count="{{ count($styleMatchRowIds) }}"
                                    data-style-label="{{ implode(' / ', $styleMatchValues) }}">
                                <i class="bi bi-layers me-1" aria-hidden="true"></i>Duplicate All {{ count($styleMatchRowIds) }} Matching {{ Str::plural('Row', count($styleMatchRowIds)) }}
                            </button>
                        @endif
                    @endif

                    @if(count($editableHeaderIds) > 0)
                        <button type="submit" class="btn btn-primary btn-sm" id="saveChangesButton">
                            Save Changes
                        </button>
                    @endif
                </div>
            </div>

            {{-- The gap between what the screen shows and what the bulk action
                 would touch, stated before the merchant presses the button.

                 The column filter matches SUBSTRINGS ("ABC" finds "ABC-2");
                 the style duplicate matches EXACTLY. So the filtered view can
                 legitimately be wider than the group. Silence here would let a
                 merchant read "37 rows" off the screen and get 24. --}}
            @if(($canAddRow ?? false) && ! empty($styleMatchValues) && count($styleMatchRowIds) > 0 && count($styleMatchRowIds) !== count($allMatchingRowIds ?? []))
                <div class="alert alert-light border small d-flex align-items-start gap-2 mt-2 mb-0 py-2">
                    <i class="bi bi-info-circle mt-1" aria-hidden="true"></i>
                    <span>
                        The filter shows <strong>{{ count($allMatchingRowIds ?? []) }}</strong>
                        {{ Str::plural('row', count($allMatchingRowIds ?? [])) }}, but only
                        <strong>{{ count($styleMatchRowIds) }}</strong> match
                        <strong>{{ implode(' / ', $styleMatchValues) }}</strong> exactly.
                        Duplicating the style copies those {{ count($styleMatchRowIds) }}.
                        The column filter matches partial text; this action does not.
                    </span>
                </div>
            @endif

            <div class="server-search-bar">
                <div class="server-search-left">
                    <input
                        type="text"
                        id="globalDocSearch"
                        class="form-control form-control-sm server-search-input"
                        value="{{ $globalSearchValue }}"
                        placeholder="Search entire document, then press Enter"
                        autocomplete="off"
                    >
                    {{-- Solid rather than outlined: it is the one action on
                         this bar that answers the question the user came here
                         with, and it sat at the same weight as Clear. --}}
                    <button type="button" class="btn btn-primary btn-sm" id="applySheetSearchButton">
                        Search
                    </button>
                    @if($globalSearchValue || count($filterValues))
                        <a href="{{ route('uploaded-files.show', $excelFile->id) }}?per_page={{ $perPage }}" class="btn btn-outline-secondary btn-sm">
                            Clear
                        </a>
                    @endif

                    {{-- Shown by sheet-range-select.js only while a block of two
                         or more cells is selected. Drag-to-select is not a
                         discoverable gesture, so it says what to press. --}}
                    <span class="sheet-select-status" data-cell-select-status hidden></span>
                </div>
                <div class="header-find-tools">
                    <span class="header-find-label">Header Finder</span>
                    {{-- One control, not two. This used to be a text box with
                         its own datalist AND this dropdown, each able to jump
                         on its own. They were not a combo box: the text box
                         never narrowed the dropdown. Worse, the Find handler
                         read `selected || typed`, and the dropdown's value was
                         never cleared — so after picking once from the list,
                         typing a different header and pressing Find silently
                         took you back to the dropdown's stale choice. A single
                         searchable dropdown is both halves of what those two
                         boxes were splitting, and the precedence bug cannot be
                         written again. Selecting jumps, so Find is gone too. --}}
                    <select id="headerJumpSelect" class="form-select form-select-sm header-find-select"
                            aria-label="Find a column by name">
                        <option value="">Search headers…</option>
                        @foreach($headers as $header)
                            <option value="{{ $header->id }}">{{ $header->header_name }} — {{ ucfirst(str_replace('_', ' ', optional($header->ownerRole)->name ?? 'N/A')) }}</option>
                        @endforeach
                    </select>
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="jumpToMyColumnsButton">My Columns</button>
                </div>

                <span class="search-help-text">All {{ $excelFile->total_rows ?: $rows->total() }} rows</span>
            </div>

            {{-- Per-buyer column view: columns this buyer does not use are
                 hidden (Admin > Buyers > Workspace Columns). --}}
            @if(($hiddenColumnCount ?? 0) > 0)
                <div class="alert alert-info py-2 px-3 mt-2 mb-0 d-flex flex-wrap align-items-center gap-2 small">
                    <i class="bi bi-layout-three-columns" aria-hidden="true"></i>
                    @if($showAllColumns)
                        <span>Showing all {{ $totalColumnCount }} columns.</span>
                        <a href="{{ request()->fullUrlWithQuery(['columns' => 'buyer']) }}" class="btn btn-sm btn-outline-primary ms-auto"><i class="bi bi-funnel me-1" aria-hidden="true"></i>Show {{ $excelFile->buyer?->buyer_name ?? 'Buyer' }} Columns Only</a>
                    @else
                        <span>Showing the {{ $totalColumnCount - $hiddenColumnCount }} columns {{ $excelFile->buyer?->buyer_name ?? 'this buyer' }} uses. {{ $hiddenColumnCount }} other columns are hidden.</span>
                        <a href="{{ request()->fullUrlWithQuery(['columns' => 'all']) }}" class="btn btn-sm btn-outline-primary ms-auto"><i class="bi bi-eye me-1" aria-hidden="true"></i>Show All Columns</a>
                    @endif
                </div>
            @endif

            <div class="excel-table-wrap">
                {{-- data-cell-select opts this table into drag/shift-click range
                     selection and Ctrl+C copy (resources/js/modules/sheet-range-select.js).
                     Selection and copy only — that module never writes a cell. --}}
                <table class="table table-bordered excel-data-table" id="excelDataTable" data-cell-select>
                    <thead>
                        <tr>
                            {{-- The select-all checkbox lives INSIDE the existing
                                 Row header, not in a new column. The grid's JS
                                 addresses cells positionally (data-col-index,
                                 editableGrid[row][col]) and the empty-state uses
                                 count($headers) + 1, so inserting a column would
                                 shift every index and break paste and filtering. --}}
                            <th>
                                @if($canAddRow ?? false)
                                    <label class="row-pick row-pick-all" title="Select all rows on this page">
                                        <input type="checkbox" id="rowSelectAll" class="form-check-input">
                                    </label>
                                @endif
                                Row
                            </th>
                            @foreach($headers as $header)
                                @php
                                    $isHeaderCalculated = in_array($header->id, $calculatedHeaderIds ?? [], true)
                                        || in_array($header->header_key, $calculatedHeaderKeys ?? [], true);
                                    $roleClass = $headerRoleClass($header);
                                    $roleLabel = ucfirst(str_replace('_', ' ', optional($header->ownerRole)->name ?? 'N/A'));
                                    $canEditHeaderColumn = in_array($header->id, $editableHeaderIds, true) && !($isFileLockedForUser ?? false) && !($isBuyerLockedForUser ?? false) && !$isHeaderCalculated;
                                @endphp
                                <th
                                    class="role-column {{ $roleClass }} {{ $isHeaderCalculated ? 'formula-header-cell' : '' }}"
                                    data-header-id="{{ $header->id }}"
                                    data-header-key="{{ $header->header_key }}"
                                    data-role-name="{{ optional($header->ownerRole)->name ?? '' }}"
                                    data-value-mode="{{ $header->value_mode ?? 'input' }}"
                                    data-col-index="{{ $loop->index }}"
                                    data-can-edit="{{ $canEditHeaderColumn ? '1' : '0' }}"
                                >
                                    <div class="header-title">{{ $header->header_name }}</div>
                                    <div class="header-meta">
                                        <span class="role-chip">{{ $roleLabel }}</span>
                                        @if($isHeaderCalculated)
                                            <span class="formula-chip">Auto</span>
                                        @endif
                                    </div>
                                </th>
                            @endforeach
                        </tr>

                        <tr class="filter-row">
                            <th>
                                <input type="text" class="form-control form-control-sm filter-input" placeholder="Row + Enter" data-column="0" data-filter-key="__row" value="{{ $filterValues['__row'] ?? '' }}" autocomplete="off">
                            </th>
                            @foreach($headers as $header)
                                @php
                                    $isHeaderCalculated = in_array($header->id, $calculatedHeaderIds ?? [], true)
                                        || in_array($header->header_key, $calculatedHeaderKeys ?? [], true);
                                    $roleClass = $headerRoleClass($header);
                                @endphp
                                <th
                                    class="role-column {{ $roleClass }} {{ $isHeaderCalculated ? 'formula-header-cell' : '' }}"
                                    data-header-id="{{ $header->id }}"
                                    data-col-index="{{ $loop->index }}"
                                >
                                    <input
                                        type="text"
                                        class="form-control form-control-sm filter-input"
                                        {{-- Says how it submits: typing alone no longer reloads the sheet. --}}
                                        placeholder="Search + Enter"
                                        data-column="{{ $loop->index + 1 }}"
                                        data-filter-key="{{ $header->id }}"
                                        value="{{ $filterValues[$header->id] ?? '' }}"
                                        autocomplete="off"
                                    >
                                </th>
                            @endforeach
                        </tr>
                    </thead>

                    <tbody>
                        {{-- Tracks the previous row's source so a run of copies
                             from one source shows its label only once. --}}
                        @php
                            $previousDuplicateSourceId = null;
                        @endphp
                        @forelse($rows as $row)
                            @php
                                $cellsByHeaderId = $row->cells->keyBy('header_id');
                                $rowLockedInfo = ($lockedRowInfo ?? collect())->get($row->id);
                                $isPoRowLocked = ($lockedRowIds ?? collect())->has($row->id);
                            @endphp
                            @php
                                // Persisted, so the marker survives refresh and
                                // pagination — the whole point of the migration.
                                $isFlaggedDuplicate = $row->duplicated_at !== null;

                                // RUN COLLAPSING. Ten copies of the same source
                                // used to stack ten identical badges, which read
                                // as noise rather than information. Only the
                                // first of a consecutive run carries the text;
                                // the rest keep the rail and the tooltip, so
                                // none of them becomes unidentifiable.
                                $isRunContinuation = $isFlaggedDuplicate
                                    && $previousDuplicateSourceId !== null
                                    && $previousDuplicateSourceId === $row->duplicated_from_row_id;

                                $previousDuplicateSourceId = $isFlaggedDuplicate
                                    ? $row->duplicated_from_row_id
                                    : null;
                            @endphp
                            {{-- data-row-number so script that needs the number
                                 reads it from the row itself. Scraping it out
                                 of the first cell's text does not work: that
                                 cell also carries the duplicate badge, the lock
                                 badges and now the Delete button, so the number
                                 is not the only text in it. --}}
                            <tr data-row-id="{{ $row->id }}" data-row-number="{{ $row->row_number }}" class="{{ trim(($isFileLockedForUser ?? false ? 'file-row-locked ' : '') . ($isPoRowLocked ? 'po-row-locked ' : '') . ($isFlaggedDuplicate ? 'row-is-duplicate ' : '') . ($isRunContinuation ? 'row-duplicate-run' : '')) }}">
                                <td>
                                    @if($canAddRow ?? false)
                                        <label class="row-pick" title="Select row {{ $row->row_number }} for duplication">
                                            <input type="checkbox" class="form-check-input row-select" value="{{ $row->id }}">
                                        </label>
                                    @endif
                                    {{ $row->row_number }}
                                    {{-- No per-row Duplicate button. It repeated on
                                         every one of 50+ rows for an action used
                                         occasionally, which read as noise in the
                                         column that has to stay scannable. The
                                         single-row entry point is now "Add New
                                         Order" in the toolbar; the checkbox here
                                         still feeds "Duplicate Selected". --}}
                                    @if($isFlaggedDuplicate)
                                        {{-- Read from the database, not from a
                                             transient CSS class, so it is still
                                             here after a refresh. A continuation
                                             row keeps the tooltip and the rail
                                             but drops the repeated label. --}}
                                        <div class="row-duplicate-badge {{ $isRunContinuation ? 'is-run' : '' }}"
                                             title="Created by duplicating row {{ optional($row->duplicatedFrom)->row_number ?? '(source removed)' }} on {{ $row->duplicated_at->format('d M Y H:i') }}">
                                            <span class="row-duplicate-badge-text">Duplicated</span>
                                            @if($canAddRow ?? false)
                                                <button type="button"
                                                        class="row-duplicate-badge-clear js-dismiss-duplicate"
                                                        data-row-id="{{ $row->id }}"
                                                        data-row-number="{{ $row->row_number }}"
                                                        aria-label="Clear duplicate marker for row {{ $row->row_number }}">&times;</button>
                                            @endif
                                        </div>
                                    @endif
                                    {{-- Shown ONLY on a row that may actually be
                                         deleted, which is the narrow set this
                                         action exists for: one just added and
                                         not yet worked on or depended upon. A
                                         disabled button on every one of 150
                                         rows would be exactly the noise the
                                         note above removed the per-row
                                         Duplicate button for — and here the
                                         button appearing is itself the signal
                                         that this is a row you just added.

                                         Membership only: $deletableRowIds was
                                         computed in one batch by
                                         ExcelRow::deletableIdsFor(), the same
                                         method deleteRow() refuses on, so this
                                         markup decides nothing. --}}
                                    @if(isset($deletableRowIds[$row->id]))
                                        <button type="button"
                                                class="row-delete-btn js-delete-row"
                                                data-row-id="{{ $row->id }}"
                                                data-row-number="{{ $row->row_number }}"
                                                title="Delete row {{ $row->row_number }} — it has not been edited and nothing uses it">
                                            <i class="bi bi-trash" aria-hidden="true"></i><span>Delete</span>
                                        </button>
                                    @endif
                                    @if($isFileLockedForUser ?? false)
                                        <div class="file-row-lock-badge" title="This workspace file is locked by admin">
                                            <i class="bi bi-lock-fill" aria-hidden="true"></i> File Locked
                                        </div>
                                    @endif
                                    @if($isPoRowLocked)
                                        <div class="po-row-lock-badge" title="PO {{ $rowLockedInfo['po_no'] ?? '' }} locked{{ !empty($rowLockedInfo['reason'] ?? '') ? ': ' . $rowLockedInfo['reason'] : '' }}">
                                            <i class="bi bi-lock-fill" aria-hidden="true"></i> PO Locked
                                        </div>
                                    @endif
                                </td>

                               @foreach($headers as $header)
                                @php
                                    $cell = $cellsByHeaderId->get($header->id);
                                    $value = $cell->value ?? '';
                                    $isCalculated = in_array($header->id, $calculatedHeaderIds ?? [], true)
                                        || in_array($header->header_key, $calculatedHeaderKeys ?? [], true);
                                    $editable = in_array($header->id, $editableHeaderIds, true) && !$isCalculated && !$isPoRowLocked && !($isFileLockedForUser ?? false) && !($isBuyerLockedForUser ?? false);
                                    $cellHighlightKey = $row->id . '-' . $header->id;
                                    $isHighlightedCell = isset($highlightedCellKeys) && $highlightedCellKeys->contains($cellHighlightKey);
                                    $roleClass = $headerRoleClass($header);

                                    // Duplicate markers: only on a still-flagged
                                    // duplicate, and only for the few columns a
                                    // new order normally changes. Both tiers
                                    // clear with the badge.
                                    //
                                    // TWO TIERS, because "this is wrong until
                                    // you change it" and "worth checking" are
                                    // different instructions:
                                    //   always → cell-needs-update (amber)
                                    //   likely → cell-check-value  (quiet grey)
                                    // A column can only be in one tier, so the
                                    // classes are mutually exclusive.
                                    $needsUpdate = $isFlaggedDuplicate
                                        && in_array($header->id, $duplicateNeedsUpdateHeaderIds['always'] ?? [], true);

                                    $checkValue = $isFlaggedDuplicate
                                        && ! $needsUpdate
                                        && in_array($header->id, $duplicateNeedsUpdateHeaderIds['likely'] ?? [], true);

                                    $inputType = 'text';
                                    if ($header->field_type === 'number') {
                                        $inputType = 'number';
                                    } elseif ($header->field_type === 'date') {
                                        $inputType = 'date';
                                    }
                                @endphp

                                    <td
                                        class="role-column {{ $roleClass }} {{ trim(($isCalculated ? 'calculated-cell ' : '') . ($isHighlightedCell ? 'notification-highlight-cell ' : '') . ($needsUpdate ? 'cell-needs-update' : ($checkValue ? 'cell-check-value' : ''))) }}"
                                        @if($needsUpdate)
                                            title="Duplicated row — {{ $header->header_name }} must be given a new value."
                                        @elseif($checkValue)
                                            title="Duplicated row — check {{ $header->header_name }}. It is often correct as copied."
                                        @endif
                                        data-header-id="{{ $header->id }}"
                                        data-header-key="{{ $header->header_key }}"
                                        data-row-index="{{ $loop->parent->index }}"
                                        data-col-index="{{ $loop->index }}"
                                    >
                                        @if($editable)
                                            {{-- SILENT DATA CORRUPTION, do not remove these four
                                                 attributes. A cell input's name is stable across
                                                 page loads (cells[<row id>][<header id>]), which is
                                                 exactly the signature a browser's form history keys
                                                 on. Chrome was silently refilling empty cells with a
                                                 value somebody once submitted for that same cell —
                                                 firing change, marking the sheet dirty, and then
                                                 being written by the "you have unsaved changes, save
                                                 first?" prompt. Nobody typed those values. The change
                                                 log carries several such writes.

                                                 The search and filter inputs on this screen already
                                                 carry autocomplete="off"; the cells were missed.
                                                 data-lpignore / data-form-type / data-1p-ignore do
                                                 the same for the third-party password managers, which
                                                 ignore autocomplete outright. --}}
                                            <input
                                                type="{{ $inputType }}"
                                                name="cells[{{ $row->id }}][{{ $header->id }}]"
                                                value="{{ $value }}"
                                                class="form-control form-control-sm excel-editable-cell"
                                                autocomplete="off"
                                                data-lpignore="true"
                                                data-form-type="other"
                                                data-1p-ignore
                                                data-row-index="{{ $loop->parent->index }}"
                                                data-col-index="{{ $loop->index }}"
                                                data-original-value="{{ $value }}"
                                                data-header-id="{{ $header->id }}"
                                                data-header-key="{{ $header->header_key }}"
                                                @if($inputType === 'number') step="any" @endif
                                            >
                                        @else
                                            <div
                                                class="px-1 py-1 excel-readonly-cell {{ $isCalculated ? 'excel-calculated-value' : '' }}"
                                                data-row-index="{{ $loop->parent->index }}"
                                                data-col-index="{{ $loop->index }}"
                                                data-header-id="{{ $header->id }}"
                                                data-header-key="{{ $header->header_key }}"
                                                data-cell-display="1"
                                                data-formula-output="{{ $isCalculated ? '1' : '0' }}"
                                                data-cell-value="{{ $value }}"
                                                title="{{ ($isFileLockedForUser ?? false) ? 'This workspace file is locked by admin' : ($isPoRowLocked ? 'This PO row is locked by admin control' : ($isCalculated ? 'Calculated field - live auto update before save' : '')) }}"
                                            >
                                                {{ $value !== '' && $value !== null ? $value : '-' }}
                                            </div>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ count($headers) + 1 }}" class="text-center">No row data found.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if(method_exists($rows, 'currentPage'))
                @php
                    $currentPage = $rows->currentPage();
                    $lastPage = method_exists($rows, 'lastPage') ? $rows->lastPage() : $currentPage;
                    $startPage = max(1, $currentPage - 2);
                    $endPage = min($lastPage, $currentPage + 2);
                @endphp

                <div class="sheet-pagination">
                    <div class="page-status">
                        Showing {{ $rows->firstItem() ?? 0 }} to {{ $rows->lastItem() ?? 0 }} of {{ method_exists($rows, 'total') ? $rows->total() : 0 }} rows
                    </div>

                    <div class="sheet-pager">
                        @if($rows->onFirstPage())
                            <span class="pager-btn disabled">&lsaquo; Previous</span>
                        @else
                            <a class="pager-btn" href="{{ $rows->previousPageUrl() }}">&lsaquo; Previous</a>
                        @endif

                        @if($startPage > 1)
                            <a class="pager-page" href="{{ $rows->url(1) }}">1</a>
                            @if($startPage > 2)
                                <span class="pager-ellipsis">...</span>
                            @endif
                        @endif

                        @for($pageNo = $startPage; $pageNo <= $endPage; $pageNo++)
                            @if($pageNo == $currentPage)
                                <span class="pager-page active">{{ $pageNo }}</span>
                            @else
                                <a class="pager-page" href="{{ $rows->url($pageNo) }}">{{ $pageNo }}</a>
                            @endif
                        @endfor

                        @if($endPage < $lastPage)
                            @if($endPage < $lastPage - 1)
                                <span class="pager-ellipsis">...</span>
                            @endif
                            <a class="pager-page" href="{{ $rows->url($lastPage) }}">{{ $lastPage }}</a>
                        @endif

                        @if($rows->hasMorePages())
                            <a class="pager-btn" href="{{ $rows->nextPageUrl() }}">Next &rsaquo;</a>
                        @else
                            <span class="pager-btn disabled">Next &rsaquo;</span>
                        @endif
                    </div>
                </div>
            @endif
        </div>
    </form>
</div>

<div class="draft-modal-overlay" id="draftRestoreModal">
    <div class="draft-modal-box">
        <div class="draft-modal-header">
            <h4 class="draft-modal-title">Unsaved Work Found</h4>
            <p class="draft-modal-subtitle">Your previous unsaved worksheet changes are available.</p>
        </div>

        <div class="draft-modal-body">
            <p>Do you want to restore the unsaved changes?</p>
            <p class="mb-0"><strong>Restore</strong> will fill the edited cells again. <strong>Discard</strong> will remove the saved draft.</p>
        </div>

        <div class="draft-modal-actions">
            <button type="button" class="btn btn-soft-secondary" id="discardDraftBtn">Discard</button>
            <button type="button" class="btn btn-gradient-warning" id="restoreOnlyBtn">Restore</button>
            <button type="button" class="btn btn-gradient-primary" id="restoreAndSaveBtn">Restore & Save</button>
        </div>
    </div>
</div>

<div class="draft-restore-toast" id="draftRestoreToast"></div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const table = document.getElementById('excelDataTable');
    const filterInputs = document.querySelectorAll('.filter-input');
    const form = document.getElementById('excelFileForm');
    const addRowForm = document.getElementById('add-row-form');
    const addRowButton = document.getElementById('addRowButton');
    const editableInputs = Array.from(document.querySelectorAll('.excel-editable-cell'));
    const successAlert = document.getElementById('pageSuccessAlert');

    const sheetHeaders = @json($sheetHeadersForJs);
    const calculatedHeaderKeys = @json($calculatedHeaderKeys ?? []);
    const calculatedHeaderIds = @json($calculatedHeaderIds ?? []);
    const piPraDates = @json($piPraDates ?? []);
    const currentUserRoleSlugs = @json($currentUserRoleSlugs);
    const shouldAutoScrollToUserColumns = @json($shouldAutoScrollToUserColumns);

    const headerJumpSelect = document.getElementById('headerJumpSelect');
    const jumpToMyColumnsButton = document.getElementById('jumpToMyColumnsButton');

    const draftModal = document.getElementById('draftRestoreModal');
    const discardDraftBtn = document.getElementById('discardDraftBtn');
    const restoreOnlyBtn = document.getElementById('restoreOnlyBtn');
    const restoreAndSaveBtn = document.getElementById('restoreAndSaveBtn');
    const draftRestoreToast = document.getElementById('draftRestoreToast');

    const draftKey = `excel_draft_user_{{ auth()->id() }}_file_{{ $excelFile->id }}`;
    const draftMeta = {
        version: 2,
        fileId: {{ (int) $excelFile->id }},
        userId: {{ (int) auth()->id() }},
    };
    let isSubmitting = false;
    let restoreModalShown = false;
    let userEditedWorksheet = false;
    let draftSaveTimer = null;

    const editableGrid = {};

    function buildEditableGrid() {
        Object.keys(editableGrid).forEach(key => delete editableGrid[key]);

        editableInputs.forEach(input => {
            const rowIndex = parseInt(input.dataset.rowIndex, 10);
            const colIndex = parseInt(input.dataset.colIndex, 10);

            if (!editableGrid[rowIndex]) {
                editableGrid[rowIndex] = {};
            }

            editableGrid[rowIndex][colIndex] = input;
        });
    }

    function focusEditableCell(rowIndex, colIndex) {
        if (editableGrid[rowIndex] && editableGrid[rowIndex][colIndex]) {
            editableGrid[rowIndex][colIndex].focus();
            editableGrid[rowIndex][colIndex].select();
        }
    }

    function headerIndexById(headerId) {
        const id = Number(headerId);
        return sheetHeaders.findIndex(header => Number(header.id) === id);
    }

    function headerById(headerId) {
        const index = headerIndexById(headerId);
        return index >= 0 ? sheetHeaders[index] : null;
    }

    function clearActiveHeaderColumn() {
        document.querySelectorAll('.active-header-column').forEach(element => {
            element.classList.remove('active-header-column');
        });
    }

    function highlightHeaderColumn(headerId) {
        clearActiveHeaderColumn();

        const selector = `[data-header-id="${headerId}"]`;
        document.querySelectorAll(selector).forEach(element => {
            element.classList.add('active-header-column');
        });

        setTimeout(clearActiveHeaderColumn, 3500);
    }

    function scrollToHeader(header, focusFirstEditable = true) {
        if (!header || !table) {
            return;
        }

        const index = headerIndexById(header.id);
        const headerCell = table.querySelector(`thead tr:first-child th[data-header-id="${header.id}"]`);

        if (!headerCell) {
            return;
        }

        headerCell.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
        highlightHeaderColumn(header.id);

        if (typeof searchColumnStorageKey !== 'undefined') {
            localStorage.setItem(searchColumnStorageKey, String(index + 1));
        }

        /* Keep the finder showing the column we actually landed on, including
         * when we got here from My Columns or the auto-scroll rather than from
         * the dropdown. Silent, and Tom Select ignores a write to .value once
         * it has taken the element over, so it has to be told directly —
         * otherwise the control keeps displaying the previous choice. */
        if (headerJumpSelect) {
            if (headerJumpSelect.tomselect) {
                headerJumpSelect.tomselect.setValue(String(header.id), true);
            } else {
                headerJumpSelect.value = String(header.id);
            }
        }

        if (focusFirstEditable) {
            setTimeout(function () {
                const editable = document.querySelector(`.excel-editable-cell[data-header-id="${header.id}"]`);

                if (editable) {
                    editable.focus({ preventScroll: true });
                    editable.select();
                    return;
                }

                const filter = document.querySelector(`.filter-input[data-filter-key="${header.id}"]`);

                if (filter) {
                    filter.focus({ preventScroll: true });
                    filter.select();
                }
            }, 450);
        }
    }


    function firstHeaderForCurrentUser() {
        const roles = Array.isArray(currentUserRoleSlugs) ? currentUserRoleSlugs : [];

        if (roles.length === 0) {
            return null;
        }

        return sheetHeaders.find(header => roles.includes(header.owner_role_slug)) || null;
    }

    function scrollToCurrentUserColumns(focusFirstEditable = false) {
        const targetHeader = firstHeaderForCurrentUser();

        if (!targetHeader) {
            return;
        }

        scrollToHeader(targetHeader, focusFirstEditable);
    }

    function normalizeValue(value) {
        return String(value ?? '').trim();
    }

    function getCurrentInputMap() {
        const data = {};

        editableInputs.forEach(input => {
            data[input.name] = input.value ?? '';
        });

        return data;
    }

    function getChangedFields() {
        const changed = {};

        editableInputs.forEach(input => {
            const originalValue = normalizeValue(input.dataset.originalValue);
            const currentValue = normalizeValue(input.value);

            if (currentValue !== originalValue) {
                changed[input.name] = input.value ?? '';
            }
        });

        return changed;
    }

    function hasRealChanges(obj) {
        return obj && Object.keys(obj).length > 0;
    }

    function getDraftFields(savedDraft) {
        if (!savedDraft || typeof savedDraft !== 'object') {
            return {};
        }

        // Backward compatible: old drafts were stored directly as { inputName: value }.
        if (savedDraft.fields && typeof savedDraft.fields === 'object') {
            return savedDraft.fields;
        }

        return savedDraft;
    }

    function getVisibleDraftChanges(fields) {
        const visibleDraft = {};

        editableInputs.forEach(input => {
            if (!Object.prototype.hasOwnProperty.call(fields, input.name)) {
                return;
            }

            const draftValue = fields[input.name] ?? '';

            // Do not show restore modal for values already equal to database/original value.
            if (normalizeValue(draftValue) === normalizeValue(input.dataset.originalValue)) {
                return;
            }

            // Do not show restore modal when the current visible input already has the draft value.
            if (normalizeValue(draftValue) === normalizeValue(input.value)) {
                return;
            }

            visibleDraft[input.name] = draftValue;
        });

        return visibleDraft;
    }

    function buildDraftPayload(fields) {
        return {
            ...draftMeta,
            savedAt: new Date().toISOString(),
            fields: fields,
        };
    }

    function saveDraftToLocal(force = false) {
        // Important: clicking/focusing a cell must not create a draft.
        // Draft will be saved only after user actually changes a value.
        if (!force && !userEditedWorksheet) {
            return;
        }

        const changed = getChangedFields();

        if (hasRealChanges(changed)) {
            localStorage.setItem(draftKey, JSON.stringify(buildDraftPayload(changed)));
        } else {
            clearDraft();
        }
    }

    function scheduleDraftSave() {
        clearTimeout(draftSaveTimer);
        draftSaveTimer = setTimeout(() => saveDraftToLocal(true), 250);
    }

    function clearDraft() {
        clearTimeout(draftSaveTimer);
        localStorage.removeItem(draftKey);
    }

    function clearRestoredHighlights() {
        document.querySelectorAll('.draft-restored-cell').forEach(cell => {
            cell.classList.remove('draft-restored-cell');
        });
    }

    function highlightRestoredInput(input) {
        const td = input.closest('td');
        if (td) {
            td.classList.add('draft-restored-cell');
        }
        input.classList.add('draft-restored-input');
    }

    function showDraftToast(message) {
        if (!draftRestoreToast) return;

        draftRestoreToast.textContent = message;
        draftRestoreToast.classList.add('show');

        setTimeout(() => {
            draftRestoreToast.classList.remove('show');
        }, 3000);
    }

    function applyDraft(draftFields) {
        clearRestoredHighlights();
        let appliedCount = 0;

        editableInputs.forEach(input => {
            if (Object.prototype.hasOwnProperty.call(draftFields, input.name)) {
                input.value = draftFields[input.name] ?? '';
                highlightRestoredInput(input);
                appliedCount++;
            }
        });

        if (appliedCount > 0) {
            userEditedWorksheet = true;
            updateLiveFormulas();
        }

        return appliedCount;
    }

    function markWorksheetChanged() {
        userEditedWorksheet = hasRealChanges(getChangedFields());

        if (userEditedWorksheet) {
            scheduleDraftSave();
        } else {
            clearDraft();
        }
    }

    function openDraftModal() {
        if (restoreModalShown || !draftModal) return;

        restoreModalShown = true;
        draftModal.classList.add('show');
        document.body.style.overflow = 'hidden';
    }

    function closeDraftModal() {
        if (!draftModal) return;

        draftModal.classList.remove('show');
        document.body.style.overflow = '';
    }

    function restoreDraftIfNeeded() {
        const rawDraft = localStorage.getItem(draftKey);
        if (!rawDraft) return;

        let savedDraft = null;

        try {
            savedDraft = JSON.parse(rawDraft);
        } catch (error) {
            clearDraft();
            return;
        }

        const draftFields = getDraftFields(savedDraft);

        if (!hasRealChanges(draftFields)) {
            clearDraft();
            return;
        }

        const visibleDraft = getVisibleDraftChanges(draftFields);

        // If no visible input needs restore, remove stale draft so modal does not keep showing.
        if (!hasRealChanges(visibleDraft)) {
            clearDraft();
            return;
        }

        openDraftModal();

        if (discardDraftBtn) {
            discardDraftBtn.onclick = function () {
                clearDraft();
                closeDraftModal();
            };
        }

        if (restoreOnlyBtn) {
            restoreOnlyBtn.onclick = function () {
                const appliedCount = applyDraft(visibleDraft);
                saveDraftToLocal(true);
                closeDraftModal();

                if (appliedCount > 0) {
                    showDraftToast(appliedCount + ' restored cell(s) highlighted. Click Save Changes when ready.');
                }
            };
        }

        if (restoreAndSaveBtn) {
            restoreAndSaveBtn.onclick = function () {
                const appliedCount = applyDraft(visibleDraft);
                clearDraft();
                isSubmitting = true;
                closeDraftModal();

                if (form && appliedCount > 0) {
                    if (typeof form.requestSubmit === 'function') {
                        form.requestSubmit();
                    } else {
                        form.submit();
                    }
                }
            };
        }
    }

    function handleArrowNavigation(input, event) {
        const rowIndex = parseInt(input.dataset.rowIndex, 10);
        const colIndex = parseInt(input.dataset.colIndex, 10);

        const key = event.key;
        const valueLength = input.value.length;
        const selectionStart = input.selectionStart ?? 0;
        const selectionEnd = input.selectionEnd ?? 0;

        if (key === 'ArrowUp') {
            event.preventDefault();
            focusEditableCell(rowIndex - 1, colIndex);
            return;
        }

        if (key === 'ArrowDown' || key === 'Enter') {
            event.preventDefault();
            focusEditableCell(rowIndex + 1, colIndex);
            return;
        }

        if (key === 'ArrowLeft') {
            if (selectionStart !== 0 || selectionEnd !== 0) {
                return;
            }

            event.preventDefault();
            focusEditableCell(rowIndex, colIndex - 1);
            return;
        }

        if (key === 'ArrowRight') {
            if (selectionStart !== valueLength || selectionEnd !== valueLength) {
                return;
            }

            event.preventDefault();
            focusEditableCell(rowIndex, colIndex + 1);
        }
    }

    function handleMultiPaste(input, event) {
        const clipboard = (event.clipboardData || window.clipboardData).getData('text');

        if (!clipboard || (!clipboard.includes('\n') && !clipboard.includes('\t'))) {
            return;
        }

        event.preventDefault();

        const startRow = parseInt(input.dataset.rowIndex, 10);
        const startCol = parseInt(input.dataset.colIndex, 10);

        const pastedRows = clipboard
            .replace(/\r/g, '')
            .split('\n')
            .filter(row => row !== '');

        let droppedRows = 0;
        let droppedCols = false;

        pastedRows.forEach((rowText, rowOffset) => {
            const cols = rowText.split('\t');
            const targetRow = startRow + rowOffset;

            if (!editableGrid[targetRow]) {
                droppedRows++;
                return;
            }

            cols.forEach((cellValue, colOffset) => {
                const targetCol = startCol + colOffset;

                if (editableGrid[targetRow][targetCol]) {
                    editableGrid[targetRow][targetCol].value = cellValue;
                } else {
                    droppedCols = true;
                }
            });
        });

        updateLiveFormulas();
        markWorksheetChanged();

        // Anything that did not fit used to vanish without a word, which is how
        // a merchant pastes 40 rows from Excel, sees the first 12 land, and has
        // no idea the rest were lost. Say so, and say what to do about it.
        if (droppedRows > 0 || droppedCols) {
            const parts = [];

            if (droppedRows > 0) {
                parts.push(droppedRows + (droppedRows === 1 ? ' row' : ' rows') + ' did not fit below the cell you pasted into');
            }

            if (droppedCols) {
                parts.push('some columns ran past the last column');
            }

            window.alert(
                'Pasted, but ' + parts.join(' and ') + '.\n\n'
                + 'Add or duplicate more rows first, then paste again into the first empty row.'
            );
        }
    }


    const headerAliases = {
        style_name: ['style', 'buyer name'],
        contract_number: ['initial contract number', 'contract number', 'gmnts po number', 'gmts po number', 'po number'],
        contract_shipment_date: ['contract shipment date', 'initial contract shipment date', 'po shipment date'],
        customer_contract_quantity: ['customer contract quantity', 'customer po quantity', 'order qty', 'gmts order qty', 'gmts order quantity'],
        initial_consumption: ['booking consumption from cad', 'initial consumption', 'booking yy', 'consumption', 'costing yy in sms', 'costing yy'],
        wastage_for_ordering_percent: ['% wastage for ordering', 'waste %', 'wastage %', 'waste'],
        materials_ordered: ['materials ordered', 'material ordered'],
        material_pi_number: ['material pi number', 'pi number', 'vendor pi number'],
        pi_rate: ['pi rate', 'invoiced rate(scm)', 'invoiced rate scm', 'invoiced rate'],
        pmt_doc_no: ['pmt doc no', 'payment doc no', 'payment reference number', 'payment ref no'],
        bl_awb_no: ['bl / awb no', 'bl awb no', 'bl no', 'awb no'],
        committed_ex_mill: ['committed ex mill', 'committed x-fty date', 'committed x fty date', 'committed ex-fty date', 'committed ex fty date'],
        committed_etd: ['committed etd', 'commited etd'],
        committed_eta: ['committed eta'],
        ata: ['ata'],
        actual_inhouse: ['actual inhouse', 'actual in-house'],
        invoiced_qty_scm: ['invoiced qty(scm)', 'invoiced qty scm', 'invoiced qty'],
        invoiced_rate_scm: ['invoiced rate(scm)', 'invoiced rate scm', 'invoiced rate', 'pi rate'],
        invoiced_qty_store: ['invoiced qty(store)', 'invoiced qty store', 'in-house / receipt qty', 'receipt qty'],
        invoiced_rate_store: ['invoiced rate(store)', 'invoiced rate store', 'invoiced rate(scm)', 'invoiced rate scm', 'invoiced rate', 'pi rate'],
        receipt_qty: ['receipt qty', 'in-house / receipt qty', 'in house receipt qty', 'receiving', 'inhouse qty'],
        production_consumption: ['production consumption', 'prod yy', 'production yy'],
        production_wastage_percent: ['production wastage %', 'prod. wastage %', 'prod wastage %'],
        issued_qty: ['issued qty', 'issued'],
        shipment_month: ['shipment month'],
        pcd_required: ['pcd required'],
        order_to_be_placed_by: ['order to be placed by', 'order to be placed'],
        consumption_incl_yy: ['consumption based on which materials order including yy', 'yy waste', 'yy + waste %'],
        materials_to_be_ordered: ['materials to be ordered', 'material to be ordered'],
        short_excess_ordered: ['(short)/excess ordered', '(short) / excess ordered', 'short excess ordered'],
        material_order_status: ['material order status'],
        pi_status: ['pi status'],
        pi_amount: ['pi amount'],
        payment_reqd_date: ["payment req'd date", 'payment reqd date', 'payment required date'],
        pi_summary_submission_date: ['pi summary submission date', 'pi summary submission'],
        payment_status: ['payment status'],
        bl_status: ['bl status'],
        arrival_status: ['arrival status'],
        committed_inhouse: ['committed inhouse', 'committed in house'],
        final_status: ['final status'],
        pcd_as_per_committed_inhouse: ['pcd as per committed inhouse', 'rm inh as per committed inhouse'],
        invoiced_amount_scm: ['invoiced amount(scm)', 'invoiced amount scm', 'invoiced amount'],
        invoiced_amount_store: ['invoiced amount(store)', 'invoiced amount store'],
        gmnts_po_number: ['gmnts po number', 'gmts po number', 'gmt po number'],
        gmts_order_qty: ['gmts order qty', 'gmts order quantity', 'gmt order qty'],
        production_cons_incl_wastage: ['production consumption including wastage', 'production cons including wastage', 'prod. yy + wastage', 'prod yy waste'],
        requirement: ['requirement'],
        excess_shortage: ['excess / (shortage)', 'excess shortage', '(short) / excess in-house qty'],
        liability: ['liability', 'liability qty'],
        buyer_liability: ['buyer liability'],
        buyer_liability_value: ['buyer liability value'],
        liability_based_on_receiving: ['liability based on receiving'],
        short_excess_issued: ['(short)/ excess issued', '(short) / excess issued', 'short excess issued'],
        return_back_to_stores: ['return back to stores'],
        dead_stock_quantity: ['dead stock quantity'],
        material_cost_value: ['material cost value', 'material issue value'],
        dead_stock_value: ['dead stock value'],
        liability_stock_value: ['liability stock value'],
        short_excess_value: ['short & excess value', 'short and excess value']
    };

    function normalizeHeaderKey(value) {
        return String(value ?? '')
            .toLowerCase()
            .trim()
            .replace(/[&+]/g, ' and ')
            .replace(/[’']/g, '')
            .replace(/[^a-z0-9]+/g, '_')
            .replace(/_+/g, '_')
            .replace(/^_+|_+$/g, '');
    }

    function buildHeaderLookup() {
        const lookup = {};

        sheetHeaders.forEach((header, index) => {
            [header.header_key, header.header_name, normalizeHeaderKey(header.header_key), normalizeHeaderKey(header.header_name)].forEach(key => {
                const normalized = normalizeHeaderKey(key);
                if (normalized && lookup[normalized] === undefined) {
                    lookup[normalized] = index;
                }
            });
        });

        Object.entries(headerAliases).forEach(([canonical, aliases]) => {
            const normalizedCanonical = normalizeHeaderKey(canonical);
            if (lookup[normalizedCanonical] !== undefined) return;

            aliases.forEach(alias => {
                const aliasKey = normalizeHeaderKey(alias);
                if (lookup[normalizedCanonical] === undefined && lookup[aliasKey] !== undefined) {
                    lookup[normalizedCanonical] = lookup[aliasKey];
                }
            });
        });

        return lookup;
    }

    const headerIndexByKey = buildHeaderLookup();

    function rowElements() {
        if (!table) return [];
        return Array.from(table.querySelectorAll('tbody tr')).filter(row => row.querySelectorAll('td').length > 1);
    }

    function readCellValue(row, colIndex) {
        const cell = row.querySelectorAll('td')[colIndex + 1];
        if (!cell) return '';

        const input = cell.querySelector('input.excel-editable-cell');
        if (input) return input.value ?? '';

        const display = cell.querySelector('[data-cell-display]');
        if (display) {
            const value = display.dataset.cellValue ?? display.textContent ?? '';
            return String(value).trim() === '-' ? '' : value;
        }

        const text = cell.textContent ?? '';
        return String(text).trim() === '-' ? '' : text;
    }

    function valAny(rowData, keys) {
        for (const key of keys) {
            const normalized = normalizeHeaderKey(key);
            const index = headerIndexByKey[normalized];
            if (index === undefined) continue;

            const value = rowData[index];
            if (!blank(value)) return value;
        }

        return null;
    }

    function blank(value) {
        return value === null || value === undefined || String(value).trim() === '' || String(value).trim() === '-';
    }

    function num(value) {
        if (blank(value)) return 0;
        const cleaned = String(value).replace(/[,\s]/g, '');
        const parsed = Number(cleaned);
        return Number.isFinite(parsed) ? parsed : 0;
    }

    function percent(value) {
        const n = num(value);
        return n > 1 ? n / 100 : n;
    }

    function parseDateValue(value) {
        if (blank(value)) return null;
        const raw = String(value).trim();

        if (/^\d+(\.\d+)?$/.test(raw)) {
            const base = new Date(Date.UTC(1899, 11, 30));
            base.setUTCDate(base.getUTCDate() + parseInt(raw, 10));
            return base;
        }

        let match = raw.match(/^(\d{4})-(\d{1,2})-(\d{1,2})$/);
        if (match) {
            return new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]));
        }

        match = raw.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);
        if (match) {
            return new Date(Number(match[3]), Number(match[1]) - 1, Number(match[2]));
        }

        const parsed = new Date(raw);
        return Number.isNaN(parsed.getTime()) ? null : parsed;
    }

    function addDays(date, days) {
        if (!date) return null;
        const next = new Date(date.getTime());
        next.setDate(next.getDate() + days);
        return next;
    }

    function fmtDate(date) {
        if (!date || Number.isNaN(date.getTime())) return '';
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        return `${year}-${month}-${day}`;
    }

    function fmtMonth(date) {
        if (!date || Number.isNaN(date.getTime())) return '';
        return date.toLocaleString('en-US', { month: 'short' });
    }

    function fmtNum(value) {
        if (value === null || value === undefined || value === '') return '';
        const n = Number(value);
        if (!Number.isFinite(n)) return '';
        if (Math.abs(n - Math.round(n)) < 0.000001) return String(Math.round(n));
        return n.toFixed(4).replace(/0+$/, '').replace(/\.$/, '');
    }

    function setFormulaValue(row, keys, value) {
        let targetIndex;

        for (const key of keys) {
            const normalized = normalizeHeaderKey(key);
            if (headerIndexByKey[normalized] !== undefined) {
                targetIndex = headerIndexByKey[normalized];
                break;
            }
        }

        if (targetIndex === undefined) return;

        const cell = row.querySelectorAll('td')[targetIndex + 1];
        if (!cell) return;

        const display = cell.querySelector('[data-formula-output="1"]');
        if (!display) return;

        const normalizedValue = value === null || value === undefined || value === '' ? '' : String(value);
        display.dataset.cellValue = normalizedValue;
        display.textContent = normalizedValue !== '' ? normalizedValue : '-';
        display.classList.add('formula-live-updated');
    }

    function calculateLiveFormulas() {
        const rows = rowElements();
        let previousFormulaKey = null;
        const today = new Date();
        today.setHours(0, 0, 0, 0);

        rows.forEach(row => {
            const cells = row.querySelectorAll('td');
            if (cells.length <= 1) return;

            const rowData = sheetHeaders.map((header, index) => readCellValue(row, index));

            const styleOrBuyer = valAny(rowData, ['style_name', 'style', 'buyer_name']);
            const gmtsColorName = valAny(rowData, ['gmts_color_name', 'gmts_colour_name', 'gmts_color']);
            const contractNumber = valAny(rowData, ['initial_contract_number', 'contract_number', 'customer_contract', 'gmnts_po_number', 'gmts_po_number', 'po_number']);
            const formulaKey = [gmtsColorName, contractNumber, styleOrBuyer]
                .map(value => String(value ?? '').trim())
                .filter(value => value !== '')
                .join('|');

            const contractShipmentDate = parseDateValue(valAny(rowData, ['contract_shipment_date', 'initial_contract_shipment_date', 'po_shipment_date']));
            const customerContractQtySource = num(valAny(rowData, ['bom_quantity', 'customer_contract_quantity', 'customer_po_quantity', 'order_qty', 'gmts_order_qty', 'gmts_order_quantity']));
            const initialConsumption = num(valAny(rowData, ['booking_consumption_from_cad', 'initial_consumption', 'booking_yy', 'consumption', 'costing_yy_in_sms', 'costing_yy']));
            const orderingWastage = percent(valAny(rowData, ['wastage_for_ordering_percent', 'waste_percent', 'wastage_percent', 'waste']));
            const materialsOrderedRaw = valAny(rowData, ['materials_ordered', 'material_ordered']);
            const materialsOrdered = num(materialsOrderedRaw);
            const materialPiNumber = valAny(rowData, ['material_pi_number', 'pi_number', 'vendor_pi_number']);
            const piRate = num(valAny(rowData, ['pi_rate', 'invoiced_rate_scm', 'invoiced_rate']));
            const pmtDocNo = valAny(rowData, ['pmt_doc_no', 'payment_doc_no', 'payment_reference_number', 'payment_ref_no']);
            const blAwbNo = valAny(rowData, ['bl_awb_no', 'bl_no', 'awb_no']);
            const committedExMill = parseDateValue(valAny(rowData, ['committed_ex_mill', 'committed_x_fty_date', 'committed_ex_fty_date', 'committed_ex_fty', 'committed_x_fty']));
            const committedEtd = parseDateValue(valAny(rowData, ['committed_etd', 'commited_etd']));
            const committedEta = parseDateValue(valAny(rowData, ['committed_eta']));
            const ata = parseDateValue(valAny(rowData, ['ata']));
            const actualInhouse = parseDateValue(valAny(rowData, ['actual_inhouse', 'actual_in_house']));
            const invoicedQtyScm = num(valAny(rowData, ['invoiced_qty_scm', 'invoiced_qty']));
            const invoicedRateScm = num(valAny(rowData, ['invoiced_rate_scm', 'invoiced_rate', 'pi_rate']));
            const invoicedQtyStore = num(valAny(rowData, ['invoiced_qty_store', 'in_house_receipt_qty', 'receipt_qty']));
            const invoicedRateStore = num(valAny(rowData, ['invoiced_rate_store', 'invoiced_rate_scm', 'invoiced_rate', 'pi_rate']));
            const receiptQty = num(valAny(rowData, ['receipt_qty', 'in_house_receipt_qty', 'receiving', 'inhouse_qty']));
            const productionConsumption = num(valAny(rowData, ['production_consumption', 'prod_yy', 'production_yy']));
            const productionWastage = percent(valAny(rowData, ['production_wastage_percent', 'prod_wastage_percent', 'prod_wastage']));
            const issuedQty = num(valAny(rowData, ['issued_qty', 'issued']));

            const shipmentMonth = fmtMonth(contractShipmentDate);
            const customerContractQty = (formulaKey !== '' && formulaKey === previousFormulaKey) ? 0 : customerContractQtySource;
            const pcdRequired = contractShipmentDate ? addDays(contractShipmentDate, -45) : null;
            const orderToBePlacedBy = pcdRequired ? addDays(pcdRequired, -70) : null;

            const consumptionInclYy = initialConsumption * (1 + orderingWastage);
            const materialsToBeOrdered = Math.round(consumptionInclYy * customerContractQtySource);
            const shortExcessOrdered = Math.round(materialsOrdered - materialsToBeOrdered);

            let materialOrderStatus = 'PO Pending';
            if (!blank(materialsOrderedRaw)) {
                if (shortExcessOrdered <= (materialsToBeOrdered * -1)) {
                    materialOrderStatus = 'PO Pending';
                } else if (shortExcessOrdered < 0) {
                    materialOrderStatus = 'Short PO Qty';
                } else if (shortExcessOrdered === 0) {
                    materialOrderStatus = 'PO Raised';
                } else {
                    materialOrderStatus = 'Excess Qty PO';
                }
            }

            const piStatus = materialOrderStatus === 'PO Pending'
                ? 'Waiting for PO'
                : (blank(materialPiNumber) ? 'PI Pending' : 'PI Received');
            const piAmount = piRate * materialsToBeOrdered;
            let paymentReqdDate = committedExMill ? addDays(committedExMill, -7) : null;

            // A created PRA overrides the formula. Matching is by PI No., so every
            // row sharing the same PI No. as the PRA updates together (latest PRA
            // wins). Rows whose PI No. has no PRA keep the ex-mill - 7 days formula.
            const piKey = String(materialPiNumber ?? '').trim().toLowerCase();
            const pra = (piKey && piPraDates[piKey]) ? piPraDates[piKey] : null;
            const paymentStatus = piStatus !== 'PI Received'
                ? piStatus
                : (blank(pmtDocNo) ? 'Pmt Pending' : 'Pmt Done');
            const blStatus = paymentStatus !== 'Pmt Done'
                ? paymentStatus
                : (blank(blAwbNo) ? 'BL Pending' : 'BL raised');

            let arrivalStatus = blStatus;
            if (ata) {
                arrivalStatus = ata.getTime() > Date.now() ? 'Sailed but not arrived' : 'Arrived';
            } else if (committedEta) {
                if (committedEtd && committedEtd.getTime() > today.getTime()) {
                    arrivalStatus = 'Not Sailed';
                } else if (committedEta.getTime() < today.getTime()) {
                    arrivalStatus = 'Late';
                } else {
                    arrivalStatus = 'Sailed but not arrived';
                }
            }

            const committedInhouse = committedEta ? addDays(committedEta, 7) : null;
            const finalStatus = actualInhouse ? 'Inhouse' : arrivalStatus;
            const pcdAsPerCommittedInhouse = committedInhouse ? addDays(committedInhouse, 2) : null;
            const isStorePayment = String(pmtDocNo ?? '').trim().toUpperCase() === 'STORES';
            const invoicedAmountScm = isStorePayment ? 0 : (invoicedQtyScm * invoicedRateScm);
            const invoicedAmountStore = invoicedQtyStore * invoicedRateStore;
            const gmntsPoNumber = contractNumber;
            const gmtsOrderQty = customerContractQtySource;
            const productionConsInclWastage = productionConsumption * (1 + productionWastage);
            const requirement = productionConsInclWastage * gmtsOrderQty;
            const excessShortage = receiptQty - requirement;
            const liabilityQty = excessShortage > 0 ? excessShortage : 0;
            const buyerLiabilityQty = shortExcessOrdered > 0 ? shortExcessOrdered : 0;
            const buyerLiabilityValue = buyerLiabilityQty * piRate;
            const liabilityBasedOnReceiving = receiptQty - materialsToBeOrdered;
            const shortExcessIssued = requirement - issuedQty;
            const returnBackToStores = shortExcessIssued < 0 ? 0 : shortExcessIssued;
            const deadStockQuantity = (receiptQty - issuedQty) - returnBackToStores;
            const materialCostValue = issuedQty * invoicedRateStore;
            const deadStockValue = deadStockQuantity * invoicedRateStore;
            const liabilityStockValue = liabilityQty * invoicedRateStore;
            const shortExcessValue = excessShortage * invoicedRateStore;

            setFormulaValue(row, ['shipment_month'], shipmentMonth);
            setFormulaValue(row, ['customer_contract_quantity', 'customer_po_quantity'], fmtNum(customerContractQty));
            setFormulaValue(row, ['pcd_required'], fmtDate(pcdRequired));
            setFormulaValue(row, ['order_to_be_placed_by', 'order_to_be_placed'], fmtDate(orderToBePlacedBy));
            setFormulaValue(row, ['consumption_incl_yy', 'consumption_based_on_which_materials_order_including_yy', 'yy_waste'], fmtNum(consumptionInclYy));
            setFormulaValue(row, ['materials_to_be_ordered', 'material_to_be_ordered'], fmtNum(materialsToBeOrdered));
            setFormulaValue(row, ['short_excess_ordered', 'short_excess_ordered_qty'], fmtNum(shortExcessOrdered));
            setFormulaValue(row, ['material_order_status'], materialOrderStatus);
            setFormulaValue(row, ['pi_status'], piStatus);
            setFormulaValue(row, ['pi_amount'], fmtNum(piAmount));
            setFormulaValue(row, ['payment_reqd_date', 'payment_req_d_date', 'payment_required_date'], (pra && pra.reqd) ? pra.reqd : fmtDate(paymentReqdDate));
            if (pra && pra.submission) {
                setFormulaValue(row, ['pi_summary_submission_date', 'pi_summary_submission'], pra.submission);
            }
            setFormulaValue(row, ['payment_status'], paymentStatus);
            setFormulaValue(row, ['bl_status'], blStatus);
            setFormulaValue(row, ['arrival_status'], arrivalStatus);
            setFormulaValue(row, ['committed_inhouse', 'committed_in_house'], fmtDate(committedInhouse));
            setFormulaValue(row, ['final_status'], finalStatus);
            setFormulaValue(row, ['pcd_as_per_committed_inhouse', 'rm_inh_as_per_committed_inhouse'], fmtDate(pcdAsPerCommittedInhouse));
            setFormulaValue(row, ['invoiced_amount_scm', 'invoiced_amount'], fmtNum(invoicedAmountScm));
            setFormulaValue(row, ['invoiced_amount_store'], fmtNum(invoicedAmountStore));
            setFormulaValue(row, ['gmnts_po_number', 'gmts_po_number'], gmntsPoNumber);
            setFormulaValue(row, ['gmts_order_qty', 'gmts_order_quantity', 'gmts_order_qty_store'], fmtNum(gmtsOrderQty));
            setFormulaValue(row, ['production_cons_incl_wastage', 'production_consumption_including_wastage', 'prod_yy_wastage'], fmtNum(productionConsInclWastage));
            setFormulaValue(row, ['requirement'], fmtNum(requirement));
            setFormulaValue(row, ['excess_shortage', 'excess_shortage_qty'], fmtNum(excessShortage));
            setFormulaValue(row, ['liability', 'liability_qty'], fmtNum(liabilityQty));
            setFormulaValue(row, ['buyer_liability'], fmtNum(buyerLiabilityQty));
            setFormulaValue(row, ['buyer_liability_value'], fmtNum(buyerLiabilityValue));
            setFormulaValue(row, ['liability_based_on_receiving'], fmtNum(liabilityBasedOnReceiving));
            setFormulaValue(row, ['short_excess_issued'], fmtNum(shortExcessIssued));
            setFormulaValue(row, ['return_back_to_stores'], fmtNum(returnBackToStores));
            setFormulaValue(row, ['dead_stock_quantity'], fmtNum(deadStockQuantity));
            setFormulaValue(row, ['material_cost_value', 'material_issue_value'], fmtNum(materialCostValue));
            setFormulaValue(row, ['dead_stock_value'], fmtNum(deadStockValue));
            setFormulaValue(row, ['liability_stock_value'], fmtNum(liabilityStockValue));
            setFormulaValue(row, ['short_excess_value', 'short_and_excess_value'], fmtNum(shortExcessValue));

            previousFormulaKey = formulaKey;
        });
    }

    function updateLiveFormulas() {
        calculateLiveFormulas();
    }

    buildEditableGrid();
    updateLiveFormulas();

    /* ---- Autofill guard, second layer ------------------------------------
     * autocomplete="off" on the form and on every cell is the fix; this is the
     * net under it, because a browser is free to ignore that attribute on a
     * field its heuristics decide to claim.
     *
     * The rule is narrow enough to be safe: BEFORE the user has touched the
     * page at all, no cell value may differ from what the server rendered.
     * Nothing legitimate writes a cell in that window — the draft restore never
     * applies on its own, it opens a modal and waits for a click — so any
     * divergence here is the browser, not a person, and reverting it cannot
     * discard an edit nobody has had the chance to make yet.
     *
     * After the first click or keypress this never runs again, so a real edit
     * is never second-guessed.
     */
    let userHasInteracted = false;

    ['pointerdown', 'keydown'].forEach((type) => {
        document.addEventListener(type, () => { userHasInteracted = true; }, { capture: true, once: true });
    });

    function revertUntouchedAutofill() {
        if (userHasInteracted) return;

        let reverted = 0;

        editableInputs.forEach((input) => {
            const original = input.dataset.originalValue ?? '';

            if (input.value !== original) {
                input.value = original;
                reverted++;
            }
        });

        if (reverted > 0) {
            updateLiveFormulas();
            // Recomputes the dirty state from scratch, so the sheet stops being
            // marked changed and the draft this had already written is cleared.
            markWorksheetChanged();
            console.warn(`[workspace] Reverted ${reverted} cell(s) the browser filled before the user typed anything.`);
        }
    }

    setTimeout(revertUntouchedAutofill, 400);

    const globalDocSearch = document.getElementById('globalDocSearch');
    const applySheetSearchButton = document.getElementById('applySheetSearchButton');
    const excelTableWrap = document.querySelector('.excel-table-wrap');
    const searchColumnStorageKey = `excel_search_column_user_{{ auth()->id() }}_file_{{ $excelFile->id }}`;

    function rememberActiveSearchColumn() {
        const active = document.activeElement;
        if (active && active.classList && active.classList.contains('filter-input')) {
            localStorage.setItem(searchColumnStorageKey, active.dataset.column || '0');
        } else if (globalDocSearch && active === globalDocSearch) {
            localStorage.setItem(searchColumnStorageKey, 'global');
        }
    }

    function restoreSearchColumnPosition() {
        const savedColumn = localStorage.getItem(searchColumnStorageKey);
        if (!savedColumn) return false;

        setTimeout(function () {
            const target = savedColumn === 'global'
                ? globalDocSearch
                : document.querySelector(`.filter-input[data-column="${savedColumn}"]`);

            if (!target) return;

            target.scrollIntoView({ block: 'nearest', inline: 'center' });
            target.focus({ preventScroll: true });

            if (target.select) {
                const valueLength = String(target.value || '').length;
                target.setSelectionRange(valueLength, valueLength);
            }
        }, 250);

        return true;
    }

    const restoredSearchColumn = restoreSearchColumnPosition();

    if (shouldAutoScrollToUserColumns && !restoredSearchColumn) {
        setTimeout(function () {
            scrollToCurrentUserColumns(false);
        }, 450);
    }

    function submitServerSheetSearch() {
        rememberActiveSearchColumn();
        const url = new URL(window.location.href);

        url.searchParams.delete('page');

        if (globalDocSearch && globalDocSearch.value.trim() !== '') {
            url.searchParams.set('search', globalDocSearch.value.trim());
        } else {
            url.searchParams.delete('search');
        }

        filterInputs.forEach(input => {
            const filterKey = input.dataset.filterKey;
            if (!filterKey) return;

            const paramName = 'filters[' + filterKey + ']';
            const value = input.value.trim();

            if (value !== '') {
                url.searchParams.set(paramName, value);
            } else {
                url.searchParams.delete(paramName);
            }
        });

        window.location.href = url.toString();
    }

    /* Search runs on Enter or the Search button only — never while typing.
     *
     * It used to fire on a 700ms typing debounce, but this search is a FULL
     * PAGE NAVIGATION, not an in-place filter. Any debounce can expire during
     * a normal mid-word pause, and once the reload starts every further
     * keystroke is thrown away (focus is only restored ~250ms after load), so
     * "bilaram" reached the server as "bilar". A reload also discards unsaved
     * cell edits. No timeout value fixes that — the trigger has to be the
     * user saying they are done. */
    [globalDocSearch, ...filterInputs].filter(Boolean).forEach(input => {
        input.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                submitServerSheetSearch();
            }
        });
    });

    if (applySheetSearchButton) {
        applySheetSearchButton.addEventListener('click', submitServerSheetSearch);
    }

    /* Order Information boxes for a VARIED field carry a filter instead of a
     * value. Choosing one narrows the sheet through the same
     * filters[<header id>] query the column search boxes already use — this is
     * a second way into the existing filter, not a second filter.
     *
     * Only this box's own key is touched, so a dropdown choice sits on top of
     * whatever column filters are already applied instead of clearing them.
     *
     * Returns false when the page is saving instead of navigating. */
    function applyOrderInfoFilter(headerId, value) {
        if (!passesUnsavedGuard()) {
            return false;
        }

        const url = new URL(window.location.href);
        url.searchParams.delete('page');

        const paramName = 'filters[' + headerId + ']';

        if (value !== '') {
            url.searchParams.set(paramName, value);
        } else {
            url.searchParams.delete(paramName);
        }

        window.location.href = url.toString();

        return true;
    }

    document.querySelectorAll('[data-order-info-filter]').forEach(function (select) {
        const initialValue = select.value;

        const onOrderInfoChange = function (value) {
            if (value === initialValue) {
                return;
            }

            if (applyOrderInfoFilter(select.dataset.headerId, value)) {
                return;
            }

            /* The guard chose to save, so no navigation happened. Put the
             * control back: a dropdown showing a value the sheet was never
             * filtered by describes rows that are not on screen. Tom Select
             * ignores a write to .value — it has to be told. */
            if (select.tomselect) {
                select.tomselect.setValue(initialValue, true);
            } else {
                select.value = initialValue;
            }
        };

        let counts = {};

        try {
            counts = JSON.parse(select.dataset.orderInfoCounts || '{}');
        } catch (e) {
            counts = {};
        }

        /* Same options as the Add New Order dialog's dropdowns, so there is one
         * searchable-dropdown behaviour on this page rather than two. Guarded
         * on window.TomSelect: a failed CDN leaves a plain <select> that still
         * filters correctly through the same handler.
         *
         * dropdownParent: 'body' is the fix for the panel that would not show.
         * These boxes sit inside .order-summary-card, which is overflow:hidden
         * to keep its 12px corners from being overdrawn. An absolutely
         * positioned panel is clipped by that ancestor no matter how high its
         * z-index is — the measured result was 163 of 170 pixels cut off, so
         * only a 7px sliver of the list was ever on screen. Raising z-index
         * cannot fix a clip; the panel has to leave the clipping box. Tom
         * Select then positions it itself and keeps it aligned on window
         * scroll and resize. */
        if (window.TomSelect) {
            new TomSelect(select, {
                allowEmptyOption: true,
                placeholder: select.options[0] ? select.options[0].text : 'Search...',
                searchField: ['text'],
                onChange: onOrderInfoChange,
                dropdownParent: 'body',
                // Parented to <body>, the panel is no longer a descendant of
                // .order-info-box, so it needs its own hook for the styling.
                dropdownClass: 'ts-dropdown order-info-dropdown',
                render: {
                    option: function (data, escape) {
                        if (data.value === '') {
                            return '<div class="oi-opt oi-opt-all">' + escape(data.text) + '</div>';
                        }

                        const count = counts[data.value];

                        return '<div class="oi-opt">'
                            + '<span class="oi-opt-name">' + escape(data.value) + '</span>'
                            + (count === undefined ? '' : '<span class="oi-opt-count">' + escape(String(count)) + '</span>')
                            + '</div>';
                    },
                    /* The chosen value alone. The row count belongs in the list
                     * where values are being compared, not in the control,
                     * where it would just crowd a 160px box. */
                    item: function (data, escape) {
                        return '<div>' + escape(data.value === '' ? data.text : data.value) + '</div>';
                    },
                    no_results: function () {
                        return '<div class="no-results">No match found</div>';
                    }
                }
            });
        } else {
            select.addEventListener('change', function () {
                onOrderInfoChange(select.value);
            });
        }
    });

    /* Tom Select repositions a body-parented panel on WINDOW scroll only. In
     * today's layout that is enough — the Order Information card does not move
     * when anything scrolls, the sheet scrolls inside its own box below it — so
     * this handler is a guard, not a fix for an observed bug: it keeps the
     * panel attached if the card is ever put inside a scrolling container.
     * Capture phase, because scroll does not bubble. */
    document.addEventListener('scroll', function () {
        document.querySelectorAll('[data-order-info-filter]').forEach(function (select) {
            if (select.tomselect && select.tomselect.isOpen) {
                select.tomselect.positionDropdown();
            }
        });
    }, true);

    if (headerJumpSelect) {
        /* Choosing a column IS the jump — there is no Find button to press any
         * more, exactly as choosing a style in the Order Information header
         * filters the sheet on its own. */
        headerJumpSelect.addEventListener('change', function () {
            const header = headerById(this.value);

            if (header) {
                scrollToHeader(header, true);
            }
        });

        /* Searchable, because this list is 129 columns long and scrolling a
         * native select to "Order To Be Placed By" is the reason the old text
         * box existed. Same options as every other Tom Select on this page.
         * Guarded on window.TomSelect: a failed CDN leaves a plain <select>
         * that still jumps through the same change handler. */
        if (window.TomSelect) {
            new TomSelect(headerJumpSelect, {
                allowEmptyOption: true,
                placeholder: 'Search headers…',
                searchField: ['text'],
                maxOptions: null,
                /* Same reason as the Order Information dropdowns: this bar sits
                 * inside .excel-work-card, which is overflow:hidden for its
                 * corners. On this file the card is tall enough that a 303px
                 * panel happens to fit inside it — but on a file with a handful
                 * of rows the card is short, and the panel would be clipped by
                 * it exactly as the header ones were. Parenting to <body> means
                 * that never depends on how many rows the file has. */
                dropdownParent: 'body',
                dropdownClass: 'ts-dropdown header-find-dropdown',
                render: {
                    no_results: function () {
                        return '<div class="no-results">No column matches that name</div>';
                    }
                }
            });
        }
    }

    if (jumpToMyColumnsButton) {
        jumpToMyColumnsButton.addEventListener('click', function () {
            scrollToCurrentUserColumns(true);
        });
    }

    editableInputs.forEach(input => {
        input.addEventListener('input', function () {
            clearRestoredHighlights();
            updateLiveFormulas();
            markWorksheetChanged();
        });
        input.addEventListener('change', function () {
            clearRestoredHighlights();
            updateLiveFormulas();
            markWorksheetChanged();
        });

        input.addEventListener('paste', function (event) {
            clearRestoredHighlights();
            handleMultiPaste(this, event);
        });

        input.addEventListener('keydown', function (event) {
            const supportedKeys = ['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight', 'Enter'];

            if (!supportedKeys.includes(event.key)) {
                return;
            }

            handleArrowNavigation(this, event);
        });
    });

    /* Send only the cells the user actually edited.
     *
     * The sheet renders one named input per editable cell, and a form posts
     * every one of them. For a merchant on the default 50-row page that is
     * ~1,300 variables, well past PHP's max_input_vars (1000), so PHP dropped
     * everything past roughly row 39 before Laravel ever saw it. The server
     * then found nothing to change for those rows and still flashed "File
     * updated successfully" — the silent data loss.
     *
     * A disabled input is not submitted, so pruning unchanged cells here takes
     * a normal save from ~1,300 variables to a handful. It also removes ~1,200
     * pointless firstOrNew() SELECTs per save. Unchanged cells were already
     * no-ops server-side, so what gets persisted is identical.
     */
    function pruneUnchangedCells() {
        const pruned = [];

        editableInputs.forEach(input => {
            if (input.disabled) return;
            if (input.value === (input.dataset.originalValue ?? '')) {
                input.disabled = true;
                pruned.push(input);
            }
        });

        return pruned;
    }

    if (form) {
        form.addEventListener('submit', function () {
            isSubmitting = true;
            clearDraft();

            const pruned = pruneUnchangedCells();
            const cellsCountField = document.getElementById('cellsCountField');

            if (cellsCountField) {
                cellsCountField.value = String(editableInputs.length - pruned.length);
            }

            // If the navigation never happens (validation, a blocked submit, the
            // user coming back via the bfcache), the sheet must not be left with
            // dead inputs.
            setTimeout(() => pruned.forEach(input => { input.disabled = false; }), 0);
        });
    }

    window.addEventListener('pageshow', function () {
        editableInputs.forEach(input => { input.disabled = false; });
    });

    /* ---- Row duplication -------------------------------------------------
     * Duplicating navigates away and the server persists immediately, exactly
     * as Add New Row does. So it reuses that button's unsaved-changes guard
     * rather than inventing a second one: without it, pending cell edits would
     * be silently thrown away by the redirect.
     */
    const duplicateRowsForm = document.getElementById('duplicate-rows-form');
    const duplicateSelectedButton = document.getElementById('duplicateSelectedButton');
    const deleteSelectedButton = document.getElementById('deleteSelectedButton');
    const deleteSelectedCount = document.getElementById('deleteSelectedCount');
    const duplicateSelectedCount = document.getElementById('duplicateSelectedCount');
    const rowSelectAll = document.getElementById('rowSelectAll');

    // Every row id the active filter matches, page or not. Ticking a checkbox
    // cancels the "all matching" mode, because the two would otherwise disagree
    // about what is selected.
    const allMatchingRowIds = @json($allMatchingRowIds ?? []).map(String);
    const selectAllMatchesButton = document.getElementById('selectAllMatchesButton');
    const selectAllMatchesCount = document.getElementById('selectAllMatchesCount');
    let allMatchesSelected = false;

    function selectedRowIds() {
        if (allMatchesSelected) {
            return allMatchingRowIds;
        }

        return Array.from(document.querySelectorAll('.row-select:checked')).map(cb => cb.value);
    }

    function refreshDuplicateSelection() {
        const ids = selectedRowIds();

        if (duplicateSelectedCount) duplicateSelectedCount.textContent = ids.length;

        // Shown only when there is a selection to act on, rather than sitting
        // greyed at (0) on every page load.
        if (duplicateSelectedButton) {
            duplicateSelectedButton.classList.toggle('d-none', ids.length === 0);
        }

        /* Delete counts only the ELIGIBLE rows of the selection.
         *
         * A row's eligibility was decided server-side and rendered as the
         * presence of its Delete button, so this reads that rather than
         * deciding anything: the same answer the server will give when the
         * request arrives. The selection is made for duplication, so it
         * routinely holds rows nothing may delete, and a button counting all of
         * them would promise more than the action performs. */
        const deletableSelected = ids.filter(id =>
            document.querySelector('tr[data-row-id="' + id + '"] .row-delete-btn.js-delete-row') !== null
        );

        if (deleteSelectedCount) deleteSelectedCount.textContent = deletableSelected.length;

        if (deleteSelectedButton) {
            // Absent when the selection contains nothing deletable at all —
            // "Delete Selected (0)" is a button that cannot do anything.
            deleteSelectedButton.classList.toggle('d-none', deletableSelected.length === 0);
            deleteSelectedButton.dataset.eligibleIds = deletableSelected.join(',');
            deleteSelectedButton.dataset.selectedCount = ids.length;
        }

        // Offer the rest of the match only when the page is fully ticked and
        // there is actually more behind it.
        if (selectAllMatchesButton) {
            const onPage = document.querySelectorAll('.row-select').length;
            const tickedOnPage = document.querySelectorAll('.row-select:checked').length;
            const moreBehind = allMatchingRowIds.length > onPage;
            const show = !allMatchesSelected && moreBehind && onPage > 0 && tickedOnPage === onPage;

            selectAllMatchesButton.classList.toggle('d-none', !show);
            if (selectAllMatchesCount) selectAllMatchesCount.textContent = allMatchingRowIds.length;
        }

        document.querySelectorAll('.row-select').forEach(function (cb) {
            cb.closest('tr')?.classList.toggle('row-is-selected', cb.checked);
        });

        if (rowSelectAll) {
            const all = document.querySelectorAll('.row-select');
            const checked = ids.length;
            rowSelectAll.checked = checked > 0 && checked === all.length;
            // Partial selection reads as indeterminate rather than unchecked.
            rowSelectAll.indeterminate = checked > 0 && checked < all.length;
        }
    }

    document.addEventListener('change', function (event) {
        if (event.target.classList.contains('row-select')) {
            // Any manual tick drops back to "what is actually checked".
            allMatchesSelected = false;
            refreshDuplicateSelection();
            return;
        }

        if (event.target === rowSelectAll) {
            allMatchesSelected = false;
            document.querySelectorAll('.row-select').forEach(cb => { cb.checked = rowSelectAll.checked; });
            refreshDuplicateSelection();
        }
    });

    if (selectAllMatchesButton) {
        selectAllMatchesButton.addEventListener('click', function () {
            allMatchesSelected = true;
            document.querySelectorAll('.row-select').forEach(cb => { cb.checked = true; });
            refreshDuplicateSelection();
        });
    }

    // Returns false when the user chose to save first — the form submit is
    // already under way and this action must not also navigate.
    function passesUnsavedGuard() {
        const changed = getChangedFields();

        if (!hasRealChanges(changed)) {
            return true;
        }

        const shouldSave = confirm('You have unsaved changes. Press OK to save first, or Cancel to continue without saving.');

        if (shouldSave) {
            isSubmitting = true;
            if (form) form.submit();
            return false;
        }

        return true;
    }

    function submitDuplicate(rowIds, placement) {
        if (!duplicateRowsForm || rowIds.length === 0) return;
        if (!passesUnsavedGuard()) return;

        duplicateRowsForm.querySelectorAll('input[name="row_ids[]"]').forEach(el => el.remove());
        duplicateRowsForm.querySelectorAll('input[name="placement"]').forEach(el => el.remove());

        // Only sent when the dialog asked for it. Absent means below_source,
        // which is what this action has always done.
        if (placement) {
            const placementInput = document.createElement('input');
            placementInput.type = 'hidden';
            placementInput.name = 'placement';
            placementInput.value = placement;
            duplicateRowsForm.appendChild(placementInput);
        }

        rowIds.forEach(function (id) {
            const hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = 'row_ids[]';
            hidden.value = id;
            duplicateRowsForm.appendChild(hidden);
        });

        isSubmitting = true;
        clearDraft();
        duplicateRowsForm.submit();
    }

    // Clearing the badge navigates and saves, so it takes the same unsaved-work
    // guard as duplicating. The URL is built from a template rather than being
    // rendered per row, so 550 rows do not each carry their own form.
    const dismissDuplicateForm = document.getElementById('dismiss-duplicate-form');
    const dismissDuplicateUrlTemplate = @json(route('uploaded-files.rows.dismiss_duplicate', ['excelFile' => $excelFile->id, 'row' => '__ROW__']));

    document.addEventListener('click', function (event) {
        const clearBtn = event.target.closest('.js-dismiss-duplicate');

        if (clearBtn && dismissDuplicateForm) {
            event.preventDefault();

            if (!confirm('Clear the duplicate marker on row ' + clearBtn.dataset.rowNumber + '? The row keeps its data; only the badge is removed.')) {
                return;
            }

            if (!passesUnsavedGuard()) return;

            dismissDuplicateForm.action = dismissDuplicateUrlTemplate.replace('__ROW__', clearBtn.dataset.rowId);
            isSubmitting = true;
            clearDraft();
            dismissDuplicateForm.submit();
            return;
        }
    });

    /* No confirm() here any more. The button opens the Duplicate Rows dialog
       (data-bs-toggle on the markup), which asks where the copies go and is
       itself the confirmation step — a browser confirm on top of it would be a
       second question about a decision already made. */

    /* Duplicate a whole style, as a block.
     *
     * Submits NO row ids — the form carries only the three style filter values
     * and the server re-resolves the group. So the count named in the confirm
     * is the server's exact-match count rendered into the button, not anything
     * this script counted off the page.
     *
     * The confirm names the style and says where the copies land, because this
     * is the widest of the three duplicate actions and the only one that can
     * touch rows the merchant cannot currently see.
     */
    const duplicateMatchingForm = document.getElementById('duplicate-matching-form');
    const duplicateMatchingButton = document.getElementById('duplicateMatchingButton');

    if (duplicateMatchingButton && duplicateMatchingForm) {
        duplicateMatchingButton.addEventListener('click', function () {
            const count = parseInt(this.dataset.count, 10) || 0;
            const styleLabel = this.dataset.styleLabel || '';

            if (count === 0) return;

            const message = 'Duplicate all ' + count + ' row' + (count === 1 ? '' : 's')
                + ' for ' + styleLabel + '?\n\n'
                + 'The copies are inserted together, directly after the last row of that style.\n'
                + 'Only rows matching this Buyer, Style and Season exactly are copied.';

            if (!confirm(message)) return;
            if (!passesUnsavedGuard()) return;

            isSubmitting = true;
            clearDraft();
            duplicateMatchingForm.submit();
        });
    }

    /* Deleting a row somebody has just added.
     *
     * The button is only rendered on rows the server already decided are
     * deletable (ExcelRow::deletableIdsFor, batched for the whole page), so
     * this handler does not re-decide anything — it confirms and posts. If the
     * row stopped qualifying between page load and click, deleteRow() refuses
     * and says why; the screen is never the guard.
     *
     * One form reused for every row, built from a template, so 150 rows do not
     * each carry their own — the same approach the dismiss-badge button takes. */
    /* Delete Selected. Posts the WHOLE selection, not the eligible subset the
     * button counted: the server re-runs the same batched check and is the
     * thing that decides, so sending a client-filtered list would only hide a
     * disagreement rather than prevent one. The count in the confirm is the
     * client's estimate of what will go; the flash afterwards is the server's
     * account of what did. */
    const deleteSelectedForm = document.getElementById('delete-selected-form');

    if (deleteSelectedButton && deleteSelectedForm) {
        deleteSelectedButton.addEventListener('click', function () {
            const ids = selectedRowIds();
            const eligible = (this.dataset.eligibleIds || '').split(',').filter(Boolean);

            if (eligible.length === 0) return;

            const skipped = ids.length - eligible.length;

            let message = eligible.length === 1
                ? 'Delete 1 row?'
                : 'Delete ' + eligible.length + ' rows?';

            if (skipped > 0) {
                message += ' ' + skipped + ' of the ' + ids.length
                    + ' rows you selected cannot be deleted and will be left alone.';
            }

            message += ' This cannot be undone.';

            if (!confirm(message)) return;
            if (!passesUnsavedGuard()) return;

            deleteSelectedForm.querySelectorAll('input[name="row_ids[]"]').forEach(el => el.remove());

            ids.forEach(function (id) {
                const hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = 'row_ids[]';
                hidden.value = id;
                deleteSelectedForm.appendChild(hidden);
            });

            isSubmitting = true;
            clearDraft();
            deleteSelectedForm.submit();
        });
    }

    const deleteRowForm = document.getElementById('delete-row-form');
    const deleteRowUrlTemplate = @json(route('uploaded-files.rows.destroy', ['excelFile' => $excelFile->id, 'row' => '__ROW__']));

    document.addEventListener('click', function (event) {
        const deleteBtn = event.target.closest('.js-delete-row');

        if (!deleteBtn || !deleteRowForm) {
            return;
        }

        event.preventDefault();

        // Same shape as the other destructive confirms on this screen: say what
        // goes, and that it cannot be undone.
        if (!confirm('Delete row ' + deleteBtn.dataset.rowNumber + '? The row and its values are removed and cannot be recovered.')) {
            return;
        }

        if (!passesUnsavedGuard()) return;

        deleteRowForm.action = deleteRowUrlTemplate.replace('__ROW__', deleteBtn.dataset.rowId);
        isSubmitting = true;
        clearDraft();
        deleteRowForm.submit();
    });

    /* ---- Duplicate Rows dialog --------------------------------------------
     *
     * What used to be "Add New Order". That dialog searched for a source row
     * through four filter boxes before it would let you do anything; the rows
     * are now simply the ones ticked on the grid, so all this asks is where the
     * copies go, and offers the edit step the old dialog wrapped.
     *
     * The write paths are unchanged: "Duplicate now" posts the same
     * duplicate-rows form Duplicate Selected always posted, and "Edit key
     * fields first" posts the same add-order form step 2 always posted. Only
     * the way in changed. */
    const duplicateModalEl = document.getElementById('duplicateRowsModal');

    if (duplicateModalEl) {
        const addOrderForm = document.getElementById('addOrderForm');
        const addOrderSourceRowId = document.getElementById('addOrderSourceRowId');
        const addOrderPlacement = document.getElementById('addOrderPlacement');
        const addOrderStepSearch = document.getElementById('addOrderStepSearch');
        const addOrderStepEdit = document.getElementById('addOrderStepEdit');
        const addOrderSubmitButton = document.getElementById('addOrderSubmitButton');
        const addOrderBackButton = document.getElementById('addOrderBackButton');
        const addOrderSourceStrip = document.getElementById('addOrderSourceStrip');
        const duplicateSelectionSummary = document.getElementById('duplicateSelectionSummary');
        const duplicateNowButton = document.getElementById('duplicateNowButton');
        const duplicateEditFirstButton = document.getElementById('duplicateEditFirstButton');
        const duplicateEditFirstNote = document.getElementById('duplicateEditFirstNote');

        function chosenPlacement() {
            const checked = duplicateModalEl.querySelector('input[name="ao_placement"]:checked');

            return checked ? checked.value : 'below_source';
        }

        function aoShowStep(step) {
            addOrderStepSearch.classList.toggle('d-none', step !== 'search');
            addOrderStepEdit.classList.toggle('d-none', step !== 'edit');

            /* HIDDEN in step 1, not merely disabled. Step 1 has its own
             * "Duplicate now", and a greyed-out "Duplicate row" sitting beside
             * it in the footer is two buttons with nearly the same name, one of
             * which does nothing — the merchant has to work out which is real. */
            addOrderSubmitButton.disabled = step !== 'edit';
            addOrderSubmitButton.classList.toggle('d-none', step !== 'edit');
        }

        /* Reopened mid-edit, the dialog would otherwise still show the previous
         * selection and the previous step. */
        function aoReset() {
            const ids = selectedRowIds();

            duplicateSelectionSummary.textContent = ids.length === 1
                ? '1 row selected'
                : ids.length + ' rows selected';

            /* One row at a time, because performRowDuplication() discards
             * overrides for more than one source — there would be no saying
             * which copy the typed values belonged to. Disabled and explained,
             * rather than accepting edits it would then throw away. */
            const single = ids.length === 1;
            duplicateEditFirstButton.disabled = !single;
            duplicateEditFirstNote.classList.toggle('d-none', single);

            addOrderSourceRowId.value = '';
            addOrderPlacement.value = 'below_source';
            duplicateModalEl.querySelectorAll('.ao-input').forEach(el => { el.value = ''; });

            const belowRadio = document.getElementById('duplicatePlaceBelow');
            if (belowRadio) belowRadio.checked = true;

            aoShowStep('search');
        }

        if (duplicateNowButton) {
            duplicateNowButton.addEventListener('click', function () {
                const ids = selectedRowIds();

                if (ids.length === 0) return;

                submitDuplicate(ids, chosenPlacement());
            });
        }

        if (duplicateEditFirstButton) {
            duplicateEditFirstButton.addEventListener('click', function () {
                const ids = selectedRowIds();

                if (ids.length !== 1) return;

                addOrderSourceRowId.value = ids[0];
                // Carried across so the choice made in step 1 still applies.
                addOrderPlacement.value = chosenPlacement();

                const sourceRow = document.querySelector('tr[data-row-id="' + ids[0] + '"]');
                const rowNumber = sourceRow ? sourceRow.dataset.rowNumber : '';

                if (addOrderSourceStrip) {
                    addOrderSourceStrip.textContent = 'Copying row ' + rowNumber + ' — '
                        + (chosenPlacement() === 'end_of_file'
                            ? 'the copy will be added at the end of the file.'
                            : 'the copy will be inserted directly below it.');
                }

                aoShowStep('edit');
            });
        }

        if (addOrderBackButton) {
            addOrderBackButton.addEventListener('click', () => aoShowStep('search'));
        }

        /* The dialog navigates on submit, so it takes the same unsaved-work
         * guard every other navigating control on this page takes: without it
         * the redirect would silently discard pending cell edits in the sheet
         * behind it. */
        if (addOrderForm) {
            addOrderForm.addEventListener('submit', function (event) {
                if (!addOrderSourceRowId.value) {
                    event.preventDefault();
                    return;
                }

                if (!passesUnsavedGuard()) {
                    event.preventDefault();
                    return;
                }

                isSubmitting = true;
                clearDraft();
            });
        }

        duplicateModalEl.addEventListener('show.bs.modal', aoReset);
    }

    refreshDuplicateSelection();

    if (addRowButton && addRowForm) {
        addRowButton.addEventListener('click', function () {
            const changed = getChangedFields();

            if (hasRealChanges(changed)) {
                const shouldSave = confirm('You have unsaved changes. Press OK to save first, or Cancel to continue without saving.');

                if (shouldSave) {
                    isSubmitting = true;
                    if (form) {
                        form.submit();
                    }
                    return;
                }
            }

            addRowForm.submit();
        });
    }

    if (successAlert) {
        setTimeout(() => {
            successAlert.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
            successAlert.style.opacity = '0';
            successAlert.style.transform = 'translateY(-8px)';

            setTimeout(() => {
                successAlert.remove();
            }, 500);
        }, 2500);
    }

    window.addEventListener('beforeunload', function () {
        if (!isSubmitting && userEditedWorksheet) {
            saveDraftToLocal(true);
        }
    });

    restoreDraftIfNeeded();
});
</script>
@endsection