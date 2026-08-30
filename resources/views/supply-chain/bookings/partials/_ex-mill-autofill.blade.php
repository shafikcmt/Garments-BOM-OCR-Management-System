{{--
    Required Ex-mill auto-fill for the Booking Format edit panel.

    WHY THIS IS A SEPARATE FILE, AND WHY THE LISTENERS ARE ON document.
    The preview partial is injected into the modal with `innerHTML`, and a
    <script> tag inserted that way never executes. Anything bound to the edit
    form itself would therefore be dead on the screen that matters most. These
    listeners sit on `document` instead, so they are registered once by the host
    page and keep working across every re-render of the preview.

    Include this from any page that can show the EDITABLE preview. The read-only
    preview has no inputs for it to find, so including it there is harmless.

    THE RULE IT ENFORCES: Required Ex-mill follows Date + 1 month until the user
    types in it, and never afterwards. A booking re-dated twice must not quietly
    throw away a deadline somebody agreed with the vendor.
--}}
<script>
    (function () {
        // dd/mm/yyyy — the format defaultBookingData() writes and the format
        // the Date field beside it already uses. Anything else is left alone
        // rather than guessed at: a half-typed date must not become a wrong one.
        function parse(value) {
            var match = /^\s*(\d{1,2})\/(\d{1,2})\/(\d{4})\s*$/.exec(value || '');

            if (! match) { return null; }

            var day = parseInt(match[1], 10);
            var month = parseInt(match[2], 10);
            var year = parseInt(match[3], 10);
            var date = new Date(year, month - 1, day);

            // Rejects 31/02/2026 and friends, which the Date constructor would
            // otherwise roll forward into March without complaint.
            if (date.getFullYear() !== year || date.getMonth() !== month - 1 || date.getDate() !== day) {
                return null;
            }

            return date;
        }

        function format(date) {
            var pad = function (n) { return (n < 10 ? '0' : '') + n; };

            return pad(date.getDate()) + '/' + pad(date.getMonth() + 1) + '/' + date.getFullYear();
        }

        // Calendar month, not 30 days. Clamped to the last day of the target
        // month so 31/01 becomes 28/02 rather than JavaScript's 03/03.
        function addMonth(date) {
            var target = new Date(date.getFullYear(), date.getMonth() + 1, 1);
            var lastDay = new Date(target.getFullYear(), target.getMonth() + 1, 0).getDate();

            target.setDate(Math.min(date.getDate(), lastDay));

            return target;
        }

        function panelOf(el) {
            return el.closest('form') || el.closest('.booking-format-preview-box') || document;
        }

        document.addEventListener('input', function (event) {
            var field = event.target;

            if (! field || ! field.classList) { return; }

            // The user typing in Ex-mill is what makes it theirs. Recorded on
            // the element, so it survives for as long as the panel is open and
            // resets naturally when the preview is re-rendered.
            if (field.classList.contains('js-booking-ex-mill')) {
                field.dataset.exMillTouched = '1';

                return;
            }

            if (! field.classList.contains('js-booking-date')) { return; }

            var exMill = panelOf(field).querySelector('.js-booking-ex-mill');

            if (! exMill || exMill.dataset.exMillTouched === '1') { return; }

            var parsed = parse(field.value);

            if (! parsed) { return; }

            exMill.value = format(addMonth(parsed));
        });
    })();
</script>
