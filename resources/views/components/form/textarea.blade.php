{{-- Textarea with label, help and error, matching x-form.field. --}}
@props([
    'name',
    'label' => null,
    'value' => null,
    'rows' => 3,
    'help' => null,
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

    <textarea name="{{ $name }}" id="{{ $id }}" rows="{{ $rows }}"
              @if($required) required @endif
              @if($describedBy) aria-describedby="{{ $describedBy }}" @endif
              @if($hasError) aria-invalid="true" @endif
              {{ $attributes->merge(['class' => 'form-control'.($hasError ? ' is-invalid' : '')]) }}>{{ old($name, $value) }}</textarea>

    @if($help)<div class="form-text" id="{{ $id }}_help">{{ $help }}</div>@endif
    @if($hasError)<div class="invalid-feedback d-block" id="{{ $id }}_error">{{ $errors->first($name) }}</div>@endif
</div>
