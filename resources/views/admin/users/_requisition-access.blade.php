{{--
    Portal Access — one row per switch in App\Support\PortalAccess.

    Each switch grants a small bundle of existing permissions to THIS user, on
    top of their role. The same boxes live in the permission matrix below; the
    script keeps the two in step, and shows "Included in role" and locks the row
    when the chosen role already carries the whole bundle.

    The hidden marker says the block was on the page: an unticked switch posts
    nothing, and without the marker a save from a form that never showed this
    block would read as "switch everything off".
--}}
@php
    $mine = isset($user) ? $user->getAllPermissions()->pluck('name') : collect();
    $rows = collect(\App\Support\PortalAccess::toggles())
        ->filter(fn ($t) => collect($t['permissions'])->every(fn ($p) => $scope->mayGrant($p)))
        ->map(function ($t, $key) use ($mine) {
            $held = collect($t['permissions'])->every(fn ($p) => $mine->contains($p));
            $t['on'] = old('portal_access_control') ? (bool) old('portal_access.'.$key) : $held;

            return $t;
        });
@endphp

@if($rows->isNotEmpty())
    <section class="gx-access mb-4" aria-labelledby="portalAccessTitle">
        <input type="hidden" name="portal_access_control" value="1">

        <header class="gx-access-head">
            <h6 id="portalAccessTitle" class="gx-access-title">Portal Access</h6>
            <p class="gx-access-sub">Extra abilities for this person, on top of their role.</p>
        </header>

        <ul class="gx-access-list">
            @foreach($rows as $key => $toggle)
                <li class="gx-access-row js-access-row" data-permissions='@json($toggle['permissions'])'>
                    <label class="gx-access-label" for="access_{{ $key }}">
                        <span class="gx-access-icon" aria-hidden="true"><i class="bi {{ $toggle['icon'] }}"></i></span>
                        <span class="gx-access-text">
                            <span class="gx-access-name">{{ $toggle['label'] }}</span>
                            <span class="gx-access-desc js-access-desc" data-default="{{ $toggle['description'] }}">{{ $toggle['description'] }}</span>
                        </span>
                    </label>

                    <span class="gx-access-badge js-access-badge" hidden>Included in role</span>

                    <label class="gx-access-switch" for="access_{{ $key }}">
                        <input type="checkbox" class="js-access-input" id="access_{{ $key }}"
                               name="portal_access[{{ $key }}]" value="1" role="switch" @checked($toggle['on'])>
                        <span class="gx-access-track" aria-hidden="true"></span>
                        <span class="visually-hidden">{{ $toggle['label'] }}</span>
                    </label>
                </li>
            @endforeach
        </ul>
    </section>

    <style>
        .gx-access { border: 1px solid var(--gx-surface-border, #e2e8f0); border-radius: var(--gx-radius-sm, 12px); background: var(--gx-card, #fff); overflow: hidden; }
        .gx-access-head { padding: .75rem 1rem; background: #f8fafc; border-bottom: 1px solid var(--gx-surface-border, #e2e8f0); }
        .gx-access-title { margin: 0; font-size: .85rem; font-weight: 700; color: var(--gx-text, #1E293B); }
        .gx-access-sub { margin: .1rem 0 0; font-size: .75rem; color: var(--gx-text-muted, #64748B); }
        .gx-access-list { list-style: none; margin: 0; padding: 0; }
        .gx-access-row { display: flex; align-items: center; gap: .75rem; padding: .8rem 1rem; }
        .gx-access-row + .gx-access-row { border-top: 1px solid #f1f5f9; }
        .gx-access-label { display: flex; align-items: center; gap: .75rem; flex: 1 1 auto; min-width: 0; margin: 0; cursor: pointer; }
        .gx-access-icon { display: inline-flex; align-items: center; justify-content: center; flex: 0 0 36px; width: 36px; height: 36px; border-radius: 10px; background: var(--gx-secondary-bg, #DBEAFE); color: #1d4ed8; font-size: 1rem; }
        .gx-access-text { display: flex; flex-direction: column; min-width: 0; }
        .gx-access-name { font-size: .88rem; font-weight: 600; color: var(--gx-text, #1E293B); }
        .gx-access-desc { font-size: .76rem; color: var(--gx-text-muted, #64748B); }
        .gx-access-badge { flex: 0 0 auto; padding: .15rem .55rem; border-radius: 999px; background: #f1f5f9; color: #64748b; font-size: .68rem; font-weight: 600; }

        .gx-access-switch { position: relative; flex: 0 0 auto; width: 42px; height: 24px; margin: 0; cursor: pointer; }
        .gx-access-switch input { position: absolute; inset: 0; width: 100%; height: 100%; margin: 0; opacity: 0; cursor: inherit; }
        .gx-access-track { position: absolute; inset: 0; border-radius: 999px; background: #cbd5e1; transition: background .15s ease; pointer-events: none; }
        .gx-access-track::after { content: ''; position: absolute; top: 3px; left: 3px; width: 18px; height: 18px; border-radius: 50%; background: #fff; box-shadow: 0 1px 2px rgba(15, 23, 42, .25); transition: transform .15s ease; }
        .gx-access-switch input:checked + .gx-access-track { background: var(--gx-secondary, #3B82F6); }
        .gx-access-switch input:checked + .gx-access-track::after { transform: translateX(18px); }
        .gx-access-switch input:focus-visible + .gx-access-track { outline: 2px solid var(--gx-secondary, #3B82F6); outline-offset: 2px; }
        .gx-access-switch input:disabled { cursor: not-allowed; }
        .gx-access-switch input:disabled + .gx-access-track { opacity: .55; }

        @media (max-width: 575.98px) { .gx-access-badge { display: none !important; } }
    </style>

    <script>
        (function () {
            var rows = Array.prototype.slice.call(document.querySelectorAll('.js-access-row'));
            if (! rows.length) { return; }

            function boxesFor(row) {
                return JSON.parse(row.dataset.permissions).map(function (name) {
                    return document.querySelector('.js-perm-box[data-permission="' + name + '"]');
                }).filter(Boolean);
            }

            // Mirror the matrix: on when every box of the bundle is ticked,
            // locked on when the chosen role already carries all of them.
            function sync(row) {
                var boxes = boxesFor(row);
                if (boxes.length !== JSON.parse(row.dataset.permissions).length) { return; }

                var input = row.querySelector('.js-access-input');
                var badge = row.querySelector('.js-access-badge');
                var fromRole = boxes.every(function (b) { return b.disabled && b.checked; });

                input.disabled = fromRole;
                badge.hidden = ! fromRole;
                input.checked = fromRole || boxes.every(function (b) { return b.checked; });
            }

            rows.forEach(function (row) {
                row.querySelector('.js-access-input').addEventListener('change', function (e) {
                    // A box another switched-on row still needs (e.g. the shared "view" right) stays ticked.
                    var keep = [];
                    if (! e.target.checked) {
                        rows.forEach(function (other) {
                            if (other !== row && other.querySelector('.js-access-input').checked) {
                                keep = keep.concat(JSON.parse(other.dataset.permissions));
                            }
                        });
                    }
                    boxesFor(row).forEach(function (b) {
                        if (b.disabled || b.checked === e.target.checked) { return; }
                        if (! e.target.checked && keep.indexOf(b.dataset.permission) !== -1) { return; }
                        b.checked = e.target.checked;
                        b.dispatchEvent(new Event('change', { bubbles: true }));
                    });
                });
            });

            function syncAll() { rows.forEach(sync); }

            document.addEventListener('change', function (e) {
                if (e.target.closest && e.target.closest('.js-perm-box')) { syncAll(); }
            });

            var role = document.querySelector('.js-role');
            if (role) { role.addEventListener('change', function () { setTimeout(syncAll, 0); }); }

            document.addEventListener('DOMContentLoaded', syncAll);
        })();
    </script>
@endif
