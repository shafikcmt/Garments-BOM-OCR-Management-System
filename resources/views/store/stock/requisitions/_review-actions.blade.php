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

    // Stage engine: the one person named on the sheet's plan for the stage
    // it waits at — never Admin or Management standing in, never the raiser.
    $onEngine = $requisition->usesStageEngine();
    $workflow = app(\App\Services\RequisitionWorkflow::class);
    $canStage = $onEngine && $workflow->canAct($requisition, $user);
    $stage = $canStage ? $workflow->currentStage($requisition) : null;
    // The old flow's head check keeps its own Approve / Return. On the stage
    // engine every stage, the head's included, has the Approve / Return pair
    // below.
    $headCanReturn = ! $onEngine
        && $requisition->awaitingDepartmentHead() && $requisition->isDepartmentHeadFor($user);
@endphp

@if($canStage)
    @php
        // The Store's own step reads "Received", not "Approve". When the next
        // step is the open Store team one, the approver names who gets it.
        $isReceive = $workflow->isStoreStep($requisition, $stage);
        $nextStage = $workflow->nextPending($stage);
        $sendOptions = $workflow->isDirectable($requisition, $nextStage) ? $workflow->sendToOptions($user, $requisition) : collect();
    @endphp
    <button type="button" class="btn {{ $size }} {{ $isReceive ? 'btn-outline-primary' : 'btn-outline-success' }}" data-bs-toggle="modal" data-bs-target="#stageApprove{{ $requisition->id }}">
        <i class="bi {{ $isReceive ? 'bi-inbox-fill' : 'bi-check2-circle' }} me-1" aria-hidden="true"></i>{{ $isReceive ? 'Received' : 'Approve' }}
    </button>
    <x-action-modal :id="'stageApprove'.$requisition->id"
                    :action="route('store.stock.requisitions.stage-approve', $requisition)"
                    :title="($isReceive ? 'Mark '.$requisition->requisition_no.' as received' : 'Approve '.$requisition->requisition_no)"
                    :submit-label="$isReceive ? 'Mark Received' : 'Approve'"
                    :submit-icon="$isReceive ? 'bi-inbox-fill' : 'bi-check2-circle'"
                    :submit-class="$isReceive ? 'btn-primary' : 'btn-success'">
        {{ $isReceive ? 'The Store confirms it has this requisition.' : 'You are approving as '.$stage->displayLabel().'.' }}
        @if($sendOptions->isNotEmpty())
            <x-slot:fields>
                <label class="form-label fw-semibold" for="sendTo{{ $requisition->id }}">Send to (Store)</label>
                <select name="send_to" id="sendTo{{ $requisition->id }}" class="form-select mb-3" required>
                    <option value="">Choose a Store user…</option>
                    @foreach($sendOptions as $option)
                        <option value="{{ $option->id }}">{{ $option->name }}</option>
                    @endforeach
                </select>
            </x-slot:fields>
        @endif
    </x-action-modal>

    {{-- Back to the previous stage; from the first stage, to the raiser. --}}
    <button type="button" class="btn {{ $size }} btn-outline-danger" data-bs-toggle="modal" data-bs-target="#stageReturn{{ $requisition->id }}">
        <i class="bi bi-arrow-return-left me-1" aria-hidden="true"></i>Return
    </button>
    <x-action-modal :id="'stageReturn'.$requisition->id"
                    :action="route('store.stock.requisitions.stage-return', $requisition)"
                    :title="'Return '.$requisition->requisition_no"
                    :required="true"
                    placeholder="What should be changed before it comes back to you?"
                    submit-label="Return" submit-icon="bi-arrow-return-left" submit-class="btn-danger">
        It goes back to the previous stage, which can change it and send it on again.
    </x-action-modal>
@endif

{{-- The Department Head's step, before the Store's. Shown only to the
     configured head of this requisition's department (or Admin /
     Management), and only while the sheet waits on them. Returning it needs
     a reason, so the requester knows what to change — hence the modal. --}}
@if($headCanReturn)
    @unless($onEngine)
    <form method="POST" action="{{ route('store.stock.requisitions.department-approve', $requisition) }}" class="d-inline"
          onsubmit="return confirm('Check and send requisition {{ $requisition->requisition_no }} to Store?');">
        @csrf
        <button type="submit" class="btn {{ $size }} btn-outline-success">
            <i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Approve
        </button>
    </form>
    @endunless
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

{{-- The person who raised a sheet never receives or approves it; the
     controller refuses it too. They get a note instead of a button. --}}
@php($raisedByViewer = $requisition->isRaisedBy($user))

{{-- Received and Approve each confirm in a modal with an optional comment,
     which goes into the requisition's history. --}}
@if(! $onEngine && $requisition->awaitingStoreReceipt() && $user?->can('store.requisition.review') && ! $raisedByViewer)
    <button type="button" class="btn {{ $size }} btn-outline-primary" data-bs-toggle="modal" data-bs-target="#receiveRequisition{{ $requisition->id }}">
        <i class="bi bi-inbox-fill me-1" aria-hidden="true"></i>Received
    </button>
    <x-action-modal :id="'receiveRequisition'.$requisition->id"
                    :action="route('store.stock.requisitions.received', $requisition)"
                    :title="'Mark '.$requisition->requisition_no.' as received'"
                    submit-label="Mark Received" submit-icon="bi-inbox-fill">
        The Store confirms it has this requisition.
    </x-action-modal>
@endif

@if(! $onEngine && $requisition->awaitingApproval() && $user?->can('store.requisition.approve') && ! $raisedByViewer)
    <button type="button" class="btn {{ $size }} btn-outline-success" data-bs-toggle="modal" data-bs-target="#approveRequisition{{ $requisition->id }}">
        <i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Approve
    </button>
    <x-action-modal :id="'approveRequisition'.$requisition->id"
                    :action="route('store.stock.requisitions.approve', $requisition)"
                    :title="'Approve '.$requisition->requisition_no"
                    submit-label="Approve" submit-icon="bi-check2-circle" submit-class="btn-success">
        It then goes forward for procurement.
    </x-action-modal>
@endif

@if(! $onEngine && $raisedByViewer && (($requisition->awaitingStoreReceipt() && $user?->can('store.requisition.review')) || ($requisition->awaitingApproval() && $user?->can('store.requisition.approve'))))
    <span class="small text-muted"><i class="bi bi-person-lock me-1" aria-hidden="true"></i>You raised this requisition, so another person must {{ $requisition->awaitingApproval() ? 'approve' : 'receive' }} it.</span>
@endif
