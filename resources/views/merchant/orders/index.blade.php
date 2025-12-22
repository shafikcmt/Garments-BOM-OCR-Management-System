@extends('layouts.app')

@section('content')
<div class="container py-4">

    {{-- HEADER --}}
    <div class="card shadow-sm mb-4">
        <div class="card-body d-flex justify-content-between align-items-center">
            <h4 class="mb-0">My Orders</h4>
            <button class="btn btn-primary"
                    data-bs-toggle="modal"
                    data-bs-target="#orderModal"
                    onclick="resetForm()">
                <i class="bi bi-plus-lg"></i> Add New
            </button>
        </div>
    </div>

    {{-- FILTERS --}}
    <div class="row mb-3 align-items-end">

    <!-- Status -->
    <div class="col-md-2">
        <label class="form-label">Status</label>
        <select id="filterStatus" class="form-select">
            <option value="">All Status</option>
            <option value="draft">Draft</option>
            <option value="pending">Pending</option>
            <option value="processing">Processing</option>
            <option value="approved">Approved</option>
            <option value="rejected">Rejected</option>
        </select>
    </div>

    <!-- Buyer (Auto Suggest) -->
    <div class="col-md-2">
        <label class="form-label">Buyer</label>
        <input type="text"
               id="filterBuyer"
               class="form-control"
               list="buyerList"
               placeholder="Type buyer name">

        <datalist id="buyerList">
            @foreach($orders->pluck('buyer_name')->unique() as $buyer)
                <option value="{{ $buyer }}"></option>
            @endforeach
        </datalist>
    </div>

    <!-- Season (Auto Suggest) -->
    <div class="col-md-2">
        <label class="form-label">Season</label>
        <input type="text"
               id="filterSeason"
               class="form-control"
               list="seasonList"
               placeholder="Type season">

        <datalist id="seasonList">
            @foreach($orders->pluck('season_name')->unique() as $season)
                <option value="{{ $season }}"></option>
            @endforeach
        </datalist>
    </div>

    <!-- Date From -->
    <div class="col-md-2">
        <label class="form-label">From</label>
        <input type="date" id="filterDateFrom" class="form-control">
    </div>

    <!-- TO -->
    <div class="col-md-1 text-center fw-bold">
        <span style="line-height:38px;">TO</span>
    </div>

    <!-- Date To -->
    <div class="col-md-2">
        <label class="form-label">To</label>
        <input type="date" id="filterDateTo" class="form-control">
    </div>

    <!-- Clear -->
    <div class="col-md-1">
        <button class="btn btn-outline-secondary w-100" id="clearFilters">
            Clear
        </button>
    </div>

</div>


    {{-- TABLE --}}
    <div class="card shadow-sm">
        <div class="card-body table-responsive">
            <table class="table table-hover align-middle" id="ordersTable">
                <thead class="table-dark">
                    <tr>
                        <th>#</th>
                        <th>Order No</th>
                        <th>Buyer</th>
                        <th>Season</th>
                        <th>Style</th>
                        <th>Qty</th>
                        <th>Shipment Date</th>
                        <th>Contract No</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($orders as $order)
                    <tr>
                        <td></td>
                        <td>{{ $order->order_number }}</td>
                        <td>{{ $order->buyer_name }}</td>
                        <td>{{ $order->season_name }}</td>
                        <td>{{ $order->style_name }}</td>
                        <td>{{ $order->quantity }}</td>
                        <td>{{ \Carbon\Carbon::parse($order->shipment_date)->format('Y-m-d') }}</td>
                        <td>{{ $order->contract_number }}</td>
                        <td>{{ $order->status }}</td>
                        <td>
                            @if($order->status === 'approved')
                                <span class="badge bg-success">Locked</span>
                            @else
                                <button class="btn btn-sm btn-outline-primary"
                                    data-bs-toggle="modal"
                                    data-bs-target="#orderModal"
                                    onclick='editOrder(@json($order))'>
                                    Edit
                                </button>

                                <form method="POST"
                                      action="{{ route('merchant.orders.destroy',$order->id) }}"
                                      class="d-inline">
                                    @csrf
                                    @method('DELETE')
                                    <button class="btn btn-sm btn-outline-danger"
                                            onclick="return confirm('Delete this order?')">
                                        Delete
                                    </button>
                                </form>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>

{{-- MODAL --}}
<div class="modal fade" id="orderModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form method="POST"
              action="{{ route('merchant.orders.store') }}"
              class="modal-content">
            @csrf

            <input type="hidden" name="order_id" id="order_id">

            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="modalTitle">Create Order</h5>
                <button type="button"
                        class="btn-close btn-close-white"
                        data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <input type="text" id="buyer_name" name="buyer_name"
                               class="form-control" placeholder="Buyer Name" required>
                    </div>

                    <div class="col-md-6">
                        <input type="text" id="season_name" name="season_name"
                               class="form-control" placeholder="Season Name" required>
                    </div>

                    <div class="col-md-6">
                        <input type="text" id="order_number" name="order_number"
                               class="form-control" placeholder="Order Number" required>
                    </div>

                    <div class="col-md-6">
                        <input type="text" id="style_name" name="style_name"
                               class="form-control" placeholder="Style Name" required>
                    </div>

                    <div class="col-md-6">
                        <input type="number" id="quantity" name="quantity"
                               class="form-control" placeholder="Quantity" required>
                    </div>

                    <div class="col-md-6">
                        <input type="date" id="shipment_date" name="shipment_date"
                               class="form-control" required>
                    </div>

                    <div class="col-md-6">
                        <input type="text" id="contract_number" name="contract_number"
                               class="form-control" placeholder="Contract Number" required>
                    </div>

                    <div class="col-md-6">
                        <select id="status" name="status" class="form-select">
                            <option value="draft">Draft</option>
                            <option value="pending">Pending</option>
                            <option value="processing">Processing</option>
                            <option value="rejected">Rejected</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="reset" class="btn btn-secondary" onclick="resetForm()">Clear</button>
                <button type="submit" class="btn btn-success">Save</button>
            </div>
        </form>
    </div>
</div>
@endsection

@section('scripts')
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>

<script>
$(function () {

    // ✅ Custom Date Filter (Shipment Date column = index 6)
    $.fn.dataTable.ext.search.push(function (settings, data) {
        let min = $('#filterDateFrom').val();
        let max = $('#filterDateTo').val();
        let shipDate = data[6]; // Shipment Date column (YYYY-MM-DD or DD-MM-YYYY)

        if (!shipDate) return true;

        let date = new Date(shipDate.split('-').reverse().join('-'));

        if (
            (min === '' && max === '') ||
            (min === '' && date <= new Date(max)) ||
            (new Date(min) <= date && max === '') ||
            (new Date(min) <= date && date <= new Date(max))
        ) {
            return true;
        }
        return false;
    });

    // ✅ DataTable Init
    let table = $('#ordersTable').DataTable({
        pageLength: 10,
        lengthMenu: [5,10,25,50],
        order: [[1,'desc']],
        searching: true,
        ordering: true,
        columnDefs: [
            { targets: 0, searchable:false, orderable:false },
            { targets: 9, searchable:false, orderable:false }
        ]
    });

    // ✅ SERIAL NUMBER (WORKING)
    table.on('draw.dt', function () {
        let info = table.page.info();
        table.column(0, { page:'current' }).nodes().each(function (cell, i) {
            cell.innerHTML = info.start + i + 1;
        });
    }).draw();

    // ✅ FILTERS
    $('#filterStatus').on('change', function () {
        table.column(8).search(this.value).draw();
    });

    $('#filterBuyer').on('keyup', function () {
        table.column(2).search(this.value).draw();
    });

    $('#filterSeason').on('keyup', function () {
        table.column(3).search(this.value).draw();
    });

    // ✅ DATE FILTER TRIGGER
    $('#filterDateFrom, #filterDateTo').on('change', function () {
        table.draw();
    });

    // CLEAR ALL FILTERS
    $('#clearFilters').on('click', function () {
        $('#filterStatus').val('');
        $('#filterBuyer').val('');
        $('#filterSeason').val('');
        $('#filterDateFrom').val('');
        $('#filterDateTo').val('');

        let table = $('#ordersTable').DataTable();

        table
            .search('')
            .columns().search('')
            .draw();
    });


});

// ✅ RESET FORM
function resetForm() {
    $('#order_id').val('');
    $('#buyer_name,#season_name,#order_number,#style_name,#quantity,#shipment_date,#contract_number').val('');
    $('#status').val('draft');
    $('#modalTitle').text('Create Order');
}

// ✅ EDIT PREFILL (WORKING)
function editOrder(order) {
    $('#order_id').val(order.id);
    $('#buyer_name').val(order.buyer_name);
    $('#season_name').val(order.season_name);
    $('#order_number').val(order.order_number);
    $('#style_name').val(order.style_name);
    $('#quantity').val(order.quantity);
    $('#contract_number').val(order.contract_number);
    $('#status').val(order.status);

    if (order.shipment_date) {
        $('#shipment_date').val(order.shipment_date.substring(0,10));
    }

    $('#modalTitle').text('Edit Order');
}
</script>

@endsection
