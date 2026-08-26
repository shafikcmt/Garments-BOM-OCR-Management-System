{{--
    Buyer Access — the reusable half of the buyer-scoping UI.

    ONE BLOCK, NOT ONE PER DEPARTMENT. It is driven entirely by what the
    controller passes, so switching another department on is a string added to
    BuyerScope::SCOPED_DEPARTMENTS and nothing here.

    Contract:
      $assignableBuyers       Buyer collection the actor may tick. Empty hides
                              the block completely.
      $mayChooseBuyerScope    whether the All / Specific radio is offered. Only
                              a super admin gets it: a department admin handing
                              out "all buyers" would be granting every buyer
                              that will ever exist, which is authority, not
                              access.
      $currentBuyerScopeMode  'all' | 'specific'
      $currentBuyerIds        list<int> already ticked
      $scopeDepartments       list<string>|null department keys the block
                              applies to. When given, the script below shows and
                              hides the block as the Department select changes.
                              Null means always shown.
      $startsOpen             bool|null whether the block is visible on the
                              first paint, before any script runs. Defaults to
                              false, which is right for create — nothing is
                              chosen yet. The edit screen passes true when the
                              stored department is already scoped, so the page
                              is correct even if the script never runs.

    THE FORM IS NOT THE CONTROL. Everything offered here is re-derived and
    re-checked server-side against the actor's own buyers; a stale or hand-made
    post assigns nothing.
--}}
@php($scopeDepartments = $scopeDepartments ?? null)
@php($startsOpen = $startsOpen ?? false)

<div class="mb-3 js-buyer-access"
     @if($scopeDepartments !== null) data-scope-departments="{{ implode(',', $scopeDepartments) }}" @unless($startsOpen) hidden @endunless @endif>

    <label class="form-label fw-semibold d-block mb-1">Buyer Access</label>

    {{-- Present on every submit of a form carrying this block, so the server can
         tell "the control was on the page and left alone" apart from "the
         control was never shown". Without it an untouched form would read as
         "clear their buyers". --}}
    <input type="hidden" name="buyer_assignment_control" value="1">

    @if($mayChooseBuyerScope)
        <div class="form-check">
            <input class="form-check-input js-buyer-scope-mode" type="radio" name="buyer_scope_mode"
                   id="buyerScopeModeAll" value="all"
                   @checked(old('buyer_scope_mode', $currentBuyerScopeMode) === 'all')>
            <label class="form-check-label" for="buyerScopeModeAll">
                All buyers
            </label>
        </div>
        <div class="form-check mb-2">
            <input class="form-check-input js-buyer-scope-mode" type="radio" name="buyer_scope_mode"
                   id="buyerScopeModeSpecific" value="specific"
                   @checked(old('buyer_scope_mode', $currentBuyerScopeMode) === 'specific')>
            <label class="form-check-label" for="buyerScopeModeSpecific">
                Specific buyers only
            </label>
        </div>
        <div class="form-text mb-2">
            Choosing <strong>All buyers</strong> clears the ticks below and lets this
            person work on every buyer, including ones added later.
        </div>
        @error('buyer_scope_mode')<div class="text-danger small mb-2">{{ $message }}</div>@enderror
    @else
        <div class="form-text mb-2">
            Tick the buyers this person works on. They can edit files for the buyers
            you tick, and no others.
        </div>
    @endif

    <div class="js-buyer-list">
        @foreach($assignableBuyers as $assignableBuyer)
            <div class="form-check">
                <input class="form-check-input @error('buyer_ids') is-invalid @enderror"
                       type="checkbox" name="buyer_ids[]" value="{{ $assignableBuyer->id }}"
                       id="buyerAccess{{ $assignableBuyer->id }}"
                       @checked(in_array(
                           (string) $assignableBuyer->id,
                           array_map('strval', (array) old('buyer_ids', $currentBuyerIds)),
                           true
                       ))>
                <label class="form-check-label" for="buyerAccess{{ $assignableBuyer->id }}">
                    {{ $assignableBuyer->buyer_name }}{{ $assignableBuyer->buyer_code ? ' (' . $assignableBuyer->buyer_code . ')' : '' }}
                </label>
            </div>
        @endforeach
    </div>

    @error('buyer_ids')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
    @error('buyer_ids.*')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
</div>

@once
    {{-- Inlined rather than pushed: this layout has no scripts stack, and
         _department-roles.blade.php inlines its own for the same reason. --}}
        {{--
            Two behaviours, both display-only. Neither decides anything the
            server does not decide again.

            1. Show the block only for a department buyer scoping applies to.
               Offering Buyer Access for a Store user would be a field that does
               nothing, which is worse than no field. Listens to the same
               .js-department select the role filter uses. THE BLOCK IS ALWAYS IN
               THE PAGE for an eligible target — hiding it here rather than
               refusing to render it server-side is the whole reason switching
               the dropdown can reveal it without a save first.
            2. Grey the buyer list out under "All buyers", so the two controls
               cannot be read as saying different things at once.
            3. Show the read-only "Assigned Buyer" badges, when the edit screen
               rendered them, exactly while the block is hidden. Otherwise a user
               who holds buyers but sits in an unscoped department would show
               neither — the editable control hidden and the badges gone with it.
        --}}
        <script>
            (function () {
                var block = document.querySelector('.js-buyer-access');

                if (! block) { return; }

                var list = block.querySelector('.js-buyer-list');
                var modes = block.querySelectorAll('.js-buyer-scope-mode');
                var department = document.querySelector('.js-department');
                var scoped = (block.dataset.scopeDepartments || '')
                    .split(',')
                    .filter(function (key) { return key !== ''; });

                function applyMode() {
                    if (! modes.length || ! list) { return; }

                    var specific = block.querySelector('.js-buyer-scope-mode[value="specific"]');
                    var on = specific && specific.checked;

                    list.style.opacity = on ? '' : '0.5';
                    list.querySelectorAll('input[type="checkbox"]').forEach(function (box) {
                        box.disabled = ! on;
                        if (! on) { box.checked = false; }
                    });
                }

                var badges = document.querySelector('.js-buyer-badges');

                function applyDepartment() {
                    // No data attribute means "always shown".
                    if (! scoped.length) { return; }

                    block.hidden = scoped.indexOf(department ? department.value : '') === -1;

                    // The badges are the read-only view of the same fact, so
                    // exactly one of the two is on screen at any moment.
                    if (badges) { badges.hidden = ! block.hidden; }
                }

                modes.forEach(function (radio) {
                    radio.addEventListener('change', applyMode);
                });

                if (department) {
                    department.addEventListener('change', applyDepartment);
                }

                applyDepartment();
                applyMode();
            })();
        </script>
@endonce
