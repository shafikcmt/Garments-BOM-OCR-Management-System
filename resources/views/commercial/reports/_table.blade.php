{{--
    Shared report table — the single source of truth for the screen preview, the
    PDF and the Excel sheet. Emits plain semantic markup only; each medium
    supplies its own CSS, so the three outputs can never show different columns
    or different values.

    $columns is a Collection of ExcelHeader models, ALWAYS supplied by
    CommercialReportService (all 25 for screen/Excel, the summary subset for
    PDF). This file never decides which columns exist.

    $contextKeys names the borrowed Merchandising columns so the screen can mark
    them as read-only context. Optional — the PDF and Excel do not pass it.

    Numbers print without thousand separators on purpose: it keeps the text
    identical everywhere and lets Excel read the cells as real numbers.
--}}
@php
    $startIndex = $startIndex ?? 0;
    $contextKeys = $contextKeys ?? [];
    $span = $columns->count() + 1;
@endphp
<table class="report-table">
    <thead>
        <tr>
            <th class="col-sl">SL</th>
            @foreach($columns as $column)
                @php $isContext = in_array($column->header_key, $contextKeys, true); @endphp
                <th class="{{ $column->field_type === 'number' ? 'num' : '' }} {{ $isContext ? 'col-context' : '' }} col-{{ $column->header_key }}">{{ $column->header_name }}</th>
            @endforeach
        </tr>
    </thead>
    <tbody>
        @forelse($rows as $index => $row)
            <tr>
                <td class="col-sl">{{ $startIndex + $index + 1 }}</td>
                @foreach($columns as $column)
                    <td class="{{ $column->field_type === 'number' ? 'num' : '' }} {{ in_array($column->header_key, $contextKeys, true) ? 'col-context' : '' }}">{{ $row['values'][$column->header_key] ?? '' }}</td>
                @endforeach
            </tr>
        @empty
            <tr>
                <td colspan="{{ $span }}" class="empty">No BOM lines found for the selected filters.</td>
            </tr>
        @endforelse
    </tbody>
</table>
