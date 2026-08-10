{{--
    Permission matrix — module (rows) x action (columns).

    Two kinds of tick, and the difference is the whole point of the screen:

      * Comes with the role — ticked and disabled. It is shown so the admin can
        see the user already has it, and locked so it cannot be written here.
        Role permissions belong to the role; copying one onto the user would
        leave it behind when the role later changes, which is how somebody keeps
        access to a module they were moved out of.
      * Granted directly to this user — ticked and editable. This is the only
        thing the form writes.

    Nothing here enforces anything. It records who is allowed what, ready for
    enforcement to be fitted module by module in a later phase.

    Expects: $permissionGroups, $actionColumns, $catalog, $rolePermissions,
             $directPermissions, and $readonly (true on the profile page).
--}}
@php
    $readonly = $readonly ?? false;
    $selectedRole = $selectedRole ?? null;
    $roleGranted = collect($rolePermissions[$selectedRole] ?? []);
    $direct = collect(old('permissions', $directPermissions ?? []));
@endphp

<div class="gx-perm-matrix" data-role-permissions='@json($rolePermissions)'>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0 gx-perm-table">
            <thead>
                <tr>
                    <th style="min-width:190px;">Module</th>
                    @foreach($actionColumns as $action)
                        <th class="text-center">{{ $catalog->actionLabel($action) }}</th>
                    @endforeach
                    <th style="min-width:150px;">Other</th>
                </tr>
            </thead>
            <tbody>
                @foreach($permissionGroups as $group)
                    @foreach($group['rows'] as $i => $row)
                        <tr>
                            <td>
                                @if($i === 0)
                                    <span class="fw-semibold">{{ $group['label'] }}</span>
                                @endif
                                @if($row['section'])
                                    <div class="small text-muted">{{ $i === 0 ? '' : '' }}{{ $row['section'] }}</div>
                                @endif
                            </td>

                            @foreach($actionColumns as $action)
                                <td class="text-center">
                                    @php($entry = $row['actions'][$action] ?? null)
                                    @if($entry)
                                        @include('admin.users._permission-check', [
                                            'entry' => $entry,
                                            'roleGranted' => $roleGranted,
                                            'direct' => $direct,
                                            'readonly' => $readonly,
                                            'showLabel' => false,
                                        ])
                                    @else
                                        <span class="text-body-tertiary">—</span>
                                    @endif
                                </td>
                            @endforeach

                            <td>
                                @forelse($row['extra'] as $entry)
                                    @include('admin.users._permission-check', [
                                        'entry' => $entry,
                                        'roleGranted' => $roleGranted,
                                        'direct' => $direct,
                                        'readonly' => $readonly,
                                        'showLabel' => true,
                                    ])
                                @empty
                                    <span class="text-body-tertiary">—</span>
                                @endforelse
                            </td>
                        </tr>
                    @endforeach
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="d-flex flex-wrap gap-3 mt-3 small text-muted">
        <span><span class="gx-perm-key gx-perm-key-role"></span>Comes with the role — cannot be changed here</span>
        <span><span class="gx-perm-key gx-perm-key-direct"></span>Granted to this user only</span>
    </div>
</div>

<style>
    .gx-perm-table th {
        font-size: .7rem;
        text-transform: uppercase;
        letter-spacing: .03em;
        color: #64748b;
        vertical-align: bottom;
    }
    .gx-perm-table td { font-size: .82rem; }
    .gx-perm-table tbody tr:hover { background: #f8fafc; }

    /* A locked tick has to read as "already has it", not as "disabled and
       therefore off" — hence the fill rather than the usual greyed box. */
    .gx-perm-table .form-check-input:disabled:checked {
        background-color: #94a3b8;
        border-color: #94a3b8;
        opacity: 1;
    }

    .gx-perm-key {
        display: inline-block;
        width: 12px;
        height: 12px;
        border-radius: 3px;
        margin-right: 6px;
        vertical-align: -1px;
    }
    .gx-perm-key-role { background: #94a3b8; }
    .gx-perm-key-direct { background: var(--bs-primary, #2563eb); }
</style>

@unless($readonly)
<script>
    /**
     * Keep the matrix honest when the role dropdown changes.
     *
     * The locked ticks describe the role that is selected right now. Change the
     * role without this and the screen keeps showing the old role's grants,
     * which is worse than showing nothing: the admin ticks a box believing the
     * new role does not cover it, or leaves one unticked believing it does.
     *
     * Only the locked state is recomputed. A box the admin ticked themselves
     * stays ticked, unless the newly chosen role now covers it — in which case
     * it becomes a role grant and is locked, and the controller drops it from
     * the direct set anyway.
     */
    (function () {
        var matrix = document.currentScript.closest('.gx-perm-matrix')
            || document.querySelector('.gx-perm-matrix');

        if (! matrix) { return; }

        var roleSelect = document.querySelector('select[name="role"]');

        if (! roleSelect) { return; }

        var byRole = JSON.parse(matrix.dataset.rolePermissions || '{}');

        // What the admin has ticked by hand, so a role change does not discard it.
        var chosen = new Set();
        matrix.querySelectorAll('.js-perm-box').forEach(function (box) {
            if (box.checked && ! box.disabled) { chosen.add(box.dataset.permission); }
        });

        matrix.addEventListener('change', function (e) {
            var box = e.target.closest('.js-perm-box');
            if (! box || box.disabled) { return; }
            box.checked ? chosen.add(box.dataset.permission) : chosen.delete(box.dataset.permission);
        });

        function apply() {
            var granted = new Set(byRole[roleSelect.value] || []);

            matrix.querySelectorAll('.js-perm-box').forEach(function (box) {
                var name = box.dataset.permission;
                var fromRole = granted.has(name);

                box.disabled = fromRole;
                box.checked = fromRole || chosen.has(name);
            });
        }

        roleSelect.addEventListener('change', apply);
        apply();
    })();
</script>
@endunless
