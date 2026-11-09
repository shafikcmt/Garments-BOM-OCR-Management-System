{{--
    Monthly / Individual, side by side: three pills over one list. A tab keeps
    the other filters and drops the page number.

    Expects: $route (list route name), $current ('' | 'monthly' | 'individual'),
    $counts (type => count).
--}}
@php
    $monthly = (int) ($counts[\App\Models\PurchaseRequisition::TYPE_MONTHLY] ?? 0);
    $individual = (int) ($counts[\App\Models\PurchaseRequisition::TYPE_INDIVIDUAL] ?? 0);
    $pills = [
        '' => ['All', 'bi-list-ul', $monthly + $individual],
        \App\Models\PurchaseRequisition::TYPE_MONTHLY => ['Monthly', 'bi-calendar3', $monthly],
        \App\Models\PurchaseRequisition::TYPE_INDIVIDUAL => ['Individual', 'bi-person', $individual],
    ];
@endphp
<ul class="nav nav-pills gap-1 mb-3" aria-label="Requisition kind">
    @foreach($pills as $value => [$label, $icon, $count])
        <li class="nav-item">
            <a class="nav-link py-1 px-3 {{ ($current ?? '') === (string) $value ? 'active' : '' }}"
               href="{{ route($route, array_filter(request()->except(['type', 'page']) + ['type' => $value], fn ($v) => $v !== null && $v !== '')) }}">
                <i class="bi {{ $icon }} me-1" aria-hidden="true"></i>{{ $label }}
                <span class="badge bg-white text-body ms-1">{{ number_format($count) }}</span>
            </a>
        </li>
    @endforeach
</ul>
