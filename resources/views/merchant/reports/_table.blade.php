{{--
    Shared report table — the single source of truth for the screen preview, the
    PDF and the Excel sheet. Emits plain semantic markup only; each medium
    supplies its own CSS, so the three outputs can never show different columns
    or different values.

    $columns is a Collection of ExcelHeader models, ALWAYS supplied by
    MerchantReportService (all 35 for screen/Excel, the summary subset for PDF).
    This file never decides which columns exist — that boundary lives in the
    service, in one place.

    Numbers print without thousand separators on purpose: it keeps the text
    identical everywhere and lets Excel read the cells as real numbers.
--}}
@php
    $startIndex = $startIndex ?? 0;
    $span = $columns->count() + 1;
@endphp
<table class="report-table">
    <thead>
        <tr>
            <th class="col-sl">SL</th>
            @foreach($columns as $column)
                <th class="{{ $column->field_type === 'number' ? 'num' : '' }} col-{{ $column->header_key }}">{{ $column->header_name }}</th>
            @endforeach
        </tr>
    </thead>
    <tbody>
        @forelse($rows as $index => $row)
            <tr>
                <td class="col-sl">{{ $startIndex + $index + 1 }}</td>
                @foreach($columns as $column)
                    <td class="{{ $column->field_type === 'number' ? 'num' : '' }}">{{ $row['values'][$column->header_key] ?? '' }}</td>
                @endforeach
            </tr>
        @empty
            <tr>
                <td colspan="{{ $span }}" class="empty">No BOM lines found for the selected filters.</td>
            </tr>
        @endforelse
    </tbody>
</table>
