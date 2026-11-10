@extends('layouts.app')

@section('title', 'Accounts Dashboard')

@section('content')
@php
    $money = fn ($n) => number_format((float) $n, 0);
    $bucketTone = ['Overdue' => 'danger', 'This week' => 'warning', 'No date' => 'secondary'];
    $bars = collect($pay['buckets'])->map(fn ($v, $k) => ['label' => $k, 'value' => $v, 'tone' => $bucketTone[$k] ?? 'primary'])->values()->all();
    $topSupplier = max(array_merge([0], array_map(fn ($s) => $s['amount'], $pay['suppliers'])));
@endphp
<div class="container-fluid">
    <x-page-header data-aos="fade-down" icon="calculator" eyebrow="Accounts"
                   title="Accounts"
                   copy="What is waiting to be paid, what is late, and where every payment request stands.">
        <x-slot:actions>
            <a href="{{ route('account.payments.index') }}" class="btn btn-outline-primary d-inline-flex align-items-center gap-2">
                <i class="bi bi-cash-coin" aria-hidden="true"></i>Payments
            </a>
            <a href="{{ route('account.workspace') }}" class="btn btn-primary d-inline-flex align-items-center gap-2">
                <i class="bi bi-grid-3x3-gap" aria-hidden="true"></i>Open Workspace
            </a>
        </x-slot:actions>
    </x-page-header>

    {{-- Money: what needs paying. Amounts are PI amounts as entered. --}}
    <div class="row g-3 mb-3">
        <div class="col-12 col-sm-6 col-xl-3">
            <x-stat-card class="gx-fade-in h-100" style="--gx-delay:0ms" icon="cash-stack" tone="primary" 
                :label="'Awaiting payment · ' . $pay['due_count'] . ' request(s)'" :value="$money($pay['due_amount'])" href="{{ route('account.payments.index') }}" />
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <x-stat-card class="gx-fade-in h-100" style="--gx-delay:100ms" icon="exclamation-triangle" tone="danger" 
                :label="'Overdue · ' . $pay['overdue_count'] . ' past required date'" :value="$money($pay['overdue_amount'])" href="{{ route('account.payments.index') }}" />
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <x-stat-card class="gx-fade-in h-100" style="--gx-delay:200ms" icon="calendar-week" tone="warning" 
                :label="'Due in 7 days · ' . $pay['week_count'] . ' request(s)'" :value="$money($pay['week_amount'])" href="{{ route('account.payments.index') }}" />
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <x-stat-card class="gx-fade-in h-100" style="--gx-delay:300ms" icon="check2-circle" tone="success" 
                :label="'Paid · ' . $pay['paid_count'] . ' request(s)'" :value="$money($pay['paid_amount'])" href="{{ route('account.payments.index', ['tab' => 'paid']) }}" />
        </div>
    </div>

    {{-- Workflow: where the requests are. --}}
    <div class="row g-3 mb-4">
        <div class="col-12 col-sm-6 col-xl-3">
            <x-stat-card class="gx-fade-in h-100" style="--gx-delay:0ms"
                icon="receipt" tone="primary" label="Payment requests"
                :value="$stats['pra_total']" :spark="collect($trend)->pluck('value')->all()" />
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <x-stat-card class="gx-fade-in h-100" style="--gx-delay:100ms"
                icon="hourglass-split" tone="warning" label="Pending approval"
                :value="$stats['pra_pending']" />
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <x-stat-card class="gx-fade-in h-100" style="--gx-delay:200ms"
                icon="check2-circle" tone="success" label="Approved"
                :value="$stats['pra_approved']" />
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <x-stat-card class="gx-fade-in h-100" style="--gx-delay:300ms"
                icon="columns-gap" tone="primary" label="Columns you own"
                :value="$workspace['fields']" />
        </div>
    </div>

    <div class="row g-3 mb-4" data-aos="fade-up">
        <div class="col-12 col-xl-7">
            <x-card class="gx-fade-in h-100" style="--gx-delay:400ms" title="Amount to pay, by when it is needed">
                <x-bar-chart :series="$bars" :height="190" label="Amount awaiting payment by required date" />
                <p class="small text-muted mt-3 mb-0">
                    Required date comes from the payment request lines.
                    @if($pay['no_date_count'] > 0)<strong>{{ $pay['no_date_count'] }}</strong> request(s) have no required date and are under "No date".@endif
                </p>
            </x-card>
        </div>
        <div class="col-12 col-xl-5">
            <x-card class="gx-fade-in h-100" style="--gx-delay:500ms" title="Where the requests stand">
                <x-donut-chart :segments="$statusSegments" caption="Requests" />
            </x-card>
        </div>
    </div>

    <div class="row g-3 mb-4" data-aos="fade-up">
        <div class="col-12 col-xl-6">
            <x-card class="gx-fade-in h-100" style="--gx-delay:550ms">
                <x-slot:title>Owed to suppliers
                    @if($pay['supplier_total'] > count($pay['suppliers']))<span class="badge bg-light text-muted border ms-1">top {{ count($pay['suppliers']) }} of {{ $pay['supplier_total'] }}</span>@endif
                </x-slot:title>
                @forelse($pay['suppliers'] as $s)
                    <div class="mb-3">
                        <div class="d-flex justify-content-between small">
                            <span class="fw-semibold text-truncate me-2">{{ $s['supplier'] }}</span>
                            <span class="text-nowrap">{{ $money($s['amount']) }}
                                <span class="text-muted">· {{ $s['count'] }} request(s)</span>
                                @if($s['overdue'] > 0)<span class="badge bg-danger-subtle text-danger ms-1">{{ $s['overdue'] }} overdue</span>@endif</span>
                        </div>
                        <div class="progress" style="height:6px" role="progressbar" aria-label="{{ $s['supplier'] }}" aria-valuenow="{{ $topSupplier > 0 ? round($s['amount'] / $topSupplier * 100) : 0 }}" aria-valuemin="0" aria-valuemax="100">
                            <div class="progress-bar {{ $s['overdue'] > 0 ? 'bg-danger' : 'bg-primary' }}" style="width: {{ $topSupplier > 0 ? round($s['amount'] / $topSupplier * 100) : 0 }}%"></div>
                        </div>
                    </div>
                @empty
                    <p class="text-muted mb-0">Nothing is waiting to be paid.</p>
                @endforelse
            </x-card>
        </div>

        <div class="col-12 col-xl-6">
            <x-card class="gx-fade-in h-100" style="--gx-delay:600ms" body-class="p-0">
                <x-slot:title>Pay these first
                    @if($pay['overdue_count'] > count($pay['overdue_list']))<span class="badge bg-light text-muted border ms-1">oldest {{ count($pay['overdue_list']) }} of {{ $pay['overdue_count'] }} overdue</span>@endif
                </x-slot:title>
                @if($pay['overdue_list'])
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0" style="min-width:0">
                            <thead><tr><th>Request</th><th>Supplier</th><th class="text-end">Amount</th><th>Required</th></tr></thead>
                            <tbody>
                            @foreach($pay['overdue_list'] as $o)
                                <tr>
                                    <td class="fw-semibold text-nowrap">{{ $o['pra']->request_no }}</td>
                                    <td>{{ $o['pra']->supplier_name ?: '—' }}</td>
                                    <td class="text-end">{{ $money($o['amount']) }}</td>
                                    <td class="text-nowrap">{{ $o['required']->format('d M Y') }}<div class="small text-danger">{{ $o['days'] }} day(s) late</div></td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="p-3 border-top"><a href="{{ route('account.payments.index') }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-cash-coin me-1" aria-hidden="true"></i>Open Payments</a></div>
                @else
                    <p class="text-muted p-3 mb-0">Nothing is past its required date.</p>
                @endif
            </x-card>
        </div>
    </div>

    <div class="row g-3 mb-4" data-aos="fade-up">
        <div class="col-12 col-xl-7">
            <x-card class="gx-fade-in h-100" style="--gx-delay:650ms">
                <x-slot:title>
                    Payment requests raised — last 6 months
                    @if($delta !== null)
                        <span class="badge {{ $delta >= 0 ? 'bg-success-subtle text-success' : 'bg-danger-subtle text-danger' }} ms-1">
                            {{ $delta >= 0 ? '+' : '' }}{{ $delta }}% vs last month
                        </span>
                    @endif
                </x-slot:title>
                <x-area-chart :series="$trend" tone="primary" label="Payment requests raised per month" />
            </x-card>
        </div>

        <div class="col-12 col-xl-5">
            <x-card class="gx-fade-in h-100" style="--gx-delay:700ms" title="Your share of the BOM">
                <x-workspace-progress :workspace="$workspace" />
                <a href="{{ route('account.workspace') }}" class="btn btn-sm btn-outline-primary mt-3">Open workspace</a>
            </x-card>
        </div>
    </div>
    {{-- Stock Summary. Renders nothing for a user who may not see
         requisitions, so it is included unconditionally. --}}
    @include('shared._requisition-summary-card')
</div>
@endsection
