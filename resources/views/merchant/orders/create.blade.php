@extends('layouts.app')

@section('content')
<div class="container">
    <div class="card shadow-sm">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0">Create New Order</h5>
        </div>

        <div class="card-body">
            <form method="POST" action="{{ route('merchant.orders.store') }}">
                @csrf

                <div class="row g-3">

                    <div class="col-md-4">
                        <label class="form-label">Order Number</label>
                        <input type="text" name="order_number" class="form-control" required>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Buyer Name</label>
                        <input type="text" name="buyer_name" class="form-control" required>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Season</label>
                        <input type="text" name="season_name" class="form-control">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Style Name</label>
                        <input type="text" name="style_name" class="form-control">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Quantity</label>
                        <input type="number" name="quantity" class="form-control" required>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Contract Number</label>
                        <input type="text" name="contract_number" class="form-control">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Shipment Date</label>
                        <input type="date" name="shipment_date" class="form-control">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Shipment Month</label>
                        <input type="text" name="shipment_month" class="form-control">
                    </div>

                </div>

                <div class="mt-4 text-end">
                    <button class="btn btn-success">
                        <i class="bi bi-send"></i> Submit for Approval
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
