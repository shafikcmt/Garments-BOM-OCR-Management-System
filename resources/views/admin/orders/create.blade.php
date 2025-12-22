@extends('layouts.app')

@section('content')
<div class="container-fluid">
    <div class="row justify-content-center">
        <div class="col-lg-10">

            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0">
                        <i class="bi bi-bag-plus me-2"></i>
                        Create New Order
                    </h5>
                </div>

                <div class="card-body">

                    {{-- Validation Errors --}}
                    @if ($errors->any())
                        <div class="alert alert-danger">
                            <ul class="mb-0">
                                @foreach ($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <form method="POST" action="{{ route('orders.store') }}">
                        @csrf

                        <div class="row g-3">

                            {{-- Order Number --}}
                            <div class="col-md-4">
                                <label class="form-label">Order Number</label>
                                <input type="text" name="order_number"
                                       class="form-control"
                                       value="{{ old('order_number') }}"
                                       required>
                            </div>

                            {{-- Buyer Name --}}
                            <div class="col-md-4">
                                <label class="form-label">Buyer Name</label>
                                <input type="text" name="buyer_name"
                                       class="form-control"
                                       value="{{ old('buyer_name') }}"
                                       required>
                            </div>

                            {{-- Season --}}
                            <div class="col-md-4">
                                <label class="form-label">Season</label>
                                <input type="text" name="season_name"
                                       class="form-control"
                                       value="{{ old('season_name') }}"
                                       required>
                            </div>

                            {{-- Style --}}
                            <div class="col-md-4">
                                <label class="form-label">Style Name</label>
                                <input type="text" name="style_name"
                                       class="form-control"
                                       value="{{ old('style_name') }}"
                                       required>
                            </div>

                            {{-- Quantity --}}
                            <div class="col-md-4">
                                <label class="form-label">Quantity</label>
                                <input type="number" name="quantity"
                                       class="form-control"
                                       value="{{ old('quantity') }}"
                                       required>
                            </div>

                            {{-- Contract Number --}}
                            <div class="col-md-4">
                                <label class="form-label">Contract Number</label>
                                <input type="text" name="contract_number"
                                       class="form-control"
                                       value="{{ old('contract_number') }}"
                                       required>
                            </div>

                            {{-- Shipment Date --}}
                            <div class="col-md-4">
                                <label class="form-label">Shipment Date</label>
                                <input type="date" name="shipment_date"
                                       class="form-control"
                                       value="{{ old('shipment_date') }}"
                                       required>
                            </div>

                            {{-- Status (Readonly) --}}
                            <div class="col-md-4">
                                <label class="form-label">Order Status</label>
                                <input type="text"
                                       class="form-control bg-light"
                                       value="Pending Approval"
                                       readonly>
                            </div>

                        </div>

                        {{-- Buttons --}}
                        <div class="mt-4 d-flex justify-content-end gap-2">
                            <a href="{{ route('orders.index') }}"
                               class="btn btn-secondary">
                                Cancel
                            </a>
                            <button type="submit"
                                    class="btn btn-success">
                                <i class="bi bi-check-circle me-1"></i>
                                Submit Order
                            </button>
                        </div>

                    </form>

                </div>
            </div>

        </div>
    </div>
</div>
@endsection
