{{--
    Flash messages and validation errors.

    Replaces store/_flash, which only ten views included while eighteen others
    hand-rolled the same markup. Handles every session key the app actually
    sets: success, warning, error, and Laravel's own "status".

    Usage:  x-flash            (inline, at the top of the page content)
            x-flash dismissible="false"
--}}
@props(['dismissible' => true])

@php
    // Shared by middleware on web requests; defaulted so the component still
    // renders anywhere that middleware has not run.
    $errors = $errors ?? new \Illuminate\Support\ViewErrorBag;

    $messages = [
        'success' => ['tone' => 'success', 'icon' => 'check-circle-fill', 'label' => 'Success'],
        'status'  => ['tone' => 'success', 'icon' => 'check-circle-fill', 'label' => 'Success'],
        'warning' => ['tone' => 'warning', 'icon' => 'exclamation-triangle-fill', 'label' => 'Warning'],
        'error'   => ['tone' => 'danger',  'icon' => 'x-circle-fill', 'label' => 'Error'],
        'info'    => ['tone' => 'info',    'icon' => 'info-circle-fill', 'label' => 'Note'],
    ];
@endphp

@foreach($messages as $key => $meta)
    @if(session($key))
        {{-- role=alert so a screen reader announces it without needing focus. --}}
        <div class="alert alert-{{ $meta['tone'] }} gx-flash d-flex align-items-start gap-2 {{ $dismissible ? 'alert-dismissible' : '' }}"
             role="alert">
            <i class="bi bi-{{ $meta['icon'] }} gx-flash-icon" aria-hidden="true"></i>
            <div class="flex-grow-1">
                <span class="visually-hidden">{{ $meta['label'] }}:</span>
                {{ session($key) }}
            </div>
            @if($dismissible)
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Dismiss this message"></button>
            @endif
        </div>
    @endif
@endforeach

@if($errors->any())
    <div class="alert alert-danger gx-flash d-flex align-items-start gap-2" role="alert">
        <i class="bi bi-x-circle-fill gx-flash-icon" aria-hidden="true"></i>
        <div class="flex-grow-1">
            <div class="fw-semibold mb-1">
                {{ $errors->count() === 1 ? 'Please correct this:' : 'Please correct the following:' }}
            </div>
            <ul class="mb-0 ps-3">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    </div>
@endif
