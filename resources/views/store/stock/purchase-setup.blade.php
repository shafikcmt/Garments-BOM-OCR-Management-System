@extends('layouts.app')

@section('title', 'General Stock — Purchase Setup')

@section('content')
<div class="container-fluid gx-stock-scope">
    <x-breadcrumb :items="[
        ['label' => 'Store', 'url' => route('store.dashboard')],
        ['label' => 'General Stock'],
        ['label' => 'Purchase Setup'],
    ]" />

    <div class="app-hero-card p-4 mb-4">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div class="d-flex align-items-center gap-3">
                <span class="app-stat-icon gx-stock-hero-icon"><i class="bi bi-shop" aria-hidden="true"></i></span>
                <div>
                    <div class="app-hero-eyebrow">General Stock</div>
                    <h3 class="app-hero-title mb-0">Purchase Setup</h3>
                    <p class="app-hero-copy mb-0">Suppliers used by the Record Purchase screen. This list is only for General Stock.</p>
                </div>
            </div>
            <a href="{{ route('store.stock.purchases.index') }}" class="btn btn-outline-secondary">
                <i class="bi bi-truck me-1" aria-hidden="true"></i>Receiving
            </a>
        </div>
    </div>

    @include('store.stock._stock-ui')


    @include('store._flash')

    {{-- Per-row outcome of a bulk upload. Errors block the whole file; skips
         are informational — those suppliers were already listed. --}}
    @foreach ([
        ['key' => 'import_errors', 'tone' => 'danger', 'icon' => 'x-circle-fill', 'heading' => 'These rows need correcting before the file can be imported:'],
        ['key' => 'import_skipped', 'tone' => 'warning', 'icon' => 'exclamation-triangle-fill', 'heading' => 'These rows were skipped:'],
    ] as $report)
        @if(session($report['key']))
            <div class="alert alert-{{ $report['tone'] }} d-flex align-items-start gap-2" role="alert">
                <i class="bi bi-{{ $report['icon'] }}" aria-hidden="true"></i>
                <div class="flex-grow-1">
                    <div class="fw-semibold mb-1">{{ $report['heading'] }}</div>
                    <ul class="mb-0 ps-3 small" style="max-height:220px; overflow-y:auto;">
                        @foreach(session($report['key']) as $line)
                            <li>{{ $line }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>
        @endif
    @endforeach

    <div class="row g-4">
        <div class="col-12 col-xl-4">
            <div class="card gx-stock-card">
                <div class="gx-stock-card-body">
                    <h5 class="mb-3">Add Supplier</h5>
                    <form method="POST" action="{{ route('store.stock.purchase-setup.store') }}">
                        @csrf
                        <label class="form-label">Supplier Name <span class="text-danger">*</span></label>
                        <input name="name" value="{{ old('name') }}" class="form-control mb-3" maxlength="255" required autofocus>

                        <button type="submit" class="btn btn-primary w-100"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add Supplier</button>
                    </form>

                    {{-- Bulk upload. Adding suppliers is routine store work, so
                         this needs no permission beyond reaching the screen. --}}
                    <hr class="my-4">
                    <h6 class="mb-1">Import Suppliers</h6>
                    <p class="gx-stock-help mb-3">
                        Upload many suppliers at once. Start from the sample template so the columns line up.
                    </p>

                    <a href="{{ route('store.stock.purchase-setup.template') }}" class="btn btn-outline-secondary w-100 mb-3">
                        <i class="bi bi-download me-1" aria-hidden="true"></i>Download Sample Template
                    </a>

                    <form method="POST" action="{{ route('store.stock.purchase-setup.import') }}" enctype="multipart/form-data">
                        @csrf
                        <input type="file" name="file" class="form-control mb-2" accept=".csv,.txt,.xlsx,.xls" required
                               aria-label="CSV or Excel file of suppliers to import">
                        <p class="gx-stock-help mb-2">
                            One supplier name per row, in the first column. A header row is skipped
                            automatically, and names already in the list are left alone.
                        </p>
                        <button type="submit" class="btn btn-outline-primary w-100"><i class="bi bi-upload me-1" aria-hidden="true"></i>Import Suppliers</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-12 col-xl-8">
            <div class="card gx-stock-card">
                <div class="gx-stock-card-body">
                    <div class="gx-stock-card-head">
                        <h5>Suppliers <span class="badge bg-primary-subtle text-primary ms-1">{{ $suppliers->count() }}</span></h5>
                    </div>
                    <div class="table-responsive">
                        <table class="table align-middle gx-stock-table">
                            <thead>
                                <tr>
                                    <th style="min-width:220px;">Supplier Name</th>
                                    <th>Status</th>
                                    <th class="text-end">Used On</th>
                                    <th>Added</th>
                                    <th class="text-end gx-stock-actions">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($suppliers as $supplier)
                                    <tr>
                                        <td class="fw-semibold text-slate-900">{{ $supplier->name }}</td>
                                        <td>
                                            @if($supplier->is_active)
                                                <span class="badge bg-success-subtle text-success">Active</span>
                                            @else
                                                <span class="badge bg-secondary-subtle text-secondary">Inactive</span>
                                            @endif
                                        </td>
                                        <td class="text-end small text-muted">{{ number_format($supplier->purchases_count) }} purchase(s)</td>
                                        <td class="small text-muted">{{ optional($supplier->created_at)->format('d-M-Y') ?? '—' }}</td>
                                        {{-- Edit and Delete are Admin / Management rights
                                             (store.edit / store.delete); both controller
                                             methods enforce the same check server-side. --}}
                                        <td class="text-end gx-stock-actions">
                                            @if($canEdit)
                                                <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-3"
                                                        data-bs-toggle="modal" data-bs-target="#editSupplier{{ $supplier->id }}">
                                                    <i class="bi bi-pencil me-1" aria-hidden="true"></i>Edit
                                                </button>
                                            @endif
                                            @if($canDelete)
                                                <form method="POST" action="{{ route('store.stock.purchase-setup.destroy', $supplier) }}" class="d-inline"
                                                      onsubmit="return confirm('Remove this supplier? Existing purchase records keep the name.');">
                                                    @csrf @method('DELETE')
                                                    <button class="btn btn-sm btn-outline-danger rounded-pill px-3">
                                                        <i class="bi bi-trash me-1" aria-hidden="true"></i>Delete
                                                    </button>
                                                </form>
                                            @endif
                                            @if(! $canEdit && ! $canDelete)
                                                <span class="text-muted small">—</span>
                                            @endif
                                        </td>
                                    </tr>

                                    {{-- Not rendered for a role that cannot submit it. --}}
                                    @if($canEdit)
                                        <div class="modal fade" id="editSupplier{{ $supplier->id }}" tabindex="-1" aria-hidden="true">
                                            <div class="modal-dialog modal-dialog-centered">
                                                <div class="modal-content gx-stock-card">
                                                    <form method="POST" action="{{ route('store.stock.purchase-setup.update', $supplier) }}">
                                                        @csrf @method('PUT')
                                                        <div class="modal-header">
                                                            <h5 class="modal-title">Edit Supplier</h5>
                                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                        </div>
                                                        <div class="modal-body">
                                                            <label class="form-label">Supplier Name <span class="text-danger">*</span></label>
                                                            <input name="name" value="{{ $supplier->name }}" class="form-control mb-3" maxlength="255" required>
                                                            <div class="form-check">
                                                                <input type="hidden" name="is_active" value="0">
                                                                <input class="form-check-input" type="checkbox" name="is_active" value="1"
                                                                       id="supActive{{ $supplier->id }}" {{ $supplier->is_active ? 'checked' : '' }}>
                                                                <label class="form-check-label" for="supActive{{ $supplier->id }}">Active (shown in the purchase dropdown)</label>
                                                            </div>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                                                            <button type="submit" class="btn btn-primary">Update</button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    @endif
                                @empty
                                    <tr><td colspan="5" class="gx-stock-empty">
                                            <span class="gx-stock-empty-icon"><i class="bi bi-shop" aria-hidden="true"></i></span>
                                            <div class="gx-stock-empty-title">No suppliers yet</div>
                                            <div class="gx-stock-empty-hint">Add one on the left, or import a list.</div>
                                        </td></tr>
                                    {{-- colspan stays 5: Supplier Name, Status, Used On, Added, Action. --}}
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <p class="gx-stock-help mt-3">
                        Removing a supplier hides it from the purchase dropdown. Purchases that already use it keep the name.
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
