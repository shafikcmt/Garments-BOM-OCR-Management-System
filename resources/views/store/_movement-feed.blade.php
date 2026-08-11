{{--
    Recent stock movement, grouped by day.

    Shared by both Store dashboards so the two cannot drift apart: General
    Stock passes its purchases and issues, Buyer / Style passes its receivings
    and bulk issues, and the shape of a row is decided here once.

    Grouping is what saves the space. Every row used to end in its own
    "3 hours ago"; the day is now said once in a heading and the row carries
    only the movement. Rows keep the existing timeline component, so the
    marker, tone and icon are unchanged.

    Expects: $rows (direction, label, qty, uom, date), $fmt
--}}
@php
    // Keys are dates so they sort; labels are what the reader wants to see.
    $grouped = collect($rows)
        ->filter(fn ($row) => $row['date'] !== null)
        ->groupBy(fn ($row) => optional($row['date'])->toDateString())
        ->sortKeysDesc();

    // A row with no date at all still belongs in the feed rather than being
    // silently dropped, so it lands in its own group at the end.
    $undated = collect($rows)->filter(fn ($row) => $row['date'] === null);

    $dayLabel = function (string $date) {
        $d = \Illuminate\Support\Carbon::parse($date);

        return match (true) {
            $d->isToday() => 'Today',
            $d->isYesterday() => 'Yesterday',
            $d->isCurrentYear() => $d->format('j M'),
            default => $d->format('j M Y'),
        };
    };
@endphp

@forelse($grouped as $date => $dayRows)
    <div class="gx-feed-day">{{ $dayLabel($date) }}</div>

    <x-timeline :items="collect($dayRows)->map(fn ($row) => [
        'tone' => $row['direction'] === 'in' ? 'success' : 'warning',
        'icon' => $row['direction'] === 'in' ? 'box-arrow-in-down' : 'box-arrow-up',
        'title' => $row['label'],
        'description' => ($row['direction'] === 'in' ? 'Received ' : 'Issued ')
            .$fmt($row['qty']).' '.($row['uom'] ?: ''),
        'meta' => optional($row['date'])->format('H:i'),
    ])->all()" />
@empty
    @if($undated->isEmpty())
        <p class="text-muted small mb-0">No stock movement recorded yet.</p>
    @endif
@endforelse

@if($undated->isNotEmpty())
    <div class="gx-feed-day">Undated</div>

    <x-timeline :items="$undated->map(fn ($row) => [
        'tone' => $row['direction'] === 'in' ? 'success' : 'warning',
        'icon' => $row['direction'] === 'in' ? 'box-arrow-in-down' : 'box-arrow-up',
        'title' => $row['label'],
        'description' => ($row['direction'] === 'in' ? 'Received ' : 'Issued ')
            .$fmt($row['qty']).' '.($row['uom'] ?: ''),
    ])->all()" />
@endif
