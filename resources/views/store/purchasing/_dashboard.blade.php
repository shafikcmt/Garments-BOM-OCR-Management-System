{{--
    General Stock dashboard: the purchasing panel. Shown only to people who may
    open Comparative Statements or Internal POs. Figures come from
    PurchasingDashboardService; every tile links to the list it counts.
--}}
@php
    $u = auth()->user();
    $seeCs = $u?->can('store.purchase_cs.view') ?? false;
    $seePo = $u?->can('store.purchase_po.view') ?? false;
    $cs = $purchasing['cs'];
    $po = $purchasing['po'];
    $money = fn ($v) => number_format((float) $v, 0);
    $maxCat = max(1, ...array_values($purchasing['waiting_by_category'] ?: [1]));
@endphp

<div class="d-flex align-items-center gap-2 mb-2">
    <h5 class="mb-0">Purchasing</h5>
    <span class="text-muted small">Requisition to Comparative Statement to Internal PO to receiving</span>
</div>

<div class="row g-3 mb-4">
    @if($seeCs)
        <div class="col-12 col-sm-6 col-xl-3">
            <x-stat-card class="gx-fade-in h-100" icon="cart-plus" tone="danger" label="Items waiting to be purchased"
                :value="$purchasing['waiting_items']" :href="route('store.purchasing.queue')" />
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <x-stat-card class="gx-fade-in h-100" icon="hourglass-split" tone="warning" label="Statements waiting for check / approval"
                :value="$cs['to_check'] + $cs['to_approve']" :href="route('store.purchasing.cs.index')" />
        </div>
    @endif
    @if($seePo)
        <div class="col-12 col-sm-6 col-xl-3">
            <x-stat-card class="gx-fade-in h-100" icon="patch-check" tone="warning" label="POs waiting for check / authorise"
                :value="$po['draft'] + $po['to_authorise']" :href="route('store.purchasing.po.index')" />
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <x-stat-card class="gx-fade-in h-100" icon="truck" tone="success" label="POs open for receiving"
                :value="$po['issued'] + $po['partial']" :href="route('store.purchasing.po.index', ['status' => 'issued'])" />
        </div>
    @endif
</div>

<div class="row g-3 mb-4">
    @if($seeCs)
        <div class="col-12 col-lg-6 col-xl-3">
            <x-card class="gx-fade-in h-100" title="Comparative Statements">
                <x-donut-chart caption="Statements" :segments="[
                    ['label' => 'Draft', 'value' => $cs['draft'], 'tone' => 'primary'],
                    ['label' => 'Waiting for check', 'value' => $cs['to_check'], 'tone' => 'warning'],
                    ['label' => 'Waiting for approval', 'value' => $cs['to_approve'], 'tone' => 'danger'],
                    ['label' => 'Approved', 'value' => $cs['approved'], 'tone' => 'success'],
                ]" />
                @if($purchasing['cs_without_po'] > 0 && $seePo)
                    <a href="{{ route('store.purchasing.cs.index', ['status' => 'approved']) }}" class="btn btn-sm btn-outline-primary mt-3">
                        {{ $purchasing['cs_without_po'] }} approved, no PO yet
                    </a>
                @endif
            </x-card>
        </div>
    @endif
    @if($seePo)
        <div class="col-12 col-lg-6 col-xl-3">
            <x-card class="gx-fade-in h-100" title="Internal POs">
                <x-donut-chart caption="POs" :segments="[
                    ['label' => 'Draft', 'value' => $po['draft'], 'tone' => 'primary'],
                    ['label' => 'Checked', 'value' => $po['to_authorise'], 'tone' => 'warning'],
                    ['label' => 'Issued', 'value' => $po['issued'], 'tone' => 'danger'],
                    ['label' => 'Partly received', 'value' => $po['partial'], 'tone' => 'warning'],
                    ['label' => 'Received', 'value' => $po['received'], 'tone' => 'success'],
                ]" />
            </x-card>
        </div>
        <div class="col-12 col-xl-6">
            <x-card class="gx-fade-in h-100" title="Ordered value per month (Taka)">
                <x-slot:actions><span class="small text-muted">This month: <strong>{{ $money($purchasing['ordered_this_month']) }}</strong></span></x-slot:actions>
                <x-area-chart :series="$purchasing['trend']" tone="success" label="Authorised purchase orders, value per month" />
            </x-card>
        </div>
    @endif
</div>

<div class="row g-3 mb-4">
    @if($seeCs)
        <div class="col-12 col-xl-5">
            <x-card class="gx-fade-in h-100" title="Waiting to purchase, by category">
                @forelse($purchasing['waiting_by_category'] as $name => $n)
                    <a href="{{ route('store.purchasing.queue', ['category' => $name]) }}" class="d-block text-decoration-none text-reset mb-2">
                        <div class="d-flex justify-content-between small"><span>{{ $name }}</span><strong>{{ $n }}</strong></div>
                        <div class="progress" style="height:6px;" role="progressbar" aria-label="{{ $name }}" aria-valuenow="{{ $n }}" aria-valuemin="0" aria-valuemax="{{ $maxCat }}">
                            <div class="progress-bar" style="width:{{ round($n / $maxCat * 100) }}%"></div>
                        </div>
                    </a>
                @empty
                    <p class="text-muted small mb-0">Nothing waiting to be purchased.</p>
                @endforelse
            </x-card>
        </div>
    @endif
    @if($seePo)
        <div class="col-12 col-xl-7">
            <x-card class="gx-fade-in h-100" title="Latest purchase orders" body-class="p-0">
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead><tr><th class="ps-4">PO No</th><th>Supplier</th><th class="text-end">Total</th><th class="pe-4">Status</th></tr></thead>
                        <tbody>
                            @forelse($purchasing['recent_orders'] as $o)
                                <tr>
                                    <td class="ps-4"><a href="{{ route('store.purchasing.po.show', $o) }}">{{ $o->po_no }}</a></td>
                                    <td>{{ $o->supplier_name }}</td>
                                    <td class="text-end">{{ $money($o->total_amount) }}</td>
                                    <td class="pe-4">@include('store.purchasing._badge', ['status' => $o->status, 'label' => $o->statusLabel()])</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="text-center text-muted py-4">No purchase orders yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-card>
        </div>
    @endif
</div>
