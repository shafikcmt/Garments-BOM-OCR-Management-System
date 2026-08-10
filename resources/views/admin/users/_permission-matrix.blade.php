{{--
    Permission matrix — module (collapsible) > sub-section > action.

    Sub-sections mirror the sidebar, so the row an admin is looking for sits
    where the menu taught them to expect it. "Module-wide" is the first row of a
    module and holds its older flat permissions (store.view and the rest), which
    are still what the roles are built from — shown rather than hidden, because
    an admin working out why somebody has access needs to see them.

    Two kinds of tick, and the difference is the whole point of the screen:

      * Comes with the role — ticked and disabled. Shown so the admin can see
        the user already has it, and locked so it cannot be written here. Role
        permissions belong to the role; copying one onto the user would leave it
        behind when the role later changes, which is how somebody keeps access
        to a module they were moved out of.
      * Granted directly to this user — ticked and editable. The only thing the
        form writes.

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

    {{-- Filter bar. Plain text matching on the module and sub-section names —
         83 permissions is more than anyone should have to read top to bottom. --}}
    <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
        <div class="position-relative flex-grow-1" style="max-width:320px;">
            <i class="bi bi-search position-absolute text-slate-400" style="left:12px;top:50%;transform:translateY(-50%);" aria-hidden="true"></i>
            <input type="search"
                   class="form-control form-control-sm js-perm-search"
                   style="padding-left:34px;"
                   placeholder="Search module or section…"
                   aria-label="Search permissions">
        </div>

        <div class="btn-group btn-group-sm" role="group" aria-label="Permission filters">
            <button type="button" class="btn btn-outline-secondary js-perm-chip is-active" data-filter="all">
                <i class="bi bi-list-ul me-1" aria-hidden="true"></i>All
            </button>
            <button type="button" class="btn btn-outline-secondary js-perm-chip" data-filter="stock">
                <i class="bi bi-box-seam me-1" aria-hidden="true"></i>Stock only
            </button>
            <button type="button" class="btn btn-outline-secondary js-perm-chip" data-filter="direct">
                <i class="bi bi-person-check me-1" aria-hidden="true"></i>Granted only
            </button>
            <button type="button" class="btn btn-outline-secondary js-perm-chip" data-filter="role">
                <i class="bi bi-people me-1" aria-hidden="true"></i>From role
            </button>
        </div>

        <button type="button" class="btn btn-sm btn-outline-secondary js-perm-toggle-all" data-expanded="1">
            <i class="bi bi-chevron-expand me-1" aria-hidden="true"></i>Collapse all
        </button>

        <span class="small text-muted ms-auto js-perm-count"></span>
    </div>

    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0 gx-perm-table">
            <thead>
                <tr>
                    <th style="min-width:210px;">Module / Section</th>
                    @foreach($actionColumns as $action)
                        <th class="text-center">{{ $catalog->actionLabel($action) }}</th>
                    @endforeach
                    <th style="min-width:150px;">Other</th>
                </tr>
            </thead>
            <tbody>
                @foreach($permissionGroups as $group)
                    {{-- Module header. Clicking it folds the module away. --}}
                    <tr class="gx-perm-module js-perm-module"
                        data-module="{{ $group['key'] }}"
                        data-text="{{ Str::lower($group['label']) }}">
                        <td colspan="{{ count($actionColumns) + 2 }}">
                            <button type="button" class="btn btn-sm btn-link p-0 text-decoration-none fw-semibold js-perm-fold" aria-expanded="true">
                                <i class="bi bi-chevron-down me-1 js-perm-caret" aria-hidden="true"></i>{{ $group['label'] }}
                            </button>
                            <span class="small text-muted ms-2">{{ count($group['rows']) }} section(s)</span>
                        </td>
                    </tr>

                    @foreach($group['rows'] as $row)
                        <tr class="gx-perm-row js-perm-row"
                            data-module="{{ $group['key'] }}"
                            data-text="{{ Str::lower($group['label'].' '.$row['section']) }}">
                            <td class="ps-4">
                                <span class="{{ $row['key'] === null ? 'text-muted fst-italic' : '' }}">{{ $row['section'] }}</span>
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

    <div class="gx-perm-empty d-none text-center text-muted small py-4">
        Nothing matches this search.
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
    .gx-perm-row:hover { background: #f8fafc; }

    /* The module header is a band, so the eye can find where one module ends
       and the next begins without counting indents. */
    .gx-perm-module td {
        background: #eef2ff;
        border-top: 1px solid #c7d2fe;
        padding-top: .45rem;
        padding-bottom: .45rem;
    }
    .gx-perm-module .js-perm-fold { color: #1e293b; }
    .gx-perm-module.is-collapsed .js-perm-caret { transform: rotate(-90deg); }
    .js-perm-caret { display: inline-block; transition: transform .12s ease; }

    /* A locked tick has to read as "already has it", not as "disabled and
       therefore off" — hence the fill rather than the usual greyed box. */
    .gx-perm-table .form-check-input:disabled:checked {
        background-color: #94a3b8;
        border-color: #94a3b8;
        opacity: 1;
    }

    .js-perm-chip.is-active {
        background: var(--bs-primary, #2563eb);
        border-color: var(--bs-primary, #2563eb);
        color: #fff;
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

<script>
    (function () {
        var matrix = document.currentScript.previousElementSibling;
        while (matrix && ! matrix.classList.contains('gx-perm-matrix')) {
            matrix = matrix.previousElementSibling;
        }
        if (! matrix) { matrix = document.querySelector('.gx-perm-matrix'); }
        if (! matrix) { return; }

        var readonly = @json($readonly);
        var rows = Array.prototype.slice.call(matrix.querySelectorAll('.js-perm-row'));
        var modules = Array.prototype.slice.call(matrix.querySelectorAll('.js-perm-module'));
        var search = matrix.querySelector('.js-perm-search');
        var chips = Array.prototype.slice.call(matrix.querySelectorAll('.js-perm-chip'));
        var counter = matrix.querySelector('.js-perm-count');
        var empty = matrix.querySelector('.gx-perm-empty');
        var toggleAll = matrix.querySelector('.js-perm-toggle-all');

        var filter = 'all';
        var collapsed = {};

        function boxesIn(row) {
            return Array.prototype.slice.call(row.querySelectorAll('.js-perm-box'));
        }

        /**
         * Whether a row survives the current chip. Read off the live checkbox
         * state rather than anything rendered server-side, so it stays true
         * after the role dropdown changes what is locked.
         */
        function passesChip(row) {
            if (filter === 'all') { return true; }
            if (filter === 'stock') {
                var m = row.dataset.module;
                return m === 'store' || m === 'material';
            }

            return boxesIn(row).some(function (box) {
                return filter === 'role'
                    ? (box.disabled && box.checked)
                    : (! box.disabled && box.checked);
            });
        }

        function apply() {
            var term = (search.value || '').trim().toLowerCase();
            var visible = 0;

            rows.forEach(function (row) {
                var hit = (! term || row.dataset.text.indexOf(term) !== -1) && passesChip(row);
                row.dataset.match = hit ? '1' : '0';
                // A row in a folded module stays in the DOM but out of sight.
                row.classList.toggle('d-none', ! hit || collapsed[row.dataset.module]);
                if (hit) { visible++; }
            });

            // A module header goes when nothing under it survived — otherwise
            // the screen fills with headings for empty modules.
            modules.forEach(function (head) {
                var any = rows.some(function (r) {
                    return r.dataset.module === head.dataset.module && r.dataset.match === '1';
                });
                head.classList.toggle('d-none', ! any);
            });

            counter.textContent = visible + ' of ' + rows.length + ' sections';
            empty.classList.toggle('d-none', visible > 0);
        }

        search.addEventListener('input', apply);

        chips.forEach(function (chip) {
            chip.addEventListener('click', function () {
                chips.forEach(function (c) { c.classList.remove('is-active'); });
                chip.classList.add('is-active');
                filter = chip.dataset.filter;
                apply();
            });
        });

        modules.forEach(function (head) {
            head.querySelector('.js-perm-fold').addEventListener('click', function (e) {
                var key = head.dataset.module;
                collapsed[key] = ! collapsed[key];
                head.classList.toggle('is-collapsed', collapsed[key]);
                e.currentTarget.setAttribute('aria-expanded', collapsed[key] ? 'false' : 'true');
                apply();
            });
        });

        toggleAll.addEventListener('click', function () {
            var expand = toggleAll.dataset.expanded !== '1';

            modules.forEach(function (head) {
                collapsed[head.dataset.module] = ! expand;
                head.classList.toggle('is-collapsed', ! expand);
                head.querySelector('.js-perm-fold').setAttribute('aria-expanded', expand ? 'true' : 'false');
            });

            toggleAll.dataset.expanded = expand ? '1' : '0';
            toggleAll.innerHTML = expand
                ? '<i class="bi bi-chevron-expand me-1" aria-hidden="true"></i>Collapse all'
                : '<i class="bi bi-chevron-contract me-1" aria-hidden="true"></i>Expand all';
            apply();
        });

        if (! readonly) {
            /**
             * Keep the matrix honest when the role dropdown changes.
             *
             * The locked ticks describe the role selected right now. Change the
             * role without this and the screen keeps showing the old role's
             * grants, which is worse than showing nothing: the admin ticks a box
             * believing the new role does not cover it, or leaves one unticked
             * believing it does.
             *
             * Only the locked state is recomputed. A box the admin ticked
             * themselves stays ticked, unless the newly chosen role now covers
             * it — in which case it becomes a role grant and is locked, and the
             * controller drops it from the direct set anyway.
             */
            var roleSelect = document.querySelector('select[name="role"]');

            if (roleSelect) {
                var byRole = JSON.parse(matrix.dataset.rolePermissions || '{}');
                var chosen = new Set();

                matrix.querySelectorAll('.js-perm-box').forEach(function (box) {
                    if (box.checked && ! box.disabled) { chosen.add(box.dataset.permission); }
                });

                matrix.addEventListener('change', function (e) {
                    var box = e.target.closest('.js-perm-box');
                    if (! box || box.disabled) { return; }
                    box.checked ? chosen.add(box.dataset.permission) : chosen.delete(box.dataset.permission);
                    apply();
                });

                var applyRole = function () {
                    var granted = new Set(byRole[roleSelect.value] || []);

                    matrix.querySelectorAll('.js-perm-box').forEach(function (box) {
                        var fromRole = granted.has(box.dataset.permission);
                        box.disabled = fromRole;
                        box.checked = fromRole || chosen.has(box.dataset.permission);
                    });

                    apply();
                };

                roleSelect.addEventListener('change', applyRole);
                applyRole();
            }
        }

        apply();
    })();
</script>
