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

    // A department admin can only pass on what they hold. $grantable is null
    // for a super admin, which means no limit — not an empty list.
    $grantable = $grantable ?? null;
    $beyondReach = $grantable !== null && ! in_array($entry['name'], $grantable, true);

    // Shown rather than hidden, and locked rather than dropped. Hiding it
    // would tell a department admin the permission does not exist; dropping
    // the input would post the user's existing grant away. The controller
    // preserves it either way, but the screen should not imply otherwise.
    $locked = $readonly || $fromRole || $beyondReach;

    // A section's Edit / Delete (store.receiving.delete) is ALSO granted by the
    // older whole-module permission (store.delete): the server checks either.
    // So an empty section box does not mean "no" while the Whole module box is
    // on — the user can still do it. Say so on the chip instead of letting an
    // empty box read as a refusal. To really withhold it, untick the Whole
    // module box and tick the sections that should keep it.
    $coveredByModule = false;
    $parts = explode('.', $entry['name']);
    if (! $checked && count($parts) === 3 && in_array($parts[2], ['edit', 'delete'], true)) {
        $flat = $parts[0].'.'.$parts[2];
        $coveredByModule = $roleGranted->contains($flat) || $direct->contains($flat);
    }
@endphp

<label class="gx-perm-chip is-{{ $state }} {{ $coveredByModule ? 'is-covered' : '' }} {{ $showLabel ? '' : 'is-compact' }} {{ $locked ? 'is-locked' : '' }}"
       for="{{ $id }}"
       title="{{ $entry['label'] }}@if($fromRole) — comes with the role @elseif($beyondReach) — you do not hold this permission yourself @elseif($coveredByModule) — still allowed: the Whole module {{ strtolower($entry['label']) }} box is on. Untick it to restrict this section. @endif">

    <input type="checkbox"
           class="gx-perm-input js-perm-box"
           id="{{ $id }}"
           name="permissions[]"
           value="{{ $entry['name'] }}"
           data-permission="{{ $entry['name'] }}"
           @if($beyondReach) data-beyond-reach="1" @endif
           @checked($checked)
           @disabled($locked)>

    <span class="gx-perm-mark" aria-hidden="true">
        <i class="bi {{ ($fromRole || $beyondReach) ? 'bi-lock-fill' : 'bi-check-lg' }}"></i>
    </span>

    <span class="gx-perm-text {{ $showLabel ? '' : 'visually-hidden' }}">
        {{ $entry['label'] }}@if($fromRole) (from role)@elseif($beyondReach) (not yours to grant)@elseif($coveredByModule) (via Whole module)@endif
    </span>
</label>
