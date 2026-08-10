{{--
    Ties the Department select to the Role select.

    Choosing a department hides every role that does not belong to it. Roles are
    hidden rather than rebuilt so the element the permission matrix listens to
    stays the same one — the matrix recomputes its locked ticks from the role
    dropdown, and replacing that element would leave it listening to a node no
    longer in the page.

    If the department changes while a role of another department is selected,
    the role is cleared rather than silently left mismatched: submitting it
    would be refused by the controller anyway, and clearing says so before the
    round trip. A change event is dispatched so the matrix repaints for the now
    empty selection.
--}}
<script>
    (function () {
        var department = document.querySelector('.js-department');
        var role = document.querySelector('.js-role');

        if (! department || ! role) { return; }

        var options = Array.prototype.slice.call(role.options);

        function apply(clearMismatch) {
            var chosen = department.value;

            options.forEach(function (option) {
                if (! option.value) { return; }   // the "Select Role" placeholder

                // No department chosen yet: offer everything rather than an
                // empty list. A role mapped to no department stays offered too,
                // or a user holding one could never be edited.
                var belongs = ! chosen
                    || option.dataset.department === chosen
                    || option.dataset.department === 'other';

                option.hidden = ! belongs;
                option.disabled = ! belongs;
            });

            var selected = role.selectedOptions[0];

            if (clearMismatch && selected && selected.value && selected.disabled) {
                role.value = '';
                // The permission matrix listens on this element for the role's
                // grants; tell it the selection went away.
                role.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }

        department.addEventListener('change', function () { apply(true); });

        // On load, filter without clearing — an existing user's saved role must
        // survive the first pass even if the map has since moved it.
        apply(false);
    })();
</script>
