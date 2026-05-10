@extends('layouts.app')

@section('title', 'PO Generate Control')

@section('styles')
<style>
    .po-control-page {
        --po-ink: #0f172a;
        --po-muted: #64748b;
        --po-border: #dbe7f3;
        --po-blue: #2563eb;
        --po-red: #dc2626;
        --po-orange: #f59e0b;
        --po-green: #059669;
    }
    .po-control-hero {
        border: 1px solid rgba(191, 219, 254, .7);
        border-radius: 22px;
        background: radial-gradient(circle at top right, rgba(37, 99, 235, .14), transparent 34%), linear-gradient(135deg, #ffffff 0%, #eef6ff 100%);
        box-shadow: 0 20px 55px rgba(15, 23, 42, .08);
    }
    .po-control-icon {
        width: 54px;
        height: 54px;
        border-radius: 18px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: #dbeafe;
        color: var(--po-blue);
        font-size: 24px;
        box-shadow: inset 0 0 0 1px rgba(37, 99, 235, .12);
    }
    .po-control-eyebrow {
        color: var(--po-blue);
        font-size: 11px;
        letter-spacing: .14em;
        font-weight: 900;
        text-transform: uppercase;
    }
    .po-control-title { color: var(--po-ink); font-weight: 900; letter-spacing: -.03em; }
    .po-control-copy { color: var(--po-muted); }
    .po-stat-card {
        border: 1px solid var(--po-border);
        border-radius: 18px;
        background: #fff;
        box-shadow: 0 16px 40px rgba(15, 23, 42, .06);
        height: 100%;
    }
    .po-stat-label { color: var(--po-muted); font-size: 12px; font-weight: 800; }
    .po-stat-value { color: var(--po-ink); font-size: 28px; font-weight: 950; line-height: 1; }
    .po-filter-card,
    .po-table-card {
        border: 1px solid var(--po-border);
        border-radius: 20px;
        background: #fff;
        box-shadow: 0 16px 45px rgba(15, 23, 42, .07);
    }
    .po-filter-card .form-control,
    .po-filter-card .form-select {
        min-height: 44px;
        border-radius: 13px;
        border-color: #cbd5e1;
        font-weight: 700;
        color: var(--po-ink);
    }
    .po-table {
        margin-bottom: 0;
        border-collapse: separate;
        border-spacing: 0;
    }
    .po-table thead th {
        background: #f8fafc;
        color: #0f172a;
        border-bottom: 1px solid var(--po-border);
        font-size: 11px;
        font-weight: 900;
        letter-spacing: .05em;
        text-transform: uppercase;
        padding: 13px 14px;
        white-space: nowrap;
    }
    .po-table tbody td {
        border-bottom: 1px solid #e8eef6;
        padding: 14px;
        vertical-align: middle;
        color: #1e293b;
    }
    .po-row-main:hover td { background: #f8fbff; }
    .po-no-pill,
    .po-state-pill,
    .po-change-pill {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        border-radius: 999px;
        padding: 6px 10px;
        font-size: 11px;
        font-weight: 900;
        white-space: nowrap;
    }
    .po-no-pill { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }
    .po-state-pill.generated { background: #ecfdf5; color: #047857; border: 1px solid #bbf7d0; }
    .po-state-pill.regenerated { background: #fff7ed; color: #c2410c; border: 1px solid #fed7aa; }
    .po-change-pill { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
    .po-change-pill.clean { background: #f0fdf4; color: #047857; border-color: #bbf7d0; }
    .po-action-btn {
        border-radius: 999px;
        font-weight: 900;
        padding: 7px 12px;
    }
    .po-detail-row td {
        background: linear-gradient(180deg, #fbfdff 0%, #ffffff 100%);
        padding: 0 14px 16px;
    }
    .po-detail-panel {
        border: 1px solid #e2e8f0;
        border-radius: 16px;
        padding: 14px;
        background: #fff;
    }
    .po-change-table { margin-bottom: 0; }
    .po-change-table th {
        color: #475569;
        font-size: 10px;
        text-transform: uppercase;
        letter-spacing: .06em;
    }
    .po-change-table td { font-size: 12px; padding: 8px !important; border-bottom: 1px solid #eef2f7 !important; }
    .po-history-line {
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 10px 12px;
        background: #f8fafc;
        margin-bottom: 8px;
    }
    .po-empty-state {
        min-height: 240px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: var(--po-muted);
    }
    .po-detail-row.d-none { display: none; }
    .po-history-toggle.is-open { background: #eef2ff; border-color: #bfdbfe; color: #1d4ed8; }
</style>
@endsection

@section('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-po-detail-toggle]').forEach(function (button) {
        button.addEventListener('click', function () {
            const target = document.querySelector(this.dataset.poDetailToggle);
            if (!target) return;
            const isOpen = !target.classList.contains('d-none');
            target.classList.toggle('d-none', isOpen);
            this.classList.toggle('is-open', !isOpen);
            this.setAttribute('aria-expanded', String(!isOpen));
            this.innerHTML = !isOpen
                ? '<i class="bi bi-eye-slash me-1"></i>Hide History'
                : '<i class="bi bi-clock-history me-1"></i>Show History';
        });
    });
});
</script>
@endsection

@section('content')
<div class="container-fluid po-control-page">
    <div class="po-control-hero p-4 p-lg-5 mb-4">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div class="d-flex align-items-center gap-3">
                <span class="po-control-icon"><i class="bi bi-shield-lock"></i></span>
                <div>
                    <div class="po-control-eyebrow">Admin Only</div>
                    <h2 class="po-control-title mb-1">PO Generate Control</h2>
                    <p class="po-control-copy mb-0">Control generated PO, re-generated PO, source-data changes, and before/after audit history from admin panel.</p>
                </div>
            </div>
            <a href="{{ route('admin.dashboard') }}" class="btn btn-outline-primary rounded-pill fw-bold px-4">
                <i class="bi bi-arrow-left me-1"></i>Admin Dashboard
            </a>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-6 col-xl-3">
            <div class="po-stat-card p-3">
                <div class="d-flex align-items-center justify-content-between gap-3">
                    <div><div class="po-stat-label">Total Generated PO</div><div class="po-stat-value">{{ $stats['total'] ?? 0 }}</div></div>
                    <span class="po-control-icon"><i class="bi bi-file-earmark-check"></i></span>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="po-stat-card p-3">
                <div class="d-flex align-items-center justify-content-between gap-3">
                    <div><div class="po-stat-label">Re-generated PO</div><div class="po-stat-value">{{ $stats['regenerated'] ?? 0 }}</div></div>
                    <span class="po-control-icon"><i class="bi bi-arrow-repeat"></i></span>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="po-stat-card p-3">
                <div class="d-flex align-items-center justify-content-between gap-3">
                    <div><div class="po-stat-label">Need Admin Control</div><div class="po-stat-value">{{ $stats['changed'] ?? 0 }}</div></div>
                    <span class="po-control-icon"><i class="bi bi-exclamation-triangle"></i></span>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="po-stat-card p-3">
                <div class="d-flex align-items-center justify-content-between gap-3">
                    <div><div class="po-stat-label">Completed PO</div><div class="po-stat-value">{{ $stats['completed'] ?? 0 }}</div></div>
                    <span class="po-control-icon"><i class="bi bi-check2-circle"></i></span>
                </div>
            </div>
        </div>
    </div>

    <form method="GET" action="{{ route('admin.po-generate-control.index') }}" class="po-filter-card p-3 p-lg-4 mb-4">
        <div class="row g-3 align-items-end">
            <div class="col-lg-4">
                <label class="form-label small fw-bold text-muted">Search PO / buyer / vendor / style</label>
                <input type="text" name="q" value="{{ request('q') }}" class="form-control" placeholder="Search PO no, vendor, buyer...">
            </div>
            <div class="col-md-4 col-lg-2">
                <label class="form-label small fw-bold text-muted">Control State</label>
                <select name="state" class="form-select">
                    <option value="all" @selected(request('state', 'all') === 'all')>All PO</option>
                    <option value="generated" @selected(request('state') === 'generated')>Generated</option>
                    <option value="regenerated" @selected(request('state') === 'regenerated')>Re-generated</option>
                    <option value="changed" @selected(request('state') === 'changed')>Need Re-generate</option>
                    <option value="completed" @selected(request('state') === 'completed')>Completed</option>
                </select>
            </div>
            <div class="col-md-4 col-lg-2">
                <label class="form-label small fw-bold text-muted">Buyer</label>
                <input type="text" name="buyer" value="{{ request('buyer') }}" class="form-control" placeholder="Buyer">
            </div>
            <div class="col-md-4 col-lg-2">
                <label class="form-label small fw-bold text-muted">Vendor</label>
                <input type="text" name="vendor" value="{{ request('vendor') }}" class="form-control" placeholder="Vendor">
            </div>
            <div class="col-lg-2 d-flex gap-2">
                <button class="btn btn-primary rounded-pill fw-bold flex-fill" type="submit"><i class="bi bi-funnel me-1"></i>Filter</button>
                <a href="{{ route('admin.po-generate-control.index') }}" class="btn btn-outline-secondary rounded-pill fw-bold"><i class="bi bi-arrow-clockwise"></i></a>
            </div>
        </div>
    </form>

    <div class="po-table-card overflow-hidden">
        <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap p-3 border-bottom">
            <div>
                <h5 class="fw-bold mb-0 text-slate-900">Generated PO Control List</h5>
                <div class="small text-muted">Showing admin-controlled PO audit rows with source changes and revision history.</div>
            </div>
            <span class="badge rounded-pill text-bg-primary px-3 py-2">{{ $bookingPos->total() }} PO found</span>
        </div>

        @if($bookingPos->count())
            <div class="table-responsive">
                <table class="table po-table align-middle">
                    <thead>
                        <tr>
                            <th>PO</th>
                            <th>Buyer / Season</th>
                            <th>Vendor</th>
                            <th>Item / Style</th>
                            <th class="text-end">Qty</th>
                            <th>State</th>
                            <th>Change Control</th>
                            <th>Generated By</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($bookingPos as $bookingPo)
                            @php
                                $data = $bookingPo->booking_data ?: [];
                                $sourceChanges = collect($data['source_change_log'] ?? []);
                                $history = collect($data['generation_history'] ?? [])->reverse()->values();
                                $revisionNo = max(0, (int) $bookingPo->revision_no);
                                if ($history->isEmpty()) {
                                    $history = collect([[
                                        'action' => 'generated',
                                        'revision_no' => $revisionNo,
                                        'changed_by_name' => optional($bookingPo->generatedBy)->name ?: 'System',
                                        'changed_at' => optional($bookingPo->generated_at)->format('d M Y, h:i A') ?: optional($bookingPo->created_at)->format('d M Y, h:i A') ?: '-',
                                        'changes' => [],
                                        'source_changes' => [],
                                    ]]);
                                }
                                $latestHistory = $history->first();
                                $needsRegenerate = (bool) $bookingPo->needs_regenerate || $sourceChanges->isNotEmpty();
                                $collapseId = 'poControlDetails' . $bookingPo->id;
                            @endphp
                            <tr class="po-row-main">
                                <td>
                                    <span class="po-no-pill"><i class="bi bi-upc-scan"></i>{{ $bookingPo->po_no }}</span>
                                    @if($revisionNo > 0)
                                        <div class="small text-warning-emphasis fw-bold mt-1">Revision R-{{ $revisionNo }}</div>
                                    @endif
                                </td>
                                <td>
                                    <div class="fw-bold text-slate-900">{{ $bookingPo->buyer_name ?: '-' }}</div>
                                    <div class="small text-muted">Season: {{ $bookingPo->season_name ?: '-' }}</div>
                                </td>
                                <td>
                                    <div class="fw-bold">{{ $bookingPo->vendor_name ?: ($data['to'] ?? '-') }}</div>
                                    <div class="small text-muted">IHOD: {{ $bookingPo->ihod ?: '-' }}</div>
                                </td>
                                <td>
                                    <div class="fw-bold">{{ $bookingPo->item_name ?: ($data['item_type'] ?? '-') }}</div>
                                    <div class="small text-muted">Style: {{ $bookingPo->style_name ?: ($data['order_style_no'] ?? '-') }}</div>
                                </td>
                                <td class="text-end fw-bold">{{ $bookingPo->qty !== null ? $bookingPo->qty : '-' }}</td>
                                <td>
                                    @if($revisionNo > 0)
                                        <span class="po-state-pill regenerated"><i class="bi bi-arrow-repeat"></i>Re-generated</span>
                                    @else
                                        <span class="po-state-pill generated"><i class="bi bi-check2-circle"></i>Generated</span>
                                    @endif
                                    <div class="small text-muted mt-1">{{ optional($bookingPo->generated_at)->format('d M Y h:i A') ?: '-' }}</div>
                                </td>
                                <td>
                                    @if($needsRegenerate)
                                        <span class="po-change-pill"><i class="bi bi-exclamation-triangle"></i>{{ $sourceChanges->count() }} Changed</span>
                                    @else
                                        <span class="po-change-pill clean"><i class="bi bi-shield-check"></i>Clean</span>
                                    @endif
                                    @if($latestHistory)
                                        <div class="small text-muted mt-1">Last: {{ ucfirst(str_replace('_', ' ', $latestHistory['action'] ?? 'generated')) }}</div>
                                    @endif
                                </td>
                                <td>
                                    <div class="fw-bold">{{ optional($bookingPo->generatedBy)->name ?: ($latestHistory['changed_by_name'] ?? 'System') }}</div>
                                    <div class="small text-muted">Completed by: {{ optional($bookingPo->completedBy)->name ?: '-' }}</div>
                                </td>
                                <td class="text-end">
                                    <div class="d-inline-flex gap-2 flex-wrap justify-content-end">
                                        <button class="btn btn-outline-secondary btn-sm po-action-btn po-history-toggle" type="button" data-po-detail-toggle="#{{ $collapseId }}" aria-expanded="false" aria-controls="{{ $collapseId }}">
                                            <i class="bi bi-clock-history me-1"></i>Show History
                                        </button>
                                        <a href="{{ route('admin.po-generate-control.show', $bookingPo) }}" class="btn btn-primary btn-sm po-action-btn">
                                            <i class="bi bi-sliders me-1"></i>Control
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <tr class="po-detail-row d-none" id="{{ $collapseId }}">
                                <td colspan="9">
                                    <div class="po-detail-panel mt-2">
                                            <div class="row g-3">
                                                <div class="col-lg-7">
                                                    <div class="d-flex justify-content-between align-items-center gap-2 mb-2">
                                                        <h6 class="fw-bold mb-0"><i class="bi bi-arrow-left-right me-1 text-danger"></i>Source Data Before / After</h6>
                                                        <span class="badge rounded-pill text-bg-light border">{{ $sourceChanges->count() }} change(s)</span>
                                                    </div>
                                                    @if($sourceChanges->isNotEmpty())
                                                        <div class="table-responsive">
                                                            <table class="table table-sm po-change-table">
                                                                <thead><tr><th>Field</th><th>Before</th><th>After</th></tr></thead>
                                                                <tbody>
                                                                @foreach($sourceChanges->take(12) as $change)
                                                                    <tr>
                                                                        <td class="fw-bold">{{ $change['label'] ?? '-' }}</td>
                                                                        <td>{{ trim((string)($change['before'] ?? '')) !== '' ? $change['before'] : 'Blank' }}</td>
                                                                        <td>{{ trim((string)($change['after'] ?? '')) !== '' ? $change['after'] : 'Blank' }}</td>
                                                                    </tr>
                                                                @endforeach
                                                                </tbody>
                                                            </table>
                                                        </div>
                                                    @else
                                                        <div class="alert alert-success rounded-4 mb-0"><i class="bi bi-check2-circle me-1"></i>No source data change found after generation.</div>
                                                    @endif
                                                </div>
                                                <div class="col-lg-5">
                                                    <h6 class="fw-bold mb-2"><i class="bi bi-clock-history me-1 text-primary"></i>Generation / Re-generation History</h6>
                                                    @forelse($history->take(5) as $entry)
                                                        <div class="po-history-line">
                                                            <div class="fw-bold text-slate-900">{{ ucfirst(str_replace('_', ' ', $entry['action'] ?? 'generated')) }} @if(($entry['revision_no'] ?? 0) > 0)<span class="text-warning-emphasis">R-{{ $entry['revision_no'] }}</span>@endif</div>
                                                            <div class="small text-muted">{{ $entry['changed_by_name'] ?? 'System' }} · {{ $entry['changed_at'] ?? '-' }}</div>
                                                            @if(! empty($entry['changes']))
                                                                <div class="small text-danger fw-bold mt-1">{{ count($entry['changes']) }} edited field(s)</div>
                                                                <div class="mt-2 d-grid gap-1">
                                                                    @foreach(collect($entry['changes'])->take(4) as $change)
                                                                        <div class="small bg-white border rounded-3 px-2 py-1">
                                                                            <span class="fw-bold">{{ $change['label'] ?? 'Field' }}:</span>
                                                                            <span class="text-muted">{{ trim((string)($change['before'] ?? '')) !== '' ? $change['before'] : 'Blank' }}</span>
                                                                            <i class="bi bi-arrow-right mx-1 text-primary"></i>
                                                                            <span>{{ trim((string)($change['after'] ?? '')) !== '' ? $change['after'] : 'Blank' }}</span>
                                                                        </div>
                                                                    @endforeach
                                                                </div>
                                                            @endif
                                                            @if(! empty($entry['source_changes']))
                                                                <div class="small text-danger fw-bold mt-1">{{ count($entry['source_changes']) }} source change(s)</div>
                                                            @endif
                                                        </div>
                                                    @empty
                                                        <div class="alert alert-light border rounded-4 mb-0">No generation history stored yet.</div>
                                                    @endforelse
                                                </div>
                                            </div>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="p-3 border-top">
                {{ $bookingPos->links() }}
            </div>
        @else
            <div class="po-empty-state text-center">
                <div>
                    <span class="po-control-icon mb-3"><i class="bi bi-inbox"></i></span>
                    <div class="fw-bold text-slate-900">No PO found</div>
                    <div class="small text-muted">Change filters or generate a PO first.</div>
                </div>
            </div>
        @endif
    </div>
</div>
@endsection
