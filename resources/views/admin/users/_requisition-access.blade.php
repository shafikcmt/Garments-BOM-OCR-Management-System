{{-- Requisition access — one switch for the common case: let this person raise
     a General Stock Purchase Requisition from their own portal, whatever their
     department. It grants exactly store.requisition.view + store.requisition.create
     on top of their role, and nothing from Buyer / Style Stock. The same two
     boxes live in the permission matrix below; the script keeps both in step. --}}
@php
    $canGrantRaise = $scope->mayGrant('store.requisition.view') && $scope->mayGrant('store.requisition.create');
    $holdsRaise = isset($user)
        && collect(['store.requisition.view', 'store.requisition.create'])
            ->every(fn ($name) => $user->getAllPermissions()->pluck('name')->contains($name));
    $raiseOn = old('can_raise_requisition_control') ? (bool) old('can_raise_requisition') : $holdsRaise;
@endphp
@if($canGrantRaise)
    <div class="mb-3">
        <input type="hidden" name="can_raise_requisition_control" value="1">
        <div class="form-check form-switch">
            <input type="checkbox" class="form-check-input" role="switch" id="canRaiseRequisition"
                   name="can_raise_requisition" value="1" @checked($raiseOn)>
            <label class="form-check-label" for="canRaiseRequisition">Can raise General Stock Requisition</label>
        </div>
        <div class="form-text js-raise-hint">
            Lets this person create and follow their own Purchase Requisitions from their portal. Works for any department. Does not give Buyer / Style Stock access.
        </div>
    </div>

    <script>
        (function () {
            var toggle = document.getElementById('canRaiseRequisition');
            if (! toggle) { return; }

            var hint = document.querySelector('.js-raise-hint');
            var hintText = hint ? hint.textContent : '';
            var names = ['store.requisition.view', 'store.requisition.create'];

            function boxes() {
                return names.map(function (n) {
                    return document.querySelector('.js-perm-box[data-permission="' + n + '"]');
                }).filter(Boolean);
            }

            // Mirror the matrix: on when both boxes are ticked, locked on when
            // the chosen role already carries them.
            function sync() {
                var b = boxes();
                if (b.length !== names.length) { return; }

                var fromRole = b.every(function (x) { return x.disabled && x.checked; });
                toggle.disabled = fromRole;
                if (fromRole) { toggle.checked = true; }
                else { toggle.checked = b.every(function (x) { return x.checked; }); }

                if (hint) {
                    hint.textContent = fromRole ? 'This role already includes requisition access.' : hintText;
                }
            }

            toggle.addEventListener('change', function () {
                boxes().forEach(function (x) {
                    if (x.disabled || x.checked === toggle.checked) { return; }
                    x.checked = toggle.checked;
                    x.dispatchEvent(new Event('change', { bubbles: true }));
                });
            });

            document.addEventListener('change', function (e) {
                if (e.target.closest && e.target.closest('.js-perm-box')) { sync(); }
            });

            var role = document.querySelector('.js-role');
            if (role) { role.addEventListener('change', function () { setTimeout(sync, 0); }); }

            document.addEventListener('DOMContentLoaded', sync);
        })();
    </script>
@endif
