@extends('layouts.app')

@section('title', 'General Stock — Monthly Ledger')

@section('content')
<div class="container-fluid">
    <x-breadcrumb :items="[
        ['label' => 'Store', 'url' => route('store.dashboard')],
        ['label' => 'General Stock'],
        ['label' => 'Monthly Stock Ledger'],
    ]" />

    <div class="app-hero-card p-4 mb-4">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div class="d-flex align-items-center gap-3">
                <span class="app-stat-icon" style="width:46px;height:46px;border-radius:15px;font-size:20px;"><i class="bi bi-journal-text"></i></span>
                <div>
                    <div class="app-hero-eyebrow">General Stock</div>
                    <h3 class="app-hero-title mb-0">Monthly Stock Ledger</h3>
                    <p class="app-hero-copy mb-0">Opening + Addition − Consumption = Closing · {{ $monthLabel }}</p>
                </div>
            </div>
            <form method="GET" class="d-flex align-items-end gap-2">
                <div>
                    <label class="form-label fw-semibold small mb-1">Month</label>
                    <input type="month" name="month" value="{{ $month }}" class="form-control">
                </div>
                <button type="submit" class="btn btn-primary"><i class="bi bi-funnel me-1"></i>View</button>
            </form>
        </div>
    </div>

    @if($reorderCount > 0)
        <div class="alert alert-warning border-0 shadow-sm rounded-3"><i class="bi bi-exclamation-triangle me-1"></i>{{ $reorderCount }} item(s) at or below re-order level.</div>
    @endif

    <div class="card border-0 shadow-sm" style="border-radius:var(--gx-radius);">
        <div class="card-body p-4">
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr class="text-muted small text-uppercase">
                            <th>Item</th><th>UOM</th>
                            <th class="text-end">Opening</th>
                            <th class="text-end">Addition</th>
                            <th class="text-end">Consumption</th>
                            <th class="text-end">Closing</th>
                            <th class="text-end">Re-order Lvl</th>
                            <th>Flag</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($rows as $r)
                            <tr class="{{ $r['needs_reorder'] ? 'table-warning' : '' }}">
                                <td class="fw-semibold">{{ $r['item']->name }}<div class="small text-muted">{{ $r['item']->code ?: '' }}</div></td>
                                <td class="small">{{ $r['item']->uom ?: '—' }}</td>
                                <td class="text-end">{{ rtrim(rtrim(number_format($r['opening'], 4), '0'), '.') }}</td>
                                <td class="text-end text-success">{{ rtrim(rtrim(number_format($r['addition'], 4), '0'), '.') }}</td>
                                <td class="text-end text-danger">{{ rtrim(rtrim(number_format($r['consumption'], 4), '0'), '.') }}</td>
                                <td class="text-end fw-bold">{{ rtrim(rtrim(number_format($r['closing'], 4), '0'), '.') }}</td>
                                <td class="text-end small text-muted">{{ $r['reorder_level'] !== null ? rtrim(rtrim(number_format($r['reorder_level'], 4), '0'), '.') : '—' }}</td>
                                <td>
                                    @if($r['needs_reorder'])
                                        <span class="badge bg-danger-subtle text-danger"><i class="bi bi-cart-plus me-1"></i>Re-order</span>
                                    @else
                                        <span class="badge bg-success-subtle text-success">OK</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="text-center text-muted py-5">No stock items yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <p class="small text-muted mt-3 mb-0">Opening = all purchases − all issues before {{ $monthLabel }}. Closing carries forward as next month's opening.</p>
        </div>
    </div>
</div>
@endsection
