@extends('layouts.app')

@section('title', 'Payments')

@section('content')
<div class="container-fluid">
    <x-breadcrumb :items="[
        ['label' => 'Accounts', 'url' => route('account.dashboard')],
        ['label' => 'Payments'],
    ]" />

    <x-page-header data-aos="fade-down" icon="cash-coin" eyebrow="Accounts"
                   title="Payments"
                   copy="Approved payment requests and what is still to be paid." />

    <x-flash />

    <div class="row g-3 mb-3">
        <div class="col-6 col-xl-3"><x-stat-card icon="hourglass-split" tone="warning" label="Awaiting payment" :value="$stats['due_count']" /></div>
        <div class="col-6 col-xl-3"><x-stat-card icon="cash-stack" tone="danger" label="Amount to pay" :value="number_format($stats['due_amount'], 2)" /></div>
        <div class="col-6 col-xl-3"><x-stat-card icon="diagram-3" tone="primary" label="At payment step" :value="$stats['waiting_step']" /></div>
        <div class="col-6 col-xl-3"><x-stat-card icon="check2-circle" tone="success" label="Paid" :value="$stats['paid_count']" /></div>
    </div>

    <x-card body-class="p-3">
        <form method="GET" class="d-flex flex-wrap gap-2 mb-3 align-items-center">
            <div class="btn-group" role="group">
                <a href="{{ route('account.payments.index', ['q' => $term]) }}" class="btn btn-sm {{ $tab === 'due' ? 'btn-primary' : 'btn-outline-secondary' }}">Awaiting payment</a>
                <a href="{{ route('account.payments.index', ['tab' => 'paid', 'q' => $term]) }}" class="btn btn-sm {{ $tab === 'paid' ? 'btn-primary' : 'btn-outline-secondary' }}">Paid</a>
            </div>
            <input type="hidden" name="tab" value="{{ $tab }}">
            <input type="search" name="q" value="{{ $term }}" class="form-control form-control-sm" style="max-width:260px" placeholder="PRA no. or supplier">
            <button class="btn btn-outline-primary btn-sm"><i class="bi bi-search me-1" aria-hidden="true"></i>Search</button>
        </form>

        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th>PRA No.</th><th>Supplier</th><th>Buyer / Season</th>
                        <th class="text-end">Lines</th><th class="text-end">Amount</th><th>Required by</th>
                        <th>Payment</th><th style="min-width:300px">{{ $tab === 'paid' ? 'Reference' : 'Action' }}</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($requests as $pra)
                    <tr>
                        <td class="fw-semibold">{{ $pra->request_no }}</td>
                        <td>{{ $pra->supplier_name ?: '—' }}</td>
                        <td>{{ trim(($pra->buyer_name ?: '') . ' ' . ($pra->season_name ?: '')) ?: '—' }}</td>
                        <td class="text-end">{{ $pra->items_count }}</td>
                        <td class="text-end">{{ number_format((float) ($pra->total_pi_amount ?: $pra->lines_amount), 2) }}</td>
                        <td>{{ $pra->required_date ? \Illuminate\Support\Carbon::parse($pra->required_date)->format('d M Y') : '—' }}</td>
                        <td>
                            @if($pra->payment_state === 'paid')
                                <span class="badge bg-success-subtle text-success">Paid</span>
                            @elseif($pra->payment_state === 'partial')
                                <span class="badge bg-warning-subtle text-warning">{{ $pra->paid_lines }} of {{ $pra->items_count }} lines</span>
                            @elseif($pra->status === 'pending_completion')
                                <span class="badge bg-primary-subtle text-primary">At payment step</span>
                            @else
                                <span class="badge bg-danger-subtle text-danger">Not paid</span>
                            @endif
                        </td>
                        <td>
                            @if($pra->payment_state === 'paid')
                                {{ $pra->data['payment_reference'] ?? 'Entered in workspace' }}
                            @elseif($pra->status === 'pending_completion')
                                <a href="{{ route('pra_departmental.show', $pra) }}" class="btn btn-outline-primary btn-sm">
                                    <i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>Open PRA
                                </a>
                            @else
                                <form method="POST" action="{{ route('account.payments.record', $pra) }}" class="d-flex gap-2">
                                    @csrf
                                    <input type="text" name="payment_reference" class="form-control form-control-sm" maxlength="100" placeholder="Payment reference no." required>
                                    <button class="btn btn-primary btn-sm text-nowrap"><i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Record Payment</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-center text-muted py-4">{{ $tab === 'paid' ? 'No paid payment requests yet.' : 'Nothing is waiting for payment.' }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </x-card>
</div>
@endsection
