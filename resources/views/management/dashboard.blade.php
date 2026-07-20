@extends('layouts.app')

@section('title', 'Management Dashboard')

@section('content')
<div class="container-fluid">
    <x-page-header icon="clipboard2-check" eyebrow="Management"
                   title="Welcome, {{ auth()->user()->name }}"
                   copy="Payment Request Approvals — current position and recent activity.">
        <x-slot:actions>
            <a href="{{ route('pra_approvals.index') }}" class="btn btn-primary d-inline-flex align-items-center gap-2">
                <i class="bi bi-inbox"></i>Pending PRA Approvals
            </a>
        </x-slot:actions>
    </x-page-header>

    {{-- KPI row. Figures are live counts from payment_requests; the only trend
         shown is month-on-month PRA volume, and it is hidden when last month
         had none to compare against rather than showing a meaningless number. --}}
    <div class="row g-3 mb-4">
        <div class="col-12 col-sm-6 col-xl-3">
            <x-stat-card class="gx-fade-in h-100" style="--gx-delay:0ms"
                icon="hourglass-split" tone="warning"
                label="Awaiting your action" :value="$myPending"
                :href="route('pra_approvals.index')" />
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <x-stat-card class="gx-fade-in h-100" style="--gx-delay:100ms"
                icon="clock-history" tone="primary"
                label="Pending approval" :value="$stats['pending']"
                :spark="collect($trend)->pluck('value')->all()" />
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <x-stat-card class="gx-fade-in h-100" style="--gx-delay:200ms"
                icon="check2-circle" tone="success"
                label="Approved" :value="$stats['approved']" />
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <x-stat-card class="gx-fade-in h-100" style="--gx-delay:300ms"
                icon="x-circle" tone="danger"
                label="Rejected" :value="$stats['rejected']" />
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-12 col-xl-5">
            <x-card class="gx-fade-in h-100" style="--gx-delay:400ms" title="PRA status distribution">
                <x-donut-chart caption="Total PRA" :total="$stats['total']" :segments="[
                    ['label' => 'Draft', 'value' => $stats['draft'], 'tone' => 'primary'],
                    ['label' => 'Pending approval', 'value' => $stats['pending'], 'tone' => 'warning'],
                    ['label' => 'Approved', 'value' => $stats['approved'], 'tone' => 'success'],
                    ['label' => 'Rejected', 'value' => $stats['rejected'], 'tone' => 'danger'],
                ]" />
            </x-card>
        </div>

        <div class="col-12 col-xl-7">
            <x-card class="gx-fade-in h-100" style="--gx-delay:500ms">
                <x-slot:title>
                    PRA raised — last 6 months
                    @if($delta !== null)
                        <span class="badge {{ $delta >= 0 ? 'bg-success-subtle text-success' : 'bg-danger-subtle text-danger' }} ms-1">
                            {{ $delta >= 0 ? '+' : '' }}{{ $delta }}% vs last month
                        </span>
                    @endif
                </x-slot:title>

                <x-area-chart :series="$trend" tone="primary" label="Payment requests raised per month" />
            </x-card>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-12 col-xl-7">
            <x-card class="gx-fade-in h-100" style="--gx-delay:600ms">
                <x-slot:title>Recent approval activity</x-slot:title>
                <x-slot:actions>
                    <a href="{{ route('pra_approvals.index') }}" class="btn btn-sm btn-outline-secondary">View all</a>
                </x-slot:actions>

                @php
                    // Map each approval action onto a tone and icon so the
                    // timeline reads at a glance.
                    $activityItems = $recentActivity->map(function ($approval) {
                        $status = $approval->status;
                        $isApproved = $status === \App\Models\PraApproval::STATUS_APPROVED;
                        $isRejected = $status === \App\Models\PraApproval::STATUS_REJECTED;

                        return [
                            'tone' => $isApproved ? 'success' : ($isRejected ? 'danger' : 'warning'),
                            'icon' => $isApproved ? 'check2' : ($isRejected ? 'x-lg' : 'hourglass-split'),
                            'title' => ($approval->approver->name ?? 'Someone')
                                .' '.($isApproved ? 'approved' : ($isRejected ? 'rejected' : 'reviewed'))
                                .' PRA '.($approval->paymentRequest->request_no ?? '#'.$approval->payment_request_id),
                            'description' => $approval->remarks ?: null,
                            'meta' => optional($approval->acted_at)->diffForHumans(),
                        ];
                    })->all();
                @endphp

                <x-timeline :items="$activityItems" />
            </x-card>
        </div>

        <div class="col-12 col-xl-5">
            <x-card class="gx-fade-in h-100" style="--gx-delay:700ms" title="Quick actions">
                <div class="row g-3">
                    <div class="col-12 col-sm-6 col-xl-12">
                        <x-quick-action icon="inbox" tone="warning" title="Pending PRA Approvals"
                            description="Review what is waiting on you"
                            :href="route('pra_approvals.index')" />
                    </div>
                    <div class="col-12 col-sm-6 col-xl-12">
                        <x-quick-action icon="file-earmark-bar-graph" tone="primary" title="Store Reports"
                            description="Receive and issue summaries"
                            :href="route('store.reports.index')" />
                    </div>
                    <div class="col-12 col-sm-6 col-xl-12">
                        <x-quick-action icon="clock-history" tone="success" title="Approval History"
                            description="Past PRA decisions"
                            :href="route('admin.pra-approvals.history')" />
                    </div>
                </div>
            </x-card>
        </div>
    </div>
</div>
@endsection
