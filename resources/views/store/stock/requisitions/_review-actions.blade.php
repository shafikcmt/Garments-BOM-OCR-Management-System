{{--
    Received / Approve, the Store's two manual steps on a department's request.

    Shared by the review queue and the single-requisition screen so the same
    rules decide both: a button appears only when the requisition is actually at
    that step AND this user holds the permission for it.

    The permissions are the ones PurchaseRequisitionPermissionSeeder already
    defines. store.requisition.approve is deliberately NOT granted to the store
    role there — whoever raises a requisition must not approve the spend — so a
    Store user sees Received but not Approve, which is the separation of duties
    the paper form's signature boxes have always carried.

    Expects: $requisition, and optionally $small for the table-row size.
--}}
@php
    $size = ($small ?? false) ? 'btn-sm' : '';
    $user = auth()->user();
    $returnModalId = 'returnRequisition'.$requisition->id;
@endphp

{{-- The Department Head's step, before the Store's. Shown only to the
     configured head of this requisition's department (or Admin /
     Management), and only while the sheet waits on them. Returning it needs
     a reason, so the requester knows what to change — hence the modal. --}}
@if($requisition->awaitingDepartmentHead() && $requisition->isDepartmentHeadFor($user))
    <form method="POST" action="{{ route('store.stock.requisitions.department-approve', $requisition) }}" class="d-inline"
          onsubmit="return confirm('Check and send requisition {{ $requisition->requisition_no }} to Store?');">
        @csrf
        <button type="submit" class="btn {{ $size }} btn-outline-success">
            <i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Approve
        </button>
    </form>
    <button type="button" class="btn {{ $size }} btn-outline-danger" data-bs-toggle="modal" data-bs-target="#{{ $returnModalId }}">
        <i class="bi bi-arrow-return-left me-1" aria-hidden="true"></i>Return
    </button>

    <div class="modal fade text-start" id="{{ $returnModalId }}" tabindex="-1" aria-labelledby="{{ $returnModalId }}Label" aria-hidden="true">
        <div class="modal-dialog">
            <form method="POST" action="{{ route('store.stock.requisitions.department-reject', $requisition) }}" class="modal-content">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title" id="{{ $returnModalId }}Label">Return {{ $requisition->requisition_no }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <label class="form-label fw-semibold" for="{{ $returnModalId }}Note">Reason for returning</label>
                    <textarea name="note" id="{{ $returnModalId }}Note" class="form-control" rows="3" maxlength="1000" required
                              placeholder="What should the requester change before submitting again?"></textarea>
                    <div class="form-text">The requester will see this note and can amend and resubmit the requisition.</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger"><i class="bi bi-arrow-return-left me-1" aria-hidden="true"></i>Return to Requester</button>
                </div>
            </form>
        </div>
    </div>
@endif

@if($requisition->awaitingStoreReceipt() && $user?->can('store.requisition.review'))
    <form method="POST" action="{{ route('store.stock.requisitions.received', $requisition) }}" class="d-inline">
        @csrf
        <button type="submit" class="btn {{ $size }} btn-outline-primary">
            <i class="bi bi-inbox-fill me-1" aria-hidden="true"></i>Received
        </button>
    </form>
@endif

@if($requisition->awaitingApproval() && $user?->can('store.requisition.approve'))
    <form method="POST" action="{{ route('store.stock.requisitions.approve', $requisition) }}" class="d-inline"
          onsubmit="return confirm('Approve requisition {{ $requisition->requisition_no }}? It then goes forward for procurement.');">
        @csrf
        <button type="submit" class="btn {{ $size }} btn-outline-success">
            <i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Approve
        </button>
    </form>
@endif
