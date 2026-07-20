{{--
    Text-style input with its label, help text and error in one place.

    Errors are read from the validation bag by field name, so a form does not
    have to repeat @error blocks — the commonest thing to forget, which leaves
    the user with a form that rejects them and does not say why.

    Props:
      name       field name; also drives the error lookup and the label's "for"
      label      visible label
      type       text | number | date | email | password | tel …
      help       supporting text under the field
      icon       bootstrap-icon name shown on the left
      suffix     short text on the right, e.g. a unit
      required   marks the label and sets the attribute

    Usage:  x-form.field name="qty" label="Physical Rcv Qty" type="number" required
--}}
@props([
    'name',
    'label' => null,
    'type' => 'text',
    'value' => null,
    'help' => null,
    'icon' => null,
    'suffix' => null,
    'required' => false,
])

@php
    $errors = $errors ?? new \Illuminate\Support\ViewErrorBag;
    $id = $attributes->get('id') ?: 'f_'.\Illuminate\Support\Str::slug($name, '_');
    $hasError = $errors->has($name);
    $describedBy = collect([
        $help ? $id.'_help' : null,
        $hasError ? $id.'_error' : null,
    ])->filter()->implode(' ');
@endphp

<div class="gx-field">
    @if($label)
        <label class="form-label fw-semibold" for="{{ $id }}">
            {{ $label }}
            @if($required)<span class="text-danger" aria-hidden="true">*</span><span class="visually-hidden">(required)</span>@endif
        </label>
    @endif

    <div class="{{ $icon || $suffix ? 'input-group' : '' }}">
        @if($icon)
            <span class="input-group-text"><i class="bi bi-{{ $icon }}" aria-hidden="true"></i></span>
        @endif

        <input
            type="{{ $type }}"
            name="{{ $name }}"
            id="{{ $id }}"
            value="{{ old($name, $value) }}"
            @if($required) required @endif
            @if($describedBy) aria-describedby="{{ $describedBy }}" @endif
            @if($hasError) aria-invalid="true" @endif
            {{ $attributes->merge(['class' => 'form-control'.($hasError ? ' is-invalid' : '')]) }}>

        @if($suffix)
            <span class="input-group-text">{{ $suffix }}</span>
        @endif
    </div>

    @if($help)
        <div class="form-text" id="{{ $id }}_help">{{ $help }}</div>
    @endif

    @if($hasError)
        <div class="invalid-feedback d-block" id="{{ $id }}_error">{{ $errors->first($name) }}</div>
    @endif
</div>
