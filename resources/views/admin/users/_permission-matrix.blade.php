{{--
    Permission matrix — one card per module.

    It was one wide grid with every action as a column. Action use is very
    uneven across modules, though: General Stock uses eleven, most modules use
    four, so eight columns out of twelve were dashes on most rows and the grid
    only fitted by scrolling sideways. Each module now carries its own column
    set, which is what makes it fit a laptop without scrolling.

    A module with no sub-sections is a card and nothing else — its name is the
    row, and its actions sit under the heading as labelled chips. Only General
    Stock and Buyer / Style Stock have real sub-sections, and only they get
    section rows, with the module name in a header that stays put whether the
    card is open or shut.

    Two kinds of tick, and the difference is the point of the screen:

      * Comes with the role — slate, locked. Shown so the admin can see the
        user already has it, locked so it cannot be written here. Role
        permissions belong to the role; copying one onto the user would leave it
        behind when the role later changes, which is how somebody keeps access
        to a module they were moved out of.
      * Granted to this user — blue, editable. The only thing the form writes.

    Nothing here enforces anything. It records who is allowed what.

    $permissionGroups arrives already narrowed on the grant forms: a department
    admin is sent only the modules they hold themselves, so a module they have
    no rights in is not on the page at all. The read-only profile still gets the
    whole catalog, because that screen reports access rather than offering it.
    The locked "not yours to grant" state below therefore only shows up there —
    it stays as the backstop it always was.

    Expects: $permissionGroups, $catalog, $rolePermissions, $directPermissions,
             $readonly (true on the profile page). $actionColumns is accepted
             for compatibility but each card uses its own $group['columns'].
--}}
@php
    $readonly = $readonly ?? false;
    // Permission names the person editing may hand out. Null means no limit —
    // a super admin, and the profile page, which passes nothing.
    $grantable = $grantablePermissions ?? null;
    $selectedRole = $selectedRole ?? null;
    $roleGranted = collect($rolePermissions[$selectedRole] ?? []);
    $direct = collect(old('permissions', $directPermissions ?? []));
@endphp

@if($permissionGroups->isEmpty())
    {{-- A department admin whose own account carries no permissions has nothing
         to hand out. Say so plainly rather than drawing an empty grid with a
         search box over it. --}}
    <p class="gx-perm-none">You have no permissions of your own to grant. Ask a system administrator to extend your access first.</p>
    <style>
        .gx-perm-none {
            margin: 0;
            padding: 1.25rem;
            border: 1px dashed var(--gx-surface-border, #e2e8f0);
            border-radius: var(--gx-radius-sm, 10px);
            background: #f8fafc;
            font-size: .85rem;
            color: var(--gx-text-muted, #64748B);
        }
    </style>
@else

<div class="gx-perm" data-role-permissions='@json($rolePermissions)'>

    <div class="gx-perm-toolbar">
        <div class="gx-perm-search">
            <i class="bi bi-search" aria-hidden="true"></i>
            <input type="search"
                   class="form-control form-control-sm js-perm-search"
                   placeholder="Search module or section…"
                   aria-label="Search permissions">
        </div>

        <div class="btn-group btn-group-sm" role="group" aria-label="Permission filters">
            <button type="button" class="btn btn-outline-secondary js-perm-chip is-active" data-filter="all">All</button>
            <button type="button" class="btn btn-outline-secondary js-perm-chip" data-filter="stock">Stock only</button>
            <button type="button" class="btn btn-outline-secondary js-perm-chip" data-filter="direct">Granted only</button>
            <button type="button" class="btn btn-outline-secondary js-perm-chip" data-filter="role">From role</button>
        </div>

        <button type="button" class="btn btn-sm btn-outline-secondary js-perm-toggle-all" data-expanded="1">
            <i class="bi bi-chevron-contract me-1" aria-hidden="true"></i>Collapse all
        </button>

        <span class="gx-perm-count js-perm-count"></span>
    </div>

    <div class="gx-perm-grid">
        @foreach($permissionGroups as $group)
            <section class="gx-perm-card js-perm-card {{ $group['sectioned'] ? 'is-wide' : '' }}"
                     data-module="{{ $group['key'] }}"
                     data-text="{{ Str::lower($group['label'].' '.collect($group['rows'])->pluck('section')->implode(' ')) }}">

                {{-- The module name lives here and never moves. Collapsing the
                     card hides the body, not the identity. --}}
                <header class="gx-perm-head">
                    <button type="button" class="gx-perm-fold js-perm-fold" aria-expanded="true">
                        <i class="bi bi-chevron-down gx-perm-caret" aria-hidden="true"></i>
                        <span class="gx-perm-module">{{ $group['label'] }}</span>
                    </button>

                    {{-- Coverage: how much of this module the user reaches, and
                         where it came from. The slate part is the role, the blue
                         part was granted here. --}}
                    <span class="gx-perm-meter" aria-hidden="true">
                        <span class="gx-perm-meter-bar"><span class="gx-perm-meter-role"></span><span class="gx-perm-meter-direct"></span></span>
                        <span class="gx-perm-meter-text js-perm-meter">0/0</span>
                    </span>
                </header>

                <div class="gx-perm-body js-perm-body">
                    @if($group['sectioned'])
                        <table class="gx-perm-table">
                            <thead>
                                <tr>
                                    <th class="gx-perm-th-section">Section</th>
                                    @foreach($group['columns'] as $action)
                                        <th>{{ $catalog->actionLabel($action) }}</th>
                                    @endforeach
                                    @if(collect($group['rows'])->contains(fn ($r) => ! empty($r['extra'])))
                                        <th class="gx-perm-th-other">Other</th>
                                    @endif
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($group['rows'] as $row)
                                    <tr class="js-perm-row" data-text="{{ Str::lower($group['label'].' '.$row['section']) }}">
                                        <th scope="row" class="{{ $row['key'] === null ? 'is-module-wide' : '' }}">{{ $row['section'] }}</th>

                                        @foreach($group['columns'] as $action)
                                            <td>
                                                @php($entry = $row['actions'][$action] ?? null)
                                                @if($entry)
                                                    @include('admin.users._permission-check', [
                                                        'entry' => $entry, 'roleGranted' => $roleGranted,
                                                        'direct' => $direct, 'readonly' => $readonly, 'grantable' => $grantable, 'showLabel' => false,
                                                    ])
                                                @else
                                                    <span class="gx-perm-na" aria-label="not applicable">·</span>
                                                @endif
                                            </td>
                                        @endforeach

                                        @if(collect($group['rows'])->contains(fn ($r) => ! empty($r['extra'])))
                                            <td class="gx-perm-other">
                                                @foreach($row['extra'] as $entry)
                                                    @include('admin.users._permission-check', [
                                                        'entry' => $entry, 'roleGranted' => $roleGranted,
                                                        'direct' => $direct, 'readonly' => $readonly, 'grantable' => $grantable, 'showLabel' => true,
                                                    ])
                                                @endforeach
                                            </td>
                                        @endif
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @else
                        {{-- No sub-sections: the card heading is the row, so the
                             actions go straight underneath as named chips. --}}
                        @php($row = $group['rows'][0] ?? ['actions' => [], 'extra' => []])
                        <div class="gx-perm-flat js-perm-row" data-text="{{ Str::lower($group['label']) }}">
                            @foreach($group['columns'] as $action)
                                @php($entry = $row['actions'][$action] ?? null)
                                @if($entry)
                                    @include('admin.users._permission-check', [
                                        'entry' => $entry, 'roleGranted' => $roleGranted,
                                        'direct' => $direct, 'readonly' => $readonly, 'grantable' => $grantable, 'showLabel' => true,
                                    ])
                                @endif
                            @endforeach

                            @foreach($row['extra'] as $entry)
                                @include('admin.users._permission-check', [
                                    'entry' => $entry, 'roleGranted' => $roleGranted,
                                    'direct' => $direct, 'readonly' => $readonly, 'grantable' => $grantable, 'showLabel' => true,
                                ])
                            @endforeach
                        </div>
                    @endif
                </div>
            </section>
        @endforeach
    </div>

    <p class="gx-perm-empty d-none">Nothing matches this search.</p>

    <div class="gx-perm-legend">
        <span><span class="gx-perm-swatch is-inherited"><i class="bi bi-lock-fill" aria-hidden="true"></i></span>Comes with the role — cannot be changed here</span>
        <span><span class="gx-perm-swatch is-granted"><i class="bi bi-check-lg" aria-hidden="true"></i></span>Granted to this user only</span>
        <span><span class="gx-perm-swatch is-open"></span>Available — click to grant</span>
    </div>
</div>

<style>
    /* ------------------------------------------------------------------
     * Permission matrix. Colours are the app's own tokens: slate for what
     * the role brings, --gx-secondary for what was granted here. Nothing
     * new is introduced.
     * ------------------------------------------------------------------ */
    .gx-perm-toolbar {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: .5rem;
        margin-bottom: 1rem;
    }

    .gx-perm-search { position: relative; flex: 1 1 240px; max-width: 320px; }
    .gx-perm-search .bi {
        position: absolute; left: 12px; top: 50%;
        transform: translateY(-50%); color: #94a3b8; pointer-events: none;
    }
    .gx-perm-search .form-control { padding-left: 34px; }

    .js-perm-chip.is-active {
        background: var(--gx-secondary, #3B82F6);
        border-color: var(--gx-secondary, #3B82F6);
        color: #fff;
    }

    .gx-perm-count { margin-left: auto; font-size: .78rem; color: var(--gx-text-muted, #64748B); }

    /* Cards sit two-up where there is room; the two sectioned modules take
       the full width because their tables are wider. */
    .gx-perm-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
        gap: .75rem;
        align-items: start;
    }
    .gx-perm-card.is-wide { grid-column: 1 / -1; }

    .gx-perm-card {
        border: 1px solid var(--gx-surface-border, #e2e8f0);
        border-radius: var(--gx-radius-sm, 10px);
        background: var(--gx-card, #fff);
        overflow: hidden;
    }

    .gx-perm-head {
        display: flex;
        align-items: center;
        gap: .75rem;
        padding: .5rem .75rem;
        background: #f8fafc;
        border-bottom: 1px solid var(--gx-surface-border, #e2e8f0);
    }

    .gx-perm-fold {
        display: flex; align-items: center; gap: .4rem;
        background: none; border: 0; padding: 0;
        font-size: .84rem; font-weight: 600;
        color: var(--gx-text, #1E293B);
        cursor: pointer;
        text-align: left;
    }
    .gx-perm-caret { font-size: .7rem; color: #94a3b8; transition: transform .12s ease; }
    .is-collapsed .gx-perm-caret { transform: rotate(-90deg); }

    .gx-perm-meter { margin-left: auto; display: flex; align-items: center; gap: .45rem; }
    .gx-perm-meter-bar {
        display: flex; width: 54px; height: 5px;
        border-radius: 999px; overflow: hidden; background: #e2e8f0;
    }
    .gx-perm-meter-role { background: #94a3b8; }
    .gx-perm-meter-direct { background: var(--gx-secondary, #3B82F6); }
    .gx-perm-meter-text {
        font-size: .7rem; font-variant-numeric: tabular-nums;
        color: var(--gx-text-muted, #64748B);
    }

    .gx-perm-body { padding: .6rem .75rem .7rem; }
    .is-collapsed .gx-perm-body { display: none; }

    .gx-perm-table { width: 100%; border-collapse: collapse; }
    .gx-perm-table th, .gx-perm-table td { padding: .3rem .4rem; }
    .gx-perm-table thead th {
        font-size: .62rem; text-transform: uppercase; letter-spacing: .04em;
        color: #94a3b8; font-weight: 600; text-align: center;
        border-bottom: 1px solid #eef2f7; white-space: nowrap;
    }
    .gx-perm-th-section, .gx-perm-th-other { text-align: left !important; }
    .gx-perm-table tbody th {
        font-size: .8rem; font-weight: 500; text-align: left;
        color: var(--gx-text, #1E293B); white-space: nowrap;
    }
    .gx-perm-table tbody th.is-module-wide { color: var(--gx-text-muted, #64748B); font-style: italic; }
    .gx-perm-table tbody tr + tr th, .gx-perm-table tbody tr + tr td { border-top: 1px solid #f1f5f9; }
    .gx-perm-table td { text-align: center; }
    .gx-perm-other { text-align: left !important; }
    .gx-perm-na { color: #cbd5e1; }

    .gx-perm-flat { display: flex; flex-wrap: wrap; gap: .35rem; }

    /* --- the chip -------------------------------------------------- */
    .gx-perm-chip {
        display: inline-flex; align-items: center; gap: .35rem;
        padding: .2rem .5rem;
        border: 1px solid #e2e8f0; border-radius: var(--gx-radius-pill, 999px);
        background: #fff; cursor: pointer;
        font-size: .74rem; line-height: 1.4; color: var(--gx-text-muted, #64748B);
        transition: background .12s ease, border-color .12s ease, color .12s ease;
    }
    .gx-perm-chip.is-compact { padding: .2rem; border-radius: 7px; }

    .gx-perm-input { position: absolute; opacity: 0; width: 0; height: 0; }

    .gx-perm-mark {
        display: inline-flex; align-items: center; justify-content: center;
        width: 17px; height: 17px; flex: 0 0 17px;
        border: 1.5px solid #cbd5e1; border-radius: 5px;
        background: #fff; color: transparent; font-size: .68rem;
    }

    .gx-perm-chip.is-granted { border-color: var(--gx-secondary-border, #BFDBFE); background: var(--gx-secondary-bg, #DBEAFE); color: #1d4ed8; }
    .gx-perm-chip.is-granted .gx-perm-mark { background: var(--gx-secondary, #3B82F6); border-color: var(--gx-secondary, #3B82F6); color: #fff; }

    .gx-perm-chip.is-inherited { border-color: #e2e8f0; background: #f1f5f9; color: #64748b; }
    .gx-perm-chip.is-inherited .gx-perm-mark { background: #94a3b8; border-color: #94a3b8; color: #fff; }

    .gx-perm-chip.is-locked { cursor: not-allowed; }
    .gx-perm-chip.is-open:hover { border-color: var(--gx-secondary, #3B82F6); color: #1d4ed8; }

    .gx-perm-input:focus-visible + .gx-perm-mark {
        outline: 2px solid var(--gx-secondary, #3B82F6);
        outline-offset: 2px;
    }

    .gx-perm-empty { text-align: center; color: var(--gx-text-muted, #64748B); font-size: .82rem; padding: 1.5rem 0; }

    .gx-perm-legend {
        display: flex; flex-wrap: wrap; gap: 1rem;
        margin-top: .9rem; font-size: .75rem; color: var(--gx-text-muted, #64748B);
    }
    .gx-perm-legend span { display: inline-flex; align-items: center; gap: .4rem; }
    .gx-perm-swatch {
        display: inline-flex; align-items: center; justify-content: center;
        width: 17px; height: 17px; border-radius: 5px;
        border: 1.5px solid #cbd5e1; background: #fff; color: #fff; font-size: .62rem;
    }
    .gx-perm-swatch.is-inherited { background: #94a3b8; border-color: #94a3b8; }
    .gx-perm-swatch.is-granted { background: var(--gx-secondary, #3B82F6); border-color: var(--gx-secondary, #3B82F6); }

    @media (prefers-reduced-motion: reduce) {
        .gx-perm-caret, .gx-perm-chip { transition: none; }
    }
</style>

<script>
    (function () {
        var script = document.currentScript;
        var matrix = script.parentElement.querySelector('.gx-perm')
            || document.querySelector('.gx-perm');
        if (! matrix) { return; }

        var readonly = @json($readonly);
        var cards = Array.prototype.slice.call(matrix.querySelectorAll('.js-perm-card'));
        var search = matrix.querySelector('.js-perm-search');
        var chips = Array.prototype.slice.call(matrix.querySelectorAll('.js-perm-chip'));
        var counter = matrix.querySelector('.js-perm-count');
        var empty = matrix.querySelector('.gx-perm-empty');
        var toggleAll = matrix.querySelector('.js-perm-toggle-all');

        var filter = 'all';

        function boxes(el) {
            return Array.prototype.slice.call(el.querySelectorAll('.js-perm-box'));
        }

        /** Repaint one chip from its checkbox — the paint IS the state. */
        function paint(box) {
            var chip = box.closest('.gx-perm-chip');
            if (! chip) { return; }

            var inherited = box.disabled && box.checked;
            chip.classList.toggle('is-inherited', inherited);
            chip.classList.toggle('is-granted', ! inherited && box.checked);
            chip.classList.toggle('is-open', ! box.checked);
            chip.classList.toggle('is-locked', box.disabled);

            var icon = chip.querySelector('.gx-perm-mark .bi');
            if (icon) { icon.className = 'bi ' + (inherited ? 'bi-lock-fill' : 'bi-check-lg'); }
        }

        /** Coverage meter: how much of the module, and where it came from. */
        function meter(card) {
            var all = boxes(card);
            var role = all.filter(function (b) { return b.disabled && b.checked; }).length;
            var direct = all.filter(function (b) { return ! b.disabled && b.checked; }).length;
            var total = all.length || 1;

            card.querySelector('.gx-perm-meter-role').style.width = (role / total * 100) + '%';
            card.querySelector('.gx-perm-meter-direct').style.width = (direct / total * 100) + '%';
            card.querySelector('.js-perm-meter').textContent = (role + direct) + '/' + all.length;
        }

        /**
         * Whether a card survives the current chip. Read off live checkbox
         * state, so it stays true after the role dropdown changes what is
         * locked.
         */
        function passesChip(card) {
            if (filter === 'all') { return true; }
            if (filter === 'stock') {
                return card.dataset.module === 'store' || card.dataset.module === 'material';
            }

            return boxes(card).some(function (b) {
                return filter === 'role' ? (b.disabled && b.checked) : (! b.disabled && b.checked);
            });
        }

        function apply() {
            var term = (search.value || '').trim().toLowerCase();
            var visible = 0;

            cards.forEach(function (card) {
                var hit = (! term || card.dataset.text.indexOf(term) !== -1) && passesChip(card);
                card.classList.toggle('d-none', ! hit);
                if (! hit) { return; }

                visible++;

                // Inside a matching card, narrow to the matching sections —
                // searching "issues" should not leave every General Stock row
                // on screen just because the module matched.
                if (term) {
                    var rows = card.querySelectorAll('.js-perm-row');
                    var anyRow = false;

                    rows.forEach(function (row) {
                        var rowHit = row.dataset.text.indexOf(term) !== -1;
                        row.classList.toggle('d-none', ! rowHit);
                        if (rowHit) { anyRow = true; }
                    });

                    // Module name matched but no row did: show them all.
                    if (! anyRow) {
                        rows.forEach(function (row) { row.classList.remove('d-none'); });
                    }
                } else {
                    card.querySelectorAll('.js-perm-row').forEach(function (row) {
                        row.classList.remove('d-none');
                    });
                }
            });

            counter.textContent = visible + ' of ' + cards.length + ' modules';
            empty.classList.toggle('d-none', visible > 0);
        }

        function refresh() {
            matrix.querySelectorAll('.js-perm-box').forEach(paint);
            cards.forEach(meter);
            apply();
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

        cards.forEach(function (card) {
            card.querySelector('.js-perm-fold').addEventListener('click', function (e) {
                var now = card.classList.toggle('is-collapsed');
                e.currentTarget.setAttribute('aria-expanded', now ? 'false' : 'true');
            });
        });

        toggleAll.addEventListener('click', function () {
            var expand = toggleAll.dataset.expanded !== '1';

            cards.forEach(function (card) {
                card.classList.toggle('is-collapsed', ! expand);
                card.querySelector('.js-perm-fold').setAttribute('aria-expanded', expand ? 'true' : 'false');
            });

            toggleAll.dataset.expanded = expand ? '1' : '0';
            toggleAll.innerHTML = expand
                ? '<i class="bi bi-chevron-contract me-1" aria-hidden="true"></i>Collapse all'
                : '<i class="bi bi-chevron-expand me-1" aria-hidden="true"></i>Expand all';
        });

        if (! readonly) {
            /**
             * Keep the matrix honest when the role dropdown changes.
             *
             * The locked chips describe the role selected right now. Change the
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
                    paint(box);
                    meter(box.closest('.js-perm-card'));
                    apply();
                });

                var applyRole = function () {
                    var granted = new Set(byRole[roleSelect.value] || []);

                    matrix.querySelectorAll('.js-perm-box').forEach(function (box) {
                        // A permission the editor does not hold themselves is
                        // left exactly as it was drawn — locked, and still
                        // showing whatever the user actually has. Repainting it
                        // from the role would either unlock it or tick it away,
                        // and the server refuses both.
                        if (box.dataset.beyondReach === '1') { return; }

                        var fromRole = granted.has(box.dataset.permission);
                        box.disabled = fromRole;
                        box.checked = fromRole || chosen.has(box.dataset.permission);
                    });

                    refresh();
                };

                roleSelect.addEventListener('change', applyRole);
                applyRole();
            }
        }

        refresh();
    })();
</script>
@endif
