{{--
    One checkbox in the permission matrix.

    Locked-and-ticked when the role already grants it; editable otherwise. The
    name is the permission's own name, so the controller can validate the post
    against the permissions table without a lookup table in between.
--}}
@php
    $fromRole = $roleGranted->contains($entry['name']);
    $checked = $fromRole || $direct->contains($entry['name']);
    $id = 'perm_'.$entry['id'];
@endphp

<div class="form-check {{ $showLabel ? '' : 'd-inline-block m-0' }}">
    <input type="checkbox"
           class="form-check-input js-perm-box"
           id="{{ $id }}"
           name="permissions[]"
           value="{{ $entry['name'] }}"
           data-permission="{{ $entry['name'] }}"
           @checked($checked)
           @disabled($readonly || $fromRole)>

    <label class="form-check-label {{ $showLabel ? 'small' : 'visually-hidden' }}" for="{{ $id }}">
        {{ $entry['label'] }}
        @if($fromRole)
            <span class="visually-hidden">(granted by role)</span>
        @endif
    </label>
</div>
