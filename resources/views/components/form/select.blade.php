{{--
    Select with label, help and error, matching x-form.field.

    Props:
      options   ['value' => 'Label'] or a plain list
      selected  currently selected value (old() wins)
      blank     placeholder option text; omit for no placeholder
--}}
@props([
    'name',
    'label' => null,
    'options' => [],
    'selected' => null,
    'blank' => null,
    'help' => null,
    'required' => false,
])

@php
    $errors = $errors ?? new \Illuminate\Support\ViewErrorBag;
    $id = $attributes->get('id') ?: 'f_'.\Illuminate\Support\Str::slug($name, '_');
    $hasError = $errors->has($name);
    $current = old($name, $selected);
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

    <select name="{{ $name }}" id="{{ $id }}"
            @if($required) required @endif
            @if($describedBy) aria-describedby="{{ $describedBy }}" @endif
            @if($hasError) aria-invalid="true" @endif
            {{ $attributes->merge(['class' => 'form-select'.($hasError ? ' is-invalid' : '')]) }}>
        @if($blank !== null)
            <option value="">{{ $blank }}</option>
        @endif
        @foreach($options as $optionValue => $optionLabel)
            @php $val = is_int($optionValue) ? $optionLabel : $optionValue; @endphp
            <option value="{{ $val }}" @selected((string) $current === (string) $val)>{{ $optionLabel }}</option>
        @endforeach
    </select>

    @if($help)<div class="form-text" id="{{ $id }}_help">{{ $help }}</div>@endif
    @if($hasError)<div class="invalid-feedback d-block" id="{{ $id }}_error">{{ $errors->first($name) }}</div>@endif
</div>
