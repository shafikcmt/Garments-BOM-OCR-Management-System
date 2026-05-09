@extends('layouts.app')

@section('title', 'Vendor Control')

@section('content')
<div class="container-fluid">

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    @if(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    <div class="card shadow-sm border-0 mb-3">
        <div class="card-body d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h3 class="mb-1">Vendor / Supplier Control</h3>
                <p class="text-muted mb-0">Create, update and manage booking format supplier information.</p>
            </div>

            <a href="{{ route('admin.suppliers.create') }}" class="btn btn-primary">
                <i class="bi bi-plus-circle me-1"></i> Add Vendor
            </a>
        </div>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-body table-responsive">
            <table class="table table-bordered table-hover align-middle mb-0">
                <thead class="table-dark">
                    <tr>
                        <th style="width:60px;">SL</th>
                        <th>Supplier Name</th>
                        <th>Contact</th>
                        <th>Email</th>
                        <th>Address</th>
                        <th>Item Type</th>
                        <th>Incoterm</th>
                        <th>Ship Mode</th>
                        <th>Status</th>
                        <th style="width:170px;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($suppliers as $supplier)
                        <tr>
                            <td>{{ $loop->iteration + ($suppliers->currentPage() - 1) * $suppliers->perPage() }}</td>
                            <td>
                                <strong>{{ $supplier->supplier_name }}</strong>

                                @if($supplier->supplier_code)
                                    <div class="small text-muted">Code: {{ $supplier->supplier_code }}</div>
                                @endif

                                @if($supplier->legal_name)
                                    <div class="small text-muted">{{ $supplier->legal_name }}</div>
                                @endif
                            </td>
                            <td>
                                {{ $supplier->contact_person ?? '-' }}

                                @if($supplier->phone)
                                    <div class="small text-muted">{{ $supplier->phone }}</div>
                                @endif
                            </td>
                            <td>{{ $supplier->email ?? '-' }}</td>
                            <td>{{ $supplier->full_address ?: '-' }}</td>
                            <td>{{ $supplier->item_type ?? '-' }}</td>
                            <td>{{ $supplier->incoterm ?? '-' }}</td>
                            <td>{{ $supplier->ship_mode ?? '-' }}</td>
                            <td>
                                @if($supplier->is_active)
                                    <span class="badge bg-success">Active</span>
                                @else
                                    <span class="badge bg-danger">Inactive</span>
                                @endif
                            </td>
                            <td>
                                <div class="d-flex gap-1">
                                    <a href="{{ route('admin.suppliers.edit', $supplier) }}" class="btn btn-sm btn-warning">
                                        <i class="bi bi-pencil-square"></i>
                                    </a>

                                    <form action="{{ route('admin.suppliers.destroy', $supplier) }}" method="POST" onsubmit="return confirm('Are you sure you want to delete this vendor?');">
                                        @csrf
                                        @method('DELETE')

                                        <button type="submit" class="btn btn-sm btn-danger">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="text-center text-muted py-4">
                                No vendor found.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>

            <div class="mt-3">
                {{ $suppliers->links() }}
            </div>
        </div>
    </div>
</div>
@endsection