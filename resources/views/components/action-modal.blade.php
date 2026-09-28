{{--
    A confirm-with-comment modal for a workflow action (Received, Approve,
    Delete; later Return and Cancel). One form, one comment box: optional by
    default, required when the action needs a reason. The server validates it
    as well; `required` here only saves a round trip.

    Opened by any button with data-bs-toggle="modal" data-bs-target="#{id}".

    <x-action-modal id="approve12" :action="route(...)" title="Approve REQ-1"
                    submit-label="Approve" submit-icon="bi-check2-circle">
        It then goes forward for procurement.
    </x-action-modal>
--}}
@props([
    'id',
    'action',
    'title',
    'method' => 'POST',
    'submitLabel' => 'Confirm',
    'submitIcon' => null,
    'submitClass' => 'btn-primary',
    'field' => 'comment',
    'fieldLabel' => null,
    'required' => false,
    'placeholder' => null,
])

@php($fieldId = $id.'Comment')

<div class="modal fade text-start" id="{{ $id }}" tabindex="-1" aria-labelledby="{{ $id }}Label" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="{{ $action }}" class="modal-content">
            @csrf
            @if(strtoupper($method) !== 'POST')
                @method($method)
            @endif
            <div class="modal-header">
                <h5 class="modal-title" id="{{ $id }}Label">{{ $title }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                @if(trim($slot) !== '')
                    <p class="mb-3">{{ $slot }}</p>
                @endif
                <label class="form-label fw-semibold" for="{{ $fieldId }}">
                    {{ $fieldLabel ?? ($required ? 'Reason' : 'Comment') }}
                    @unless($required)<span class="text-muted fw-normal">(optional)</span>@endunless
                </label>
                <textarea name="{{ $field }}" id="{{ $fieldId }}" class="form-control" rows="3" maxlength="1000"
                          @if($required) required @endif
                          @if($placeholder) placeholder="{{ $placeholder }}" @endif></textarea>
                <div class="form-text">Saved in the requisition history.</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                <button type="submit" class="btn {{ $submitClass }}">
                    @if($submitIcon)<i class="bi {{ $submitIcon }} me-1" aria-hidden="true"></i>@endif{{ $submitLabel }}
                </button>
            </div>
        </form>
    </div>
</div>
