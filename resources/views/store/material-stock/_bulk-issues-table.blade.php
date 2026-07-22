{{-- Swappable history table. Rendered on first load and returned on its own for
     every tab / search / sort / page change (partial=1), so JS can replace just
     this block. Expects: $issues, $counts, $tab, $q, $sort, $dir, $perPage. --}}
@php
    $num = fn ($v) => rtrim(rtrim(number_format((float) $v, 4), '0'), '.');
    // Arrow for a sortable header: dimmed ⇅ when inactive, coloured ↑/↓ when this
    // column drives the sort. Rendered right beside the label so the pairing reads.
    $arrow = function (string $col) use ($sort, $dir) {
        if ($sort !== $col) return '<i class="bi bi-arrow-down-up text-muted opacity-50" aria-hidden="true"></i>';
        return $dir === 'asc'
            ? '<i class="bi bi-arrow-up text-primary" aria-hidden="true"></i>'
            : '<i class="bi bi-arrow-down text-primary" aria-hidden="true"></i>';
    };
    $from = $issues->total() ? ($issues->firstItem() ?? 0) : 0;
    $to = $issues->total() ? ($issues->lastItem() ?? 0) : 0;

    // Department (Indent Section) colour set — deliberately DISTINCT from the
    // Bulk/Sample/Liability/Dead issue-type colours (green/blue/amber/red) so a
    // section badge never reads as a quantity type. [bg, text].
    $sectionColors = [
        'Cutting' => ['#EEF2FF', '#4338CA'],
        'Sewing' => ['#ECFEFF', '#0E7490'],
        'Finishing' => ['#F0FDFA', '#0F766E'],
        'Sample' => ['#F5F3FF', '#6D28D9'],
        'Embroidery' => ['#FDF4FF', '#A21CAF'],
        'Printing' => ['#FFF7ED', '#C2410C'],
        'Washing' => ['#F8FAFC', '#475569'],
        'Store' => ['#F1F5F9', '#334155'],
    ];
    $sectionStyle = fn ($s) => isset($sectionColors[$s])
        ? 'background:'.$sectionColors[$s][0].';color:'.$sectionColors[$s][1].';'
        : 'background:#F1F5F9;color:#475569;';

    // Correction rights. Defaulted so the partial still renders if it is ever
    // included without them (read-only is the safe fallback).
    $canEdit = $canEdit ?? false;
    $canDelete = $canDelete ?? false;
    $showActions = $canEdit || $canDelete;
    $colSpan = $showActions ? 8 : 7;
@endphp

{{-- Tab counts travel with the partial so an AJAX swap can refresh the badges. --}}
<span data-bi-counts='{{ json_encode($counts ?? []) }}' hidden></span>

<div class="table-responsive bi-table-wrap">
    <table class="table align-middle mb-0 bi-history-table" id="biHistoryTable">
        <thead>
            <tr class="text-muted small text-uppercase">
                <th style="width:40px;">
                    <input type="checkbox" class="form-check-input" id="biSelectAll" aria-label="Select all rows on this page">
                </th>
                <th>
                    <button type="button" class="btn btn-link btn-sm p-0 text-muted text-decoration-none fw-semibold text-uppercase" data-bi-sort="date">
                        Date {!! $arrow('date') !!}
                    </button>
                </th>
                <th>
                    <button type="button" class="btn btn-link btn-sm p-0 text-muted text-decoration-none fw-semibold text-uppercase" data-bi-sort="po">
                        PO / Material {!! $arrow('po') !!}
                    </button>
                </th>
                <th class="text-end">Bulk</th>
                <th class="text-end">Sample</th>
                <th class="text-end">Liab.</th>
                <th class="text-end">Dead</th>
                @if($showActions)<th class="text-end">Action</th>@endif
            </tr>
        </thead>
        <tbody>
            @forelse($issues as $i)
                <tr data-bi-row data-id="{{ $i->id }}">
                    <td data-label="Select">
                        <input type="checkbox" class="form-check-input bi-row-check" value="{{ $i->id }}" aria-label="Select this issue">
                    </td>
                    <td class="small" data-label="Date">{{ optional($i->issue_date)->format('d-M-Y') ?? '—' }}</td>
                    <td data-label="PO / Material">
                        <div class="fw-semibold">{{ $i->po_no }} · {{ $i->material_name ?: $i->material_description }}</div>
                        <div class="small text-muted">{{ collect([$i->buyer_name, $i->style_name, $i->material_color, $i->size])->filter()->implode(' · ') }}</div>
                        @if($i->indent_section)
                            <span class="badge bi-section-badge mt-1" style="{{ $sectionStyle($i->indent_section) }}"><i class="bi bi-diagram-3 me-1" aria-hidden="true"></i>{{ $i->indent_section }}</span>
                        @endif
                    </td>
                    <td class="text-end text-success" data-label="Bulk">{{ $num($i->bulk_qty) }}</td>
                    <td class="text-end text-primary" data-label="Sample">{{ $num($i->sample_qty) }}</td>
                    <td class="text-end text-warning" data-label="Liability">{{ $num($i->liability_qty) }}</td>
                    <td class="text-end text-danger" data-label="Dead">{{ $num($i->dead_qty) }}</td>
                    @if($showActions)
                        <td class="text-end" data-label="Action">
                            <div class="d-inline-flex gap-1">
                                @if($canEdit)
                                    <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-2" data-bi-edit="{{ $i->id }}" aria-label="Edit this entry" title="Edit"><i class="bi bi-pencil" aria-hidden="true"></i></button>
                                @endif
                                @if($canDelete)
                                    <form method="POST" action="{{ route('store.material.bulk-issues.destroy', $i) }}" onsubmit="return confirm('Remove this bulk issue? Closing stock will update.');">
                                        @csrf @method('DELETE')
                                        <button class="btn btn-sm btn-outline-danger rounded-pill px-2" aria-label="Delete this entry" title="Delete"><i class="bi bi-trash" aria-hidden="true"></i></button>
                                    </form>
                                @endif
                            </div>
                        </td>
                    @endif
                </tr>
            @empty
                <tr>
                    <td colspan="{{ $colSpan }}" class="text-center text-muted py-5">
                        <i class="bi bi-inbox d-block mb-2" style="font-size:26px;opacity:.4;" aria-hidden="true"></i>
                        @if($q !== '' || $tab !== 'all')
                            No bulk issues match this view.
                        @else
                            No bulk issues recorded yet.
                        @endif
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mt-3">
    <div class="d-flex align-items-center gap-3 small text-muted">
        <span>Showing {{ $from }}–{{ $to }} of {{ $issues->total() }}</span>
        <span class="d-flex align-items-center gap-2">
            <label for="biPerPage" class="mb-0">Show</label>
            <select id="biPerPage" class="form-select form-select-sm" style="width:auto;">
                @foreach([10,20,50,100] as $size)
                    <option value="{{ $size }}" {{ $perPage === $size ? 'selected' : '' }}>{{ $size }}</option>
                @endforeach
            </select>
            <span>per page</span>
        </span>
    </div>
    <div>{{ $issues->onEachSide(1)->links() }}</div>
</div>
