{{--
    Checkbox, radio or switch — the three share Bootstrap's markup and differ
    only by type and one class, so they are one component.

    Props:
      as   checkbox | radio | switch
--}}
@props([
    'name',
    'label' => null,
    'as' => 'checkbox',
    'value' => 1,
    'checked' => false,
    'help' => null,
])

@php
    $errors = $errors ?? new \Illuminate\Support\ViewErrorBag;
    $id = $attributes->get('id') ?: 'f_'.\Illuminate\Support\Str::slug($name.'_'.$value, '_');
    $type = $as === 'radio' ? 'radio' : 'checkbox';
    $hasError = $errors->has($name);
    $isChecked = old($name, $checked ? $value : null) == $value;
@endphp

<div class="form-check {{ $as === 'switch' ? 'form-switch' : '' }}">
    <input type="{{ $type }}" name="{{ $name }}" id="{{ $id }}" value="{{ $value }}"
           @checked($isChecked)
           @if($help) aria-describedby="{{ $id }}_help" @endif
           {{ $attributes->merge(['class' => 'form-check-input'.($hasError ? ' is-invalid' : '')]) }}>

    @if($label)
        <label class="form-check-label" for="{{ $id }}">{{ $label }}</label>
    @endif

    @if($help)<div class="form-text" id="{{ $id }}_help">{{ $help }}</div>@endif
    @if($hasError)<div class="invalid-feedback d-block">{{ $errors->first($name) }}</div>@endif
</div>
