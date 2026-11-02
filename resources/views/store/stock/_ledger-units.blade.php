{{--
    Stock by Unit: Stock as on Date per unit of measure. A quantity total across
    Piece, Kg, Carton and Box adds up to nothing, so each unit is counted on
    its own. Most items first.

    $part = 'top' (the card: three units and a View all button) or 'all' (the list in the popup).
    Rendered inline and again inside the live filter's meta, so both read alike.
--}}
@php
    $units = collect($summary['by_unit'] ?? []);
    $fmt = fn ($v) => rtrim(rtrim(number_format((float) $v, 4, '.', ','), '0'), '.');
@endphp
@if($part === 'top')
    @php($palette = ['#2563eb', '#16a34a', '#d97706'])
    @forelse($units->take(3) as $u)
        <div class="gx-unit-line" title="{{ number_format($u['items']) }} items">
            <span class="gx-unit-name"><i style="background:{{ $palette[$loop->index % 3] }}"></i>{{ $u['unit'] }}</span>
            <b>{{ $fmt($u['stock']) }}</b>
        </div>
    @empty
        <div class="gx-unit-line"><span class="text-muted">No counted items</span></div>
    @endforelse
    @if($units->count() > 3)
        <button type="button" class="gx-unit-all" data-bs-toggle="modal" data-bs-target="#stockUnitsModal">View all {{ $units->count() }} units <i class="bi bi-arrow-right" aria-hidden="true"></i></button>
    @endif
@else
    @php($totalItems = max(1, $units->sum('items')))
    <div class="gx-unit-table">
        @foreach($units as $u)
            <div class="gx-unit-row">
                <div class="gx-unit-row-head">
                    <span class="fw-semibold">{{ $u['unit'] }}</span>
                    <span class="text-muted small">{{ number_format($u['items']) }} {{ $u['items'] === 1 ? 'item' : 'items' }}</span>
                    <b>{{ $fmt($u['stock']) }}</b>
                </div>
                <div class="gx-unit-bar"><i style="width:{{ round($u['items'] / $totalItems * 100, 1) }}%"></i></div>
            </div>
        @endforeach
    </div>
@endif
