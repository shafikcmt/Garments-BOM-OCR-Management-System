@extends('layouts.app')

@section('content')

<style>
    /* ===== COMPACT ENTERPRISE TABLE ===== */
.orders-table {
    white-space: nowrap;
    width: max-content;
    min-width: 100%;
    font-size: 13px;
    /* padding:10px; */
}

.orders-table th
{
    border-bottom: 0 solid transparent;
    padding: 13px 15px;
    border: 0px solid transparent;
    font-size: 13px;
    font-weight: 700;
    color: #5B89BA;
    white-space: nowrap;
    text-transform: capitalize;
    font-family: "Nunito", sans-serif;
    border: 0;
    background: #fff;
    border: 0 !important;
    background: #F8FAFF;
    text-align:center !important;
} 

/* Remove extra padding */
.orders-table td {
    font-size: 14px;
    font-weight: 400;
    border-bottom: 0;
    padding: 8px 15px;

}

/* Prevent wrapping */
.orders-table td,
.orders-table th {
    white-space: nowrap;
}

/* Smaller action buttons */
.btn-xs {
    padding: 2px 6px;
    font-size: 11px;
}

/* Remove DataTables sort icons */
table.dataTable thead .sorting,
table.dataTable thead .sorting_asc,
table.dataTable thead .sorting_desc {
    background-image: none !important;
}

/* Cursor normal */
.orders-table th {
    cursor: default;
}

</style>
<div class="container py-4">

    {{-- HEADER --}}
    <div class="card shadow-sm mb-2">
        <div class="card-body d-flex justify-content-between align-items-center">
            <h5 class="mb-0" id="tableTitle">My Orders</h5>
            <button class="btn btn-primary"
                    data-bs-toggle="modal"
                    data-bs-target="#orderModal"
                    onclick="resetForm()">
                <i class="bi bi-plus-lg"></i> Add New
            </button>
        </div>
    </div>

    {{-- FILTERS --}}
    <div class="row mb-2 align-items-end">

        <div class="col-md-2">
            <label class="form-label">Status</label>
            <select id="filterStatus" class="form-select">
                <option value="">All Status</option>
                <option value="Open">Open</option>
                <option value="Processing">Processing</option>
                <option value="Completed">Completed</option>
            </select>
        </div>

        <div class="col-md-2">
            <label class="form-label">Buyer</label>
            <input type="text" id="filterBuyer" class="form-control" list="buyerList" placeholder="Type buyer name">
            <datalist id="buyerList">
                @foreach($orders->pluck('buyer_name')->unique() as $buyer)
                    <option value="{{ $buyer }}"></option>
                @endforeach
            </datalist>
        </div>

        <div class="col-md-2">
            <label class="form-label">Season</label>
            <input type="text" id="filterSeason" class="form-control" list="seasonList" placeholder="Type season">
            <datalist id="seasonList">
                @foreach($orders->pluck('season_name')->unique() as $season)
                    <option value="{{ $season }}"></option>
                @endforeach
            </datalist>
        </div>

        <div class="col-md-2">
            <label class="form-label">From</label>
            <input type="date" id="filterDateFrom" class="form-control">
        </div>

        <div class="col-md-1 text-center fw-bold">
            <span style="line-height:38px;">TO</span>
        </div>

        <div class="col-md-2">
            <label class="form-label">To</label>
            <input type="date" id="filterDateTo" class="form-control">
        </div>

        <div class="col-md-1">
            <button class="btn btn-outline-secondary w-100" id="clearFilters">Clear</button>
        </div>

    </div>

  {{-- SHOW/HIDE COLUMNS --}}

  @php
    $columns = [
        '#',
        'Buyer',
        'Division',
        'Season',
        'Order Category',
        'Product Type',
        'Style',
        'PO Number #',
        'Description',
        'Wash Type',
        'Order Qty',
        'Sewing Qty',
        'Balance to sewing',
        'SMV',
        'Total Minutes',
        'FOB',
        'Sales Value',
        'GM',
        'Destination',
        'PCD',
        'X-Fty',
        'X-Country',
        'Original X-Fty',
        'Original X-Country',
        'Shipment Status',
        'Fabric Booking Status',
        'Remarks',
        'Status',
        'Action'
    ];
@endphp

  <div class="d-flex justify-content-between align-items-start mb-3 flex-wrap">

    {{-- SHOW / HIDE COLUMNS DROPDOWN --}}
    <div class="dropdown">
        <button class="btn btn-outline-primary dropdown-toggle" type="button" data-bs-toggle="dropdown">
            Show / Hide Columns
        </button>
        <div class="dropdown-menu p-3" style="max-height: 300px; overflow:auto; min-width:250px;">
            <div class="d-flex justify-content-between mb-2">
                <button class="btn btn-sm btn-success" id="selectAllColumns">Select All</button>
                <button class="btn btn-sm btn-danger" id="unselectAllColumns">Unselect</button>
            </div>
            @foreach($columns as $index => $col)
                <div class="form-check">
                    <input class="form-check-input column-toggle" type="checkbox" data-column="{{ $index }}" checked>
                    <label class="form-check-label">{{ $col }}</label>
                </div>
            @endforeach
        </div>
    </div>

    {{-- SELECTED ACTIONS --}}
    <div id="selectedActions" class="d-none text-end mb-2">
        <button class="btn btn-sm btn-outline-primary me-1" id="editSelected">
            <i class="bi bi-pencil-square"></i> Edit
        </button>
        <button class="btn btn-sm btn-outline-danger" id="deleteSelected">
            <i class="bi bi-trash-fill"></i> Delete
        </button>
    </div>

</div>


    {{-- TABLE --}}
    <div class="card shadow-sm">
    <div class="card-body p-4">
        <div class="table-responsive">
           <table id="ordersTable" class="table table-striped table-bordered nowrap">
    <thead>
        <tr>
            <th><input type="checkbox" id="selectAllRows"></th> <!-- Select all -->
            <th>SL</th>
            <th>Buyer Name</th>
            <th>Division</th>
            <th>Season</th>
            <th>Order Category</th>
            <th>Product Type</th>
            <th>Style Name</th>
            <th>PO #</th>
            <th>Description</th>
            <th>Wash Type</th>
            <th>Order Qty</th>
            <th>Sewing Qty</th>
            <th>Balance to Sewing</th>
            <th>SMV</th>
            <th>Total Minutes</th>
            <th>FOB</th>
            <th>Sales Value</th>
            <th>GM</th>
            <th>Destination</th>
            <th>PCD</th>
            <th>X-Fty</th>
            <th>X-Country</th>
            <th>Original X-Fty</th>
            <th>Original X-Country</th>
            <th>Shipment Status</th>
            <th>Fabric Booking Status</th>
            <th>Remarks</th>
            <th>Order Status</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
        @foreach($orders as $order)
        <tr>
            <td><input type="checkbox" class="rowCheckbox" data-id="{{ $order->id }}"></td>
            <td></td> <!-- SL will be auto-filled by DataTables -->
            <td data-field="buyer_name">{{ $order->buyer_name }}</td>
            <td data-field="division">{{ $order->division }}</td>
            <td data-field="season_name">{{ $order->season_name }}</td>
            <td data-field="order_category">{{ $order->order_category }}</td>
            <td data-field="product_type">{{ $order->product_type }}</td>
            <td data-field="style_name">{{ $order->style_name }}</td>
            <td data-field="po_number">{{ $order->po_number }}</td>
            <td data-field="description">{{ $order->description }}</td>
            <td data-field="wash_type">{{ $order->wash_type }}</td>
            <td data-field="order_qty">{{ $order->order_qty }}</td>
            <td data-field="sewing_qty">{{ $order->sewing_qty }}</td>
            <td data-field="balance_to_sewing">{{ $order->balance_to_sewing }}</td>
            <td data-field="smv">{{ $order->smv }}</td>
            <td data-field="total_minutes">{{ $order->total_minutes }}</td>
            <td data-field="fob">{{ $order->fob }}</td>
            <td data-field="sales_value">{{ $order->sales_value }}</td>
            <td data-field="gm">{{ $order->gm }}</td>
            <td data-field="destination">{{ $order->destination }}</td>
            <td data-field="pcd">{{ $order->pcd?->format('Y-m-d') }}</td>
            <td data-field="x_fty">{{ $order->x_fty?->format('Y-m-d') }}</td>
            <td data-field="x_country">{{ $order->x_country?->format('Y-m-d') }}</td>
            <td data-field="original_x_fty">{{ $order->original_x_fty?->format('Y-m-d') }}</td>
            <td data-field="original_x_country">{{ $order->original_x_country?->format('Y-m-d') }}</td>
            <td data-field="shipment_status">{{ $order->shipment_status }}</td>
            <td data-field="fabric_booking_status">{{ $order->fabric_booking_status }}</td>
            <td data-field="remarks">{{ $order->remarks }}</td>
            <td data-field="order_status">{{ $order->status }}</td>
            <td>
                <button class="btn btn-sm btn-primary editRowBtn" data-id="{{ $order->id }}">Edit</button>
                <button class="btn btn-sm btn-danger deleteRowBtn" data-id="{{ $order->id }}">Delete</button>
            </td>
        </tr>
        @endforeach
    </tbody>
</table>

        </div>
    </div>
</div>


</div>

{{-- MODAL --}}
<div class="modal fade" id="orderModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <form id="orderForm" method="POST" action="{{ route('merchant.orders.store') }}" class="modal-content shadow">
            @csrf
            <input type="hidden" name="order_id" id="order_id">

            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fw-semibold" id="modalTitle">Create Order</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body">
                <div class="row g-3">
                    <!-- Buyer/Order Info -->
                    <div class="col-md-3">
                        <label class="form-label">Buyer Name</label>
                        <input type="text" class="form-control" name="buyer_name" id="buyer_name" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Division</label>
                        <input type="text" class="form-control" name="division" id="division" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Season</label>
                        <input type="text" class="form-control" name="season_name" id="season_name" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Order Status</label>
                        <select class="form-select" name="order_status" id="order_status" required>
                            <option value="CONFIRMED">CONFIRMED</option>
                            <option value="PROCESSING">PROCESSING</option>
                            <option value="COMPLETED">COMPLETED</option>
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">Order Category</label>
                        <select class="form-select" name="order_category" id="order_category" required>
                            <option value="BULK">BULK</option>
                            <option value="SAMPLE">SAMPLE</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Product Type</label>
                        <input type="text" class="form-control" name="product_type" id="product_type" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Style Name</label>
                        <input type="text" class="form-control" name="style_name" id="style_name" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">PO #</label>
                        <input type="text" class="form-control" name="po_number" id="po_number" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Description</label>
                        <input type="text" class="form-control" name="description" id="description">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Wash Type</label>
                        <input type="text" class="form-control" name="wash_type" id="wash_type">
                    </div>

                    <!-- Quantities & Calculations -->
                    <div class="col-md-3">
                        <label class="form-label">Order Qty</label>
                        <input type="number" class="form-control" name="order_qty" id="order_qty" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Sewing Qty</label>
                        <input type="number" class="form-control" name="sewing_qty" id="sewing_qty" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Balance to Sewing</label>
                        <input type="number" class="form-control bg-light" name="balance_to_sewing" id="balance_to_sewing" readonly>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">SMV</label>
                        <input type="number" step="0.01" class="form-control" name="smv" id="smv">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Total Minutes</label>
                        <input type="number" class="form-control bg-light" name="total_minutes" id="total_minutes" readonly>
                        <small class="text-muted">Order Qty × SMV</small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">FOB</label>
                        <input type="number" step="0.01" class="form-control" name="fob" id="fob">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Sales Value</label>
                        <input type="number" class="form-control bg-light" name="sales_value" id="sales_value" readonly>
                        <small class="text-muted">Order Qty × FOB</small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">GM</label>
                        <input type="number" class="form-control" name="gm" id="gm">
                    </div>

                    <!-- Logistics -->
                    <div class="col-md-3">
                        <label class="form-label">Destination</label>
                        <input type="text" class="form-control" name="destination" id="destination">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">X-Fty</label>
                        <input type="date" class="form-control" name="x_fty" id="x_fty">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">PCD</label>
                        <input type="date" class="form-control bg-light" name="pcd" id="pcd" readonly>
                        <small class="text-muted">X-Fty − 45 days</small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">X-Country</label>
                        <input type="date" class="form-control bg-light" name="x_country" id="x_country" readonly>
                        <small class="text-muted">X-Fty + 3 days</small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Original X-Fty</label>
                        <input type="date" class="form-control" name="original_x_fty" id="original_x_fty">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Original X-Country</label>
                        <input type="date" class="form-control bg-light" name="original_x_country" id="original_x_country" readonly>
                        <small class="text-muted">Original X-Fty + 2 days</small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Shipment Status</label>
                        <input type="text" class="form-control" name="shipment_status" id="shipment_status">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Fabric Booking Status</label>
                        <input type="text" class="form-control" name="fabric_booking_status" id="fabric_booking_status">
                    </div>
                    <div class="col-md-12">
                        <label class="form-label">Remarks</label>
                        <textarea class="form-control" rows="2" name="remarks" id="remarks"></textarea>
                    </div>

                </div>
            </div>

            <div class="modal-footer">
                <button type="reset" class="btn btn-secondary" onclick="resetForm()">Clear</button>
                <button type="submit" class="btn btn-success">Save Order</button>
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

    /* ================= DATATABLE INIT ================= */
    let table = $('#ordersTable').DataTable({
        autoWidth: true,
        ordering: false,
        scrollX: true,
        pageLength: 10,
        columnDefs: [
            { targets: [0,1,-1], orderable:false, searchable:false }
        ]
    });

    /* ================= FILTERS ================= */
    $('#filterStatus').on('change', function () {
        table.column(28).search(this.value).draw();
    });

    $('#filterBuyer').on('keyup', function () {
        table.column(2).search(this.value).draw();
    });

    $('#filterSeason').on('keyup', function () {
        table.column(4).search(this.value).draw();
    });

    $.fn.dataTable.ext.search.push(function (settings, data) {
        let min = $('#filterDateFrom').val();
        let max = $('#filterDateTo').val();
        let pcd = data[20]; // PCD column

        if (!pcd) return true;

        let d = new Date(pcd);

        if (
            (!min && !max) ||
            (!min && d <= new Date(max)) ||
            (new Date(min) <= d && !max) ||
            (new Date(min) <= d && d <= new Date(max))
        ) return true;

        return false;
    });

    $('#filterDateFrom, #filterDateTo').on('change', function () {
        table.draw();
    });

    $('#clearFilters').on('click', function () {
        $('#filterStatus,#filterBuyer,#filterSeason,#filterDateFrom,#filterDateTo').val('');
        table.search('').columns().search('').draw();
    });

    /* ================= COLUMN TOGGLE ================= */
    $('.column-toggle').on('change', function () {
        let colIndex = $(this).data('column');
        table.column(colIndex).visible(this.checked);
    });

    $('#selectAllColumns').on('click', function () {
        $('.column-toggle').each(function () {
            $(this).prop('checked', true);
            table.column($(this).data('column')).visible(true);
        });
    });

    $('#unselectAllColumns').on('click', function () {
        $('.column-toggle').each(function () {
            let idx = $(this).data('column');
            if ([0,1,29].includes(idx)) {
                $(this).prop('checked', true);
                table.column(idx).visible(true);
            } else {
                $(this).prop('checked', false);
                table.column(idx).visible(false);
            }
        });
    });

    /* ================= SERIAL NUMBER ================= */
    table.on('draw.dt', function () {
        let info = table.page.info();
        table.column(1, { page:'current' }).nodes().each(function (cell, i) {
            cell.innerHTML = info.start + i + 1;
        });
    }).draw();

    /* ================= ROW SELECTION ================= */
    $('#selectAllRows').on('change', function () {
        $('.rowCheckbox').prop('checked', this.checked);
        toggleSelectedActions();
    });

    $(document).on('change', '.rowCheckbox', function () {
        toggleSelectedActions();
        if (!this.checked) $('#selectAllRows').prop('checked', false);
    });

    function toggleSelectedActions() {
        let count = $('.rowCheckbox:checked').length;
        $('#selectedActions').toggleClass('d-none', count === 0);
    }

    /* ================= EDIT SELECTED (TOP BUTTON) ================= */
    $('#editSelected').on('click', function () {
        let checked = $('.rowCheckbox:checked');
        if (checked.length !== 1) { alert('Select exactly ONE row to edit'); return; }
        let row = checked.closest('tr');
        fillModalFromRow(row);
        $('#modalTitle').text('Edit Order');
        $('#orderModal').modal('show');
    });

    /* ================= DELETE SELECTED ================= */
    $('#deleteSelected').on('click', function () {
        let checked = $('.rowCheckbox:checked');
        if (!checked.length) return;
        if (!confirm('Delete selected orders?')) return;

        checked.each(function () {
            let id = $(this).data('id');
            $.ajax({
                url: `/merchant/orders/${id}`,
                type: 'POST',
                data: { _method: 'DELETE', _token: $('input[name="_token"]').val() },
                success: () => table.row($(this).closest('tr')).remove().draw()
            });
        });
    });

    /* ================= ROW EDIT BUTTON ================= */
    $(document).on('click', '.editRowBtn', function () {
        let row = $(this).closest('tr');
        fillModalFromRow(row);
        $('#modalTitle').text('Edit Order');
        $('#orderModal').modal('show');
    });

    /* ================= ROW DELETE BUTTON ================= */
    $(document).on('click', '.deleteRowBtn', function () {
        let row = $(this).closest('tr');
        let id = $(this).data('id');
        if (!confirm('Delete this order?')) return;

        $.ajax({
            url: `/merchant/orders/${id}`,
            type: 'POST',
            data: { _method: 'DELETE', _token: $('input[name="_token"]').val() },
            success: () => table.row(row).remove().draw()
        });
    });

    /* ================= FILL MODAL FROM TABLE ROW ================= */
    function fillModalFromRow(row) {
        let orderId = row.find('.rowCheckbox').data('id');
        $('#order_id').val(orderId);

        let form = $('#orderModal form');
        form.attr('action', `/merchant/orders/${orderId}`);

        if (!form.find('input[name="_method"]').length) {
            form.append('<input type="hidden" name="_method" value="PUT">');
        }

        row.find('td[data-field]').each(function () {
            let field = $(this).data('field');
            $('#' + field).val($(this).text());
        });

        $('#order_status').val(row.find('td[data-field="order_status"]').text());
    }

    /* ================= CALCULATIONS ================= */
    function calcValues() {
        let oq = +$('#order_qty').val() || 0;
        let sq = +$('#sewing_qty').val() || 0;
        let smv = +$('#smv').val() || 0;
        let fob = +$('#fob').val() || 0;

        $('#balance_to_sewing').val(oq - sq);
        $('#total_minutes').val((oq * smv).toFixed(2));
        $('#sales_value').val((oq * fob).toFixed(2));
    }

    $('#order_qty,#sewing_qty,#smv,#fob').on('input', calcValues);

    /* ================= DATE LOGIC ================= */
    $('#x_fty').on('change', function () {
        let d = new Date(this.value);
        if (!isNaN(d)) {
            $('#pcd').val(new Date(d.getTime() - 45*24*60*60*1000).toISOString().slice(0,10));
            $('#x_country').val(new Date(d.getTime() + 3*24*60*60*1000).toISOString().slice(0,10));
        }
    });

    $('#original_x_fty').on('change', function () {
        let d = new Date(this.value);
        if (!isNaN(d)) {
            $('#original_x_country').val(new Date(d.getTime() + 2*24*60*60*1000).toISOString().slice(0,10));
        }
    });

    /* ================= RESET ================= */
    window.resetForm = function() {
        let form = $('#orderModal form');
        form[0].reset();
        $('#order_id').val('');
        $('#modalTitle').text('Create Order');
        form.attr('action', '{{ route("merchant.orders.store") }}');
        form.find('input[name="_method"]').remove();
    }

});




</script>


@endsection
