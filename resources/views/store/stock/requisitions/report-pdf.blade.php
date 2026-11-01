{{--
    Monthly Purchase Requisition report — PDF, and the source view for every
    sheet of the Excel export ($forExcel).

    Reproduces the "Month_Of_<Month>.xlsx" workbook, ONE PAGE SET PER TAB:
    the company heading, the header block, the two-tier column header, a
    Total- row and the signature boxes.

    $tabs: name => ['type', 'rows', 'header'] — ALL, each category, or each
    section, as the switch picks (MonthlyRequisitionReportService::tabs()).
    One Excel sheet is handed exactly one. Each tab's header comes from
    tabHeader() (owner, 2026-09-29):

      ALL / category tab: HAPL/Dept./ALL/<Year>/<Month>/01, Section ALL,
        Name of User ALL, Contact = Contact Person setting, FINAL boxes.
      Section tab: HAPL/Dept./<SECTION>/<Year>/<Month>/01, the section, its
        head's name and phone / email, DEPARTMENT boxes.

    The source sheet numbers are on the screen only, never printed here.
--}}
@php
    $forExcel = $forExcel ?? false;
    $report = app(\App\Services\MonthlyRequisitionReportService::class);

    // A caller that hands bare rows ($sections: name => rows, or $rows) gets
    // them as ALL-type tabs.
    $tabs = $tabs ?? collect($sections ?? ['ALL' => $rows])
        ->map(fn ($tabRows) => ['type' => \App\Services\MonthlyRequisitionReportService::TAB_ALL, 'rows' => collect($tabRows)]);
    $monthStart = $report->resolveMonth($month ?? null);

    // Excel gets bare numbers and blank cells: a "1,175" carrying a thousands
    // separator arrives as TEXT, which cannot be summed, sorted or number
    // formatted, and a "-" placeholder would make a numeric column read as
    // text. The separators and dashes are Excel's job, applied by
    // FormatsStoreSheet. The PDF, which is just ink, keeps them here.
    $qty = $forExcel
        ? fn ($v) => \App\Support\Quantity::wholePlain($v)
        : fn ($v) => \App\Support\Quantity::whole($v, '-');

    $money = $forExcel
        ? fn ($v) => $v === null ? '' : number_format((float) $v, 2, '.', '')
        : fn ($v) => $v === null ? '-' : number_format((float) $v, 2);

    $date = $forExcel
        ? fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->format('d-M-y') : ''
        : fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->format('d-M-y') : '-';

    $dash = fn ($v) => ($v === null || trim((string) $v) === '') ? '—' : $v;

    $typeLabels = \App\Models\PurchaseRequisitionItem::typeLabels();

    // Letterhead: always the company. "Name of Unit": the requisition setting
    // (Admin > Requisition Signatures), defaulting to the company name.
    $unit = config('stock.company_name');
    $unitName = \App\Services\RequisitionHeaderResolver::unitName();

    // The Store Personnel box — the same on every tab: the user chosen on
    // Admin > Requisition Signatures, else the Store department head.
    $storePersonnel = app(\App\Services\RequisitionHeaderResolver::class)->storePersonnel();
@endphp
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    @include("store.stock.requisitions._report-styles")
</head>
<body>

@foreach($tabs as $name => $tab)
    @php
        $tabRows = $tab['rows'];
        $header = $tab['header'] ?? $report->tabHeader($tab['type'], (string) $name, $tabRows, $monthStart);

        // Section-wise: the stock column is the part allocated to the
        // section, and the page says how it was allocated.
        $sectionWise = $tab['type'] === \App\Services\MonthlyRequisitionReportService::TAB_SECTION;
        $stockLabel = $sectionWise ? 'Stock Allocated' : 'Stock in Hand';
        $stockNote = $sectionWise ? \App\Services\MonthlyRequisitionReportService::SECTION_STOCK_NOTE : null;

        // Left column: what this document is — the same six lines as the
        // single sheet's document.
        $leftRows = [
            ['Requisition No', $header['requisition_no']],
            ['Requisition Date', $monthLabel],
            ['Name of Unit', $unitName],
            ['Section', $dash($header['section'])],
            ['Name of User', $dash($header['user_name'])],
            ['Contact No. / Email', $dash($header['contact'])],
        ];
    @endphp

    @unless($forExcel)
        <div class="{{ $loop->first ? '' : 'page-break' }}">
            <table class="top">
                <tr>
                    <td style="width:25%;">&nbsp;</td>
                    <td style="width:50%;">
                        <div class="company">{{ $unit }}</div>
                        <div class="title">{{ $title }}</div>
                    </td>
                    <td style="width:25%;" class="date-area">
                        <div>Printed On</div>
                        <div>{{ \App\Support\LocalTime::now()->format('d M Y, h:i A') }}</div>
                    </td>
                </tr>
            </table>

            @php
                // The Store Personnel box, as on the Excel sheet. No item count
                // or amount here: the reference layout has none, and the total
                // is on the Total- row.
                $rightRows = [
                    ['Store Personnel', $dash($storePersonnel['name'])],
                    ['Designation', $dash($storePersonnel['designation'])],
                    ['Department', $dash($storePersonnel['department'])],
                    ['Email', $dash($storePersonnel['email'])],
                ];
            @endphp
            <table class="head-block">
                @foreach($leftRows as $i => [$label, $value])
                    <tr>
                        <td class="label">{{ $label }}</td><td class="value">{{ $value }}</td>
                        <td class="label">{{ $rightRows[$i][0] ?? '' }}</td><td class="value">{{ $rightRows[$i][1] ?? '' }}</td>
                    </tr>
                @endforeach
            </table>

            <div class="section-title">
                {{ $name }} — {{ $tabRows->count() }} item(s)
                @if($stockNote)<span class="note"> · {{ $stockNote }}</span>@endif
            </div>

            @include('store.stock.requisitions._report-rows', ['rows' => $tabRows, 'stockLabel' => $stockLabel])

            {{-- The tab's boxes from Admin > Settings > Requisition Signatures:
                 the final sheet's on ALL and category tabs, the department
                 sheet's on section tabs — nothing here is typed in. --}}
            @include('store.stock.requisitions._signature-block', ['signatures' => $header['signatures'], 'forExcel' => false])
        </div>
    @else
        {{-- Excel letterhead. The colspans ARE the merges: the HTML reader
             turns them into merged cells, giving the reference workbook's
             layout — company across the full width, title across the full
             width, then each information line as label (A:D) + value (E:M)
             with the store personnel block running down the right (N:U).

             Type sizes, row heights and alignment come from FormatsStoreSheet;
             HTML cannot carry them into a spreadsheet. --}}
        @php
            $columns = 21;
            $labelSpan = 4;
            $valueSpan = 9;
            $rightSpan = $columns - $labelSpan - $valueSpan;

            $rightCells = [
                'Store Personnel',
                $dash($storePersonnel['name']),
                $dash($storePersonnel['designation']),
                $dash($storePersonnel['department']),
                $dash($storePersonnel['email']),
                '',
            ];
        @endphp
        <table>
            <tr><td colspan="{{ $columns }}">{{ $unit }}</td></tr>
            <tr><td colspan="{{ $columns }}">{{ $title }}</td></tr>
            @foreach($leftRows as $i => [$label, $value])
                <tr>
                    <td colspan="{{ $labelSpan }}">{{ $label }}</td>
                    <td colspan="{{ $valueSpan }}">{{ $value }}</td>
                    <td colspan="{{ $rightSpan }}">{{ $rightCells[$i] }}</td>
                </tr>
            @endforeach
            <tr><td colspan="{{ $columns }}">{{ $stockNote }}</td></tr>
        </table>

        @include('store.stock.requisitions._report-rows', ['rows' => $tabRows, 'stockLabel' => $stockLabel])

        @include('store.stock.requisitions._signature-block', ['signatures' => $header['signatures'], 'forExcel' => true, 'columns' => 21])
    @endunless
@endforeach

@unless($forExcel)
    <div class="footer">
        {{ $unit }} &mdash; {{ $title }} &mdash; {{ $monthLabel }}
        <span class="right">Generated {{ \App\Support\LocalTime::now()->format('d M Y') }}</span>
    </div>
@endunless

</body>
</html>
