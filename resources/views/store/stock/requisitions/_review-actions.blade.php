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
@endphp

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
