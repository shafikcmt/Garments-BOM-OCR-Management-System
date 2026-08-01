{{-- Bulk Issuing — Full Table view.

     One row per issued item line, in the column order of the reference
     workbook's "Bulk Issuing" tab (docs/Fabric-Hugo Boss-Closing Stock
     Report.xlsx, header row 5, columns A–U). Every column carries an
     Excel-style filter, mirroring that sheet's AutoFilter, which covers the
     whole range rather than a marked subset.

     Rendered inside the same swappable partial as the Summary table so a tab /
     search / sort / page change refreshes both at once.

     Expects: $issues (paginator), and the view-mode scope from the parent
     x-data (`mode`). --}}
@php
    $num = fn ($v) => rtrim(rtrim(number_format((float) $v, 4), '0'), '.');

    // Column order and headers taken from the reference workbook. "Art. No"
    // keeps its dot; "Dead Stock Qty" corrects the sheet's "Stcok" typo.
    // "Issued By" exists in no tab of that workbook — it is added here, last,
    // as record provenance, so the A–U order above it still matches the file.
    $fullColumns = [
        ['key' => 'issue_date', 'label' => 'Issue Date', 'type' => 'date'],
        ['key' => 'indent_section', 'label' => 'Indent Section'],
        ['key' => 'indent_person', 'label' => 'Indent Person'],
        ['key' => 'requisition_number', 'label' => 'Requisition Number'],
        ['key' => 'season_name', 'label' => 'Season'],
        ['key' => 'buyer_name', 'label' => 'Buyer Name'],
        ['key' => 'style_name', 'label' => 'Style Number'],
        ['key' => 'po_no', 'label' => 'PO Number'],
        ['key' => 'gmts_color_name', 'label' => 'GMTS Color Name'],
        ['key' => 'material_name', 'label' => 'Material Name'],
        ['key' => 'material_description', 'label' => 'Material Description', 'wide' => true],
        ['key' => 'art_no', 'label' => 'Art. No'],
        ['key' => 'sap_code', 'label' => 'SAP Code'],
        ['key' => 'material_color', 'label' => 'Material Color'],
        ['key' => 'size', 'label' => 'Size'],
        ['key' => 'uom', 'label' => 'Unit'],
        ['key' => 'bulk_qty', 'label' => 'Bulk Issued Qty', 'type' => 'num'],
        ['key' => 'sample_qty', 'label' => 'Sample Issued Qty', 'type' => 'num'],
        ['key' => 'liability_qty', 'label' => 'Liability Stock Qty', 'type' => 'num'],
        ['key' => 'dead_qty', 'label' => 'Dead Stock Qty', 'type' => 'num'],
        ['key' => 'remarks', 'label' => 'Remarks', 'wide' => true],
        ['key' => 'issued_by', 'label' => 'Issued By'],
    ];

    // Rows as data, not markup: the filter has to sort them, and reordering
    // server-rendered <tr>s is how a table and its data drift apart.
    $fullRows = $issues->map(fn ($i) => [
        'id' => $i->id,
        'issue_date' => optional($i->issue_date)->toDateString(),
        'issue_date_label' => optional($i->issue_date)->format('d-M-Y'),
        'indent_section' => $i->indent_section,
        'indent_person' => $i->indent_person,
        'requisition_number' => $i->requisition_number,
        'season_name' => $i->season_name,
        'buyer_name' => $i->buyer_name,
        'style_name' => $i->style_name,
        'po_no' => $i->po_no,
        'gmts_color_name' => $i->gmts_color_name,
        'material_name' => $i->material_name,
        'material_description' => $i->material_description,
        'art_no' => $i->art_no,
        'sap_code' => $i->sap_code,
        'material_color' => $i->material_color,
        'size' => $i->size,
        'uom' => $i->uom,
        'bulk_qty' => $num($i->bulk_qty),
        'sample_qty' => $num($i->sample_qty),
        'liability_qty' => $num($i->liability_qty),
        'dead_qty' => $num($i->dead_qty),
        'remarks' => $i->remarks,
        // createdBy is eager-loaded by the controller, so this costs no query.
        'issued_by' => optional($i->createdBy)->name,
    ])->values();
@endphp

<div x-show="mode === 'full'" x-cloak
     x-data="bulkIssueFullTable({
        columns: {{ Illuminate\Support\Js::from($fullColumns) }},
        rows: {{ Illuminate\Support\Js::from($fullRows) }},
        routes: {
            exportExcel: @js(route('store.material.bulk-issues.export.excel')),
            exportPdf: @js(route('store.material.bulk-issues.export.pdf'))
        },
        csrf: @js(csrf_token())
     })"
     @keydown.escape="openKey = ''"
     @click.outside="openKey = ''">

    {{-- Filter status + the exports that follow it. Stated above the table
         because after two or three column filters the row count on screen is no
         longer self-explanatory. --}}
    <div class="bi-ft-bar">
        <div class="d-flex flex-wrap align-items-center gap-2">
            <span class="bi-ft-count">
                <span x-text="visibleRows.length"></span> of {{ $issues->count() }} row(s) on this page
            </span>
            <span class="bi-ft-chip" x-show="activeFilterCount > 0" x-cloak>
                <i class="bi bi-funnel-fill" aria-hidden="true"></i>
                <span x-text="activeFilterCount"></span> column filter(s)
            </span>
            <span class="bi-ft-chip is-sel" x-show="selected.length > 0" x-cloak>
                <i class="bi bi-check2-square" aria-hidden="true"></i>
                <span x-text="selected.length"></span> selected
            </span>
            <button type="button" class="btn btn-sm btn-link text-decoration-none p-0"
                    x-show="activeFilterCount > 0 || sortKey" x-cloak @click="clearAll()">Clear all filters</button>
        </div>

        <div class="d-flex flex-wrap align-items-center gap-2">
            {{-- Ticked rows if there are any, otherwise everything the filters
                 leave showing — so a filtered view exports as it reads. --}}
            <button type="button" class="btn btn-sm btn-outline-success" @click="submitExport(routes.exportExcel)">
                <i class="bi bi-file-earmark-excel me-1" aria-hidden="true"></i>Excel
            </button>
            <button type="button" class="btn btn-sm btn-outline-danger" @click="submitExport(routes.exportPdf)">
                <i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>PDF
            </button>
            <button type="button" class="btn btn-sm btn-outline-secondary" @click="printView()">
                <i class="bi bi-printer me-1" aria-hidden="true"></i>Print
            </button>
            <span class="bi-ft-hint" x-text="selected.length ? 'Exports the ' + selected.length + ' selected row(s)' : 'Exports the filtered rows shown'"></span>
        </div>
    </div>

    {{-- No overflow wrapper on purpose: a scroll container would anchor the
         sticky header to itself and stop it sticking to the page. The table is
         allowed to run wider than the viewport and the page scrolls sideways,
         which is what keeps the header row frozen while reading. --}}
    <table class="bi-fulltable">
        <thead>
            <tr>
                <th class="bi-ft-check">
                    <input type="checkbox" class="form-check-input"
                           :checked="allVisibleSelected" @change="toggleSelectAll()"
                           aria-label="Select all rows shown">
                </th>
                @foreach($fullColumns as $col)
                    <th class="{{ ($col['wide'] ?? false) ? 'bi-ft-wide' : '' }} {{ ($col['type'] ?? '') === 'num' ? 'bi-ft-num' : '' }}">
                        <div class="bi-ft-head">
                            <span class="bi-ft-label">{{ $col['label'] }}</span>
                            <button type="button" class="bi-ft-fbtn"
                                    :class="{ 'is-on': isFiltered(@js($col['key'])) || sortKey === @js($col['key']) }"
                                    @click.stop="toggleMenu(@js($col['key']))"
                                    :aria-expanded="openKey === @js($col['key'])"
                                    aria-label="Filter {{ $col['label'] }}">
                                <i class="bi" :class="isFiltered(@js($col['key'])) ? 'bi-funnel-fill' : 'bi-chevron-down'" aria-hidden="true"></i>
                            </button>
                        </div>

                        {{-- Excel's own dropdown, in the same order: the two
                             sorts, then a search, then Select All over the value
                             list. --}}
                        <div class="bi-ft-menu" x-show="openKey === @js($col['key'])" x-cloak
                             @click.stop x-transition.opacity.duration.120ms>
                            <button type="button" class="bi-ft-mitem" @click="sortBy(@js($col['key']), 'asc')">
                                <i class="bi bi-sort-alpha-down" aria-hidden="true"></i>Sort A to Z
                            </button>
                            <button type="button" class="bi-ft-mitem" @click="sortBy(@js($col['key']), 'desc')">
                                <i class="bi bi-sort-alpha-up-alt" aria-hidden="true"></i>Sort Z to A
                            </button>
                            <div class="bi-ft-msep"></div>

                            <div class="bi-ft-msearch">
                                <i class="bi bi-search" aria-hidden="true"></i>
                                <input type="text" class="form-control form-control-sm"
                                       placeholder="Search {{ $col['label'] }}…"
                                       x-model="needles[@js($col['key'])]"
                                       aria-label="Search values in {{ $col['label'] }}">
                            </div>

                            <label class="bi-ft-mall">
                                <input type="checkbox" class="form-check-input"
                                       :checked="allChecked(@js($col['key']))"
                                       @change="toggleAll(@js($col['key']))">
                                <span>(Select All)</span>
                            </label>

                            <div class="bi-ft-mlist">
                                <template x-for="value in valuesFor(@js($col['key']))" :key="value">
                                    <label class="bi-ft-mopt">
                                        <input type="checkbox" class="form-check-input"
                                               :checked="isChecked(@js($col['key']), value)"
                                               @change="toggleValue(@js($col['key']), value)">
                                        <span x-text="value"></span>
                                    </label>
                                </template>
                                <div class="bi-ft-mempty" x-show="valuesFor(@js($col['key'])).length === 0">No matching values</div>
                            </div>

                            <div class="bi-ft-mfoot">
                                <button type="button" class="btn btn-sm btn-link text-decoration-none p-0"
                                        @click="clearFilter(@js($col['key']))">Clear filter</button>
                                <button type="button" class="btn btn-sm btn-primary bi-btn-xs" @click="openKey = ''">Done</button>
                            </div>
                        </div>
                    </th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            <template x-for="row in visibleRows" :key="row.id">
                <tr :class="{ 'is-picked': selected.includes(row.id) }">
                    <td class="bi-ft-check">
                        <input type="checkbox" class="form-check-input" :value="row.id" x-model="selected"
                               :aria-label="'Select issue ' + row.po_no">
                    </td>
                    @foreach($fullColumns as $col)
                        @php
                            $isNum = ($col['type'] ?? '') === 'num';
                            $isDate = ($col['type'] ?? '') === 'date';
                            $bind = $isDate ? 'row.issue_date_label' : 'row.'.$col['key'];
                        @endphp
                        <td class="{{ $isNum ? 'bi-ft-num' : '' }} {{ ($col['wide'] ?? false) ? 'bi-ft-wide' : '' }}"
                            @if($col['wide'] ?? false) :title="{{ $bind }} || ''" @endif>
                            <span x-text="{{ $bind }} || '—'"
                                  :class="({{ $bind }} === null || {{ $bind }} === '') ? 'bi-ft-blank' : ''"></span>
                        </td>
                    @endforeach
                </tr>
            </template>
            <tr x-show="visibleRows.length === 0" x-cloak>
                <td colspan="{{ count($fullColumns) + 1 }}" class="text-center py-5">
                    <span class="bi-empty-icon d-inline-flex"><i class="bi bi-funnel" aria-hidden="true"></i></span>
                    <div class="bi-empty-title mt-1">No rows match these filters</div>
                    <p class="bi-empty-text mb-0">Clear one of the column filters to widen the view.</p>
                </td>
            </tr>
        </tbody>
    </table>
</div>
