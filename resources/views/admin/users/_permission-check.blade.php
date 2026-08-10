{{--
    One permission, drawn as a state chip.

    A real checkbox is still what posts — it is visually hidden inside the
    label, so the form, keyboard and screen readers behave exactly as they did
    when this was a bare checkbox. Only the paint changed.

    Three states, and telling them apart at a glance is the whole job:

      inherited  solid slate, ticked, locked   — comes with the role
      granted    solid blue, ticked            — given to this user here
      open       outlined, empty               — available, not granted

    $showLabel is on for chips that stand alone (a module with no sub-sections,
    or a one-off switch like approve-pra) and off inside a column grid, where
    the column heading already names the action.
--}}
@php
    $fromRole = $roleGranted->contains($entry['name']);
    $checked = $fromRole || $direct->contains($entry['name']);
    $id = 'perm_'.$entry['id'];

    $state = $fromRole ? 'inherited' : ($checked ? 'granted' : 'open');
@endphp

<label class="gx-perm-chip is-{{ $state }} {{ $showLabel ? '' : 'is-compact' }} {{ ($readonly || $fromRole) ? 'is-locked' : '' }}"
       for="{{ $id }}"
       title="{{ $entry['label'] }}{{ $fromRole ? ' — comes with the role' : '' }}">

    <input type="checkbox"
           class="gx-perm-input js-perm-box"
           id="{{ $id }}"
           name="permissions[]"
           value="{{ $entry['name'] }}"
           data-permission="{{ $entry['name'] }}"
           @checked($checked)
           @disabled($readonly || $fromRole)>

    <span class="gx-perm-mark" aria-hidden="true">
        <i class="bi {{ $fromRole ? 'bi-lock-fill' : 'bi-check-lg' }}"></i>
    </span>

    <span class="gx-perm-text {{ $showLabel ? '' : 'visually-hidden' }}">
        {{ $entry['label'] }}{{ $fromRole ? ' (from role)' : '' }}
    </span>
</label>
