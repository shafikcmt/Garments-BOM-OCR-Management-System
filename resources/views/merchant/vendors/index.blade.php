@extends('layouts.app')

@section('title', 'Vendors')

@section('content')
<div class="container-fluid">
    <x-page-header icon="buildings" eyebrow="Merchandising" title="Vendors"
                   copy="The vendor list used for bookings and payment requests. Add a new vendor or edit one here; Admin removes one.">
        <x-slot:actions>
            <a href="{{ route('merchant.vendors.export', array_filter(['q' => $term])) }}" class="btn btn-outline-primary d-inline-flex align-items-center gap-2">
                <i class="bi bi-file-earmark-excel" aria-hidden="true"></i>Export Excel
            </a>
            <button type="button" class="btn btn-outline-primary d-inline-flex align-items-center gap-2" data-bs-toggle="collapse" data-bs-target="#vendorImport" aria-expanded="{{ session('import_errors') || session('import_skipped') || $errors->has('file') ? 'true' : 'false' }}" aria-controls="vendorImport">
                <i class="bi bi-upload" aria-hidden="true"></i>Import Excel
            </button>
            <a href="{{ route('merchant.vendors.create') }}" class="btn btn-primary d-inline-flex align-items-center gap-2">
                <i class="bi bi-plus-lg" aria-hidden="true"></i>Add Vendor
            </a>
        </x-slot:actions>
    </x-page-header>

    <x-flash />

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

    <div class="collapse {{ session('import_errors') || session('import_skipped') || $errors->has('file') ? 'show' : '' }}" id="vendorImport">
        <x-card class="mb-3" title="Import vendors from Excel">
            <p class="text-muted small mb-3">
                Fill the upload workbook (Vendors, Contacts, Addresses, Item Categories, Bank Accounts sheets) and upload it. The file is checked as a whole: if any row has a problem nothing is imported.
                New vendors arrive as drafts for approval. A vendor already on the list is skipped, never overwritten; change it on its profile (<strong>Open</strong>) instead.
            </p>
            <form method="POST" action="{{ route('merchant.vendors.import') }}" enctype="multipart/form-data" class="row g-2 align-items-end">
                @csrf
                <div class="col-12 col-md-6 col-xl-5">
                    <label class="form-label" for="vendorFile">Excel or CSV file <span class="text-danger">*</span></label>
                    <input id="vendorFile" type="file" name="file" accept=".xlsx,.xls,.csv" class="form-control @error('file') is-invalid @enderror" required>
                    @error('file')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-auto d-flex gap-2">
                    <button class="btn btn-primary"><i class="bi bi-upload me-1" aria-hidden="true"></i>Import</button>
                    <a href="{{ route('merchant.vendors.template') }}" class="btn btn-outline-secondary"><i class="bi bi-download me-1" aria-hidden="true"></i>Download Template</a>
                </div>
            </form>
        </x-card>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-6 col-md-4"><x-stat-card icon="buildings" tone="primary" label="Vendors" :value="$totals['all']" /></div>
        <div class="col-6 col-md-4"><x-stat-card icon="check2-circle" tone="success" label="Active" :value="$totals['active']" /></div>
        <div class="col-12 col-md-4"><x-stat-card icon="hourglass-split" tone="info" label="Awaiting approval" :value="$totals['pending']" /></div>
    </div>

    <x-card body-class="p-0">
        <div class="p-3 border-bottom">
            <form method="GET" action="{{ route('merchant.vendors.index') }}" class="row g-2 align-items-center">
                <div class="col-12 col-md-6 col-xl-4">
                    <label class="visually-hidden" for="vendorSearch">Search vendors</label>
                    <input id="vendorSearch" type="search" name="q" value="{{ $term }}" class="form-control"
                           placeholder="Search name, code, contact, city or item type">
                </div>
                <div class="col-auto d-flex gap-2">
                    <button class="btn btn-primary"><i class="bi bi-search me-1" aria-hidden="true"></i>Search</button>
                    @if($term !== '')
                        <a href="{{ route('merchant.vendors.index') }}" class="btn btn-outline-secondary"><i class="bi bi-x-circle me-1" aria-hidden="true"></i>Clear</a>
                    @endif
                </div>
            </form>
        </div>

        @if($vendors->isEmpty())
            <div class="text-center text-muted py-5">
                <i class="bi bi-buildings fs-2 d-block mb-2" aria-hidden="true"></i>
                {{ $term !== '' ? 'No vendor matches your search.' : 'No vendor yet. Press Add Vendor to create the first one.' }}
            </div>
        @else
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Vendor</th>
                            <th>Contact</th>
                            <th>Item Type</th>
                            <th>Location</th>
                            <th>Approval</th>
                            <th>Status</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($vendors as $vendor)
                            <tr>
                                <td>
                                    <div class="fw-semibold text-body">{{ $vendor->supplier_name }}</div>
                                    <div class="small text-muted">{{ $vendor->supplier_code ?: 'No code' }}@if($vendor->legal_name) · {{ $vendor->legal_name }}@endif</div>
                                </td>
                                <td>
                                    <div>{{ $vendor->contact_person ?: '—' }}</div>
                                    <div class="small text-muted">{{ collect([$vendor->phone, $vendor->email])->filter()->implode(' · ') ?: '—' }}</div>
                                </td>
                                <td>{{ $vendor->item_type ?: '—' }}</td>
                                <td>{{ collect([$vendor->city, $vendor->country])->filter()->implode(', ') ?: '—' }}</td>
                                <td>
                                    @php $ap = ['draft' => 'secondary', 'submitted' => 'info', 'under_review' => 'info', 'approved' => 'success', 'rejected' => 'danger'][$vendor->approval_status] ?? 'secondary'; @endphp
                                    <span class="badge bg-{{ $ap }}-subtle text-{{ $ap }}-emphasis">{{ \App\Models\Supplier::APPROVALS[$vendor->approval_status] ?? $vendor->approval_status }}</span>
                                </td>
                                <td>
                                    <span class="badge {{ $vendor->vendor_status === 'active' ? 'bg-success-subtle text-success' : ($vendor->vendor_status === 'blocked' ? 'bg-danger-subtle text-danger' : 'bg-secondary-subtle text-secondary') }}">{{ \App\Models\Supplier::STATUSES[$vendor->vendor_status] ?? 'Inactive' }}</span>
                                </td>
                                <td class="text-end">
                                    <a href="{{ route('vendors.show', $vendor) }}" class="btn btn-sm btn-outline-primary">
                                        <i class="bi bi-eye me-1" aria-hidden="true"></i>Open
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if($vendors->hasPages())
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 p-3 border-top">
                    <div class="small text-muted">Showing {{ $vendors->firstItem() }} to {{ $vendors->lastItem() }} of {{ $vendors->total() }} vendors</div>
                    {{ $vendors->links() }}
                </div>
            @endif
        @endif
    </x-card>
</div>
@endsection
