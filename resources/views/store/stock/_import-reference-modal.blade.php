{{--
    Reference-data import for the Stock Report.

    Included only when the viewer holds the correction right — the controller
    checks the same right again, because hiding a button is presentation, not a
    guard.
--}}
<div class="modal fade" id="importReference" tabindex="-1" aria-labelledby="importReferenceTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content gx-stock-card">
            <div class="modal-header">
                <h5 class="modal-title" id="importReferenceTitle">Import Reference Data</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="gx-stock-help mb-3">
                    Fills in Unit Price, Date of Last Addition and Date of Last Consumption for items
                    carried over from the old Excel sheets. You will see what would change before
                    anything is saved.
                </p>

                <div class="gx-edit-alert mb-3">
                    <i class="bi bi-info-circle-fill" aria-hidden="true"></i>
                    <div>
                        <div class="gx-edit-alert-title">Blank columns only</div>
                        <div class="gx-edit-alert-body">
                            A column that already shows a value on this report is never changed, even
                            if the file has something different in it. The three columns are decided
                            separately, so an item missing only its price keeps the dates it has.
                            Items are matched on <strong>Item Name and Brand/Specification together</strong> —
                            many names are stocked under more than one brand, so a name alone can point
                            at the wrong item.
                        </div>
                    </div>
                </div>

                <a href="{{ route('store.stock.ledger.import.template') }}" class="btn btn-outline-secondary w-100 mb-3">
                    Download Blank Template
                </a>

                <form method="POST" action="{{ route('store.stock.ledger.import.preview') }}" enctype="multipart/form-data" id="importReferenceForm">
                    @csrf
                    <input type="file" name="file" class="form-control mb-2" accept=".csv,.txt,.xlsx,.xls" required
                           aria-label="CSV or Excel file of reference data to import">
                    <p class="gx-stock-help mb-0">
                        Five columns: Item Name, Brand/Specification, Unit Price, Last Addition Date,
                        Last Consumption Date. A blank cell leaves that column alone rather than
                        clearing it, and a row matching no item is reported instead of being created.
                        No receiving or issue record is ever created by this import.
                    </p>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" form="importReferenceForm" class="btn btn-primary">
                    Check File
                </button>
            </div>
        </div>
    </div>
</div>
