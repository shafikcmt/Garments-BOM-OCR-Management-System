{{--
    Type-to-search dropdowns for the General Stock screens.

    <select class="js-searchable"> becomes a searchable list.
    <select class="js-creatable"> is searchable AND lets the user type a value
        that is not in the list yet. A typed value is submitted as "new:<name>";
        the controller resolves it against the master table and creates the row
        if it is genuinely new (see ResolvesIssueSetupMasters), so it appears as
        an ordinary suggestion next time.

    The item master runs to well over a thousand consumables and the Indent
    Person master to a couple of hundred names, so a plain <select> is not
    usable for store staff.

    TomSelect is already a project dependency (used by Bulk Issue and Bookings);
    this loads the same version from the CDN those screens use.
--}}
<link href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.bootstrap5.min.css" rel="stylesheet">

<style>
    /* ------------------------------------------------------------------
     * TomSelect copies the original <select>'s class list onto the wrapper
     * it builds, so .ts-wrapper ends up carrying .form-select / .form-control.
     * The TomSelect bootstrap5 theme already tries to neutralise that, but
     * this app's components.css declares border-radius and border-color on
     * .form-select with !important, which wins — leaving the wrapper drawing
     * a box while .ts-control inside draws a second one. The text then sits
     * squeezed between two borders with Bootstrap's caret image over it.
     *
     * Strip the chrome off the wrapper and let .ts-control own it.
     * ------------------------------------------------------------------ */
    .ts-wrapper.form-select,
    .ts-wrapper.form-control {
        /* Keep the border, radius and background so the field still looks like
           every other input in the app — only remove the two things that cause
           the overlap: Bootstrap's caret image, painted on top of the text
           TomSelect draws itself, and the padding it reserves for that caret
           (.ts-control does its own padding). */
        background-image: none !important;
        padding: 0 !important;
        height: auto !important;
        display: flex;
        align-items: center;
    }

    /* The wrapper owns the box, so the inner control must not draw a second. */
    .ts-wrapper.form-select .ts-control,
    .ts-wrapper.form-control .ts-control {
        border: 0 !important;
        background: transparent !important;
        box-shadow: none !important;
        min-height: 0 !important;
        width: 100%;
        padding: .3rem .65rem !important;
    }

    .ts-wrapper.form-select-sm .ts-control,
    .ts-wrapper.form-control-sm .ts-control {
        padding: .2rem .55rem !important;
        font-size: 12.5px;
    }

    .ts-wrapper.focus,
    .ts-wrapper.dropdown-active {
        border-color: #60a5fa !important;
        box-shadow: 0 0 0 .2rem rgba(37, 99, 235, .10);
    }

    .ts-wrapper.disabled { background-color: #f1f5f9 !important; }

    /* Single-select: while the user is typing, the previously selected label
       must get out of the way or the two overlap in the same box. */
    .ts-wrapper.single.input-active .ts-control > .item { display: none; }
    .ts-wrapper.single .ts-control > .item { line-height: 1.35; }

    /* The dropdown is rendered on <body> (see dropdownParent below) so it can
       never be clipped by a scrolling ancestor. It therefore needs to sit
       above cards, sticky headers and modals. */
    .ts-dropdown {
        z-index: 1080;
        border: 1px solid #dbe4f0;
        border-radius: 12px;
        box-shadow: 0 12px 28px rgba(15, 23, 42, .14);
        font-size: 13px;
        margin-top: 4px;
        overflow: hidden;
    }
    .ts-dropdown .option { padding: .4rem .7rem; }
    .ts-dropdown .active { background: #dbeafe; color: #1d4ed8; }
    .ts-dropdown .create { padding: .4rem .7rem; }
    .ts-dropdown .no-results { padding: .5rem .7rem; color: #64748b; }
</style>

<script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>
<script>
    (function () {
        // Must match ResolvesIssueSetupMasters::NEW_VALUE_PREFIX.
        var NEW_PREFIX = 'new:';

        var KEY_TAB = 9;
        var KEY_UP = 38;
        var KEY_DOWN = 40;

        /**
         * Tab picks the highlighted option, then moves on to the next field.
         *
         * Store staff enter a requisition from the keyboard: type a few letters,
         * see the name they want, Tab to the next box. Without this, Tab left the
         * field with nothing chosen and the typing was thrown away.
         *
         * TomSelect ships `selectOnTab`, but it calls preventDefault() so focus
         * stays put — the operator then has to press Tab a second time. We want
         * both halves: select, and carry on to the next field. So the key is
         * handled here and the event is deliberately left alone afterwards, which
         * lets the browser do its own Tab navigation.
         *
         * Hooked by replacing the instance's onKeyDown rather than by adding a
         * listener to a particular element: TomSelect calls `self.onKeyDown(e)`
         * through the instance at event time, and which element it listens on
         * differs between its two control layouts. Going through the method is
         * correct for both. Enter, arrows, Escape and every other key fall
         * through to the original untouched.
         */
        function enableTabToSelect(self, userDrove, markUserDrove) {
            var parentKeyDown = self.onKeyDown;

            self.onKeyDown = function (e) {
                if (e.keyCode === KEY_UP || e.keyCode === KEY_DOWN) {
                    markUserDrove();
                    return parentKeyDown.call(self, e);
                }

                // Ctrl/Alt/Meta+Tab belongs to the browser, not to us.
                if (e.keyCode !== KEY_TAB || e.ctrlKey || e.altKey || e.metaKey) {
                    return parentKeyDown.call(self, e);
                }

                var option = self.activeOption;

                // canSelect() checks the list is open and the option is really
                // in it. Guarded because the CDN build is pinned a few minor
                // versions behind the one this was read from.
                var selectable = typeof self.canSelect === 'function'
                    ? self.canSelect(option)
                    : !!(self.isOpen && option && self.dropdown_content.contains(option));

                if (!userDrove() || !selectable) {
                    // Nothing the user highlighted — plain Tab, no forced pick.
                    return parentKeyDown.call(self, e);
                }

                // Handles the "Add <name> to the list" row too: onOptionSelect
                // routes a .create option into createItem() itself, so a typed
                // new master name is kept on Tab exactly as it is on Enter.
                self.onOptionSelect(e, option);
                self.close();

                // No preventDefault, on purpose — the browser moves focus.
            };
        }

        function build(el, creatable) {
            if (el.tomselect) { return; }

            // Did the user drive the highlight on this field, by typing a search
            // or walking the list with the arrow keys?
            //
            // TomSelect highlights an option every time the list is refreshed,
            // including the moment it opens, so there is always an "active"
            // option whether the user asked for one or not. Selecting that on
            // Tab unconditionally would mean clicking into an empty field and
            // tabbing straight out silently picks whatever happened to be first.
            // The flag is what separates a highlight the user aimed at from one
            // the widget put there. Reset every time the list opens.
            var userDroveHighlight = false;

            var options = {
                maxOptions: null,
                allowEmptyOption: true,
                // Render the dropdown on <body> rather than inside the wrapper.
                // A select inside a .table-responsive is otherwise clipped:
                // Bootstrap sets overflow-x:auto there, and CSS then computes
                // overflow-y to auto as well, so the list is cut off at the
                // bottom of the table instead of overflowing past it.
                dropdownParent: 'body',
                // Blank the search box on open so the list is not filtered by
                // whatever is already selected.
                onFocus: function () { this.setTextboxValue(''); },
                // With the dropdown rendered on <body> it is no longer a child
                // of the control, so TomSelect's own outside-click handling can
                // leave a previous one open — two lists float over the page at
                // once. Closing the others on open keeps it to one.
                onDropdownOpen: function () {
                    userDroveHighlight = false;

                    var self = this;
                    document.querySelectorAll('select.tomselected').forEach(function (el) {
                        if (el.tomselect && el.tomselect !== self) { el.tomselect.close(); }
                    });
                },
                // Typing a search counts as aiming at the highlight.
                onType: function () { userDroveHighlight = true; },
                onInitialize: function () { enableTabToSelect(this, function () { return userDroveHighlight; }, function () { userDroveHighlight = true; }); },
            };

            if (creatable) {
                options.create = function (input) {
                    var name = input.trim();
                    if (!name) { return false; }

                    // The value carries the marker; the label stays clean so
                    // the user sees exactly what they typed.
                    return { value: NEW_PREFIX + name, text: name };
                };
                options.createFilter = function (input) {
                    var wanted = input.trim().toLowerCase();
                    if (!wanted) { return false; }

                    // Do not offer "add new" for something already in the list —
                    // that is how duplicate spellings get in.
                    return !Object.keys(this.options).some(function (key) {
                        return (this.options[key].text || '').trim().toLowerCase() === wanted;
                    }, this);
                };
                options.render = {
                    option_create: function (data, escape) {
                        return '<div class="create">Add <strong>' + escape(data.input) + '</strong> to the list</div>';
                    },
                };
            }

            new TomSelect(el, options);
        }

        function init(root) {
            if (!window.TomSelect) { return; }

            root = root || document;
            root.querySelectorAll('select.js-searchable').forEach(function (el) { build(el, false); });
            root.querySelectorAll('select.js-creatable').forEach(function (el) { build(el, true); });
        }

        // Exposed so a screen that adds rows after load (the multi-item Record
        // Issue form) can wire up the selects it just created.
        window.gxInitSearchable = init;

        function start() {
            // Fields outside a modal, built once up front.
            document.querySelectorAll('select.js-searchable, select.js-creatable').forEach(function (el) {
                if (!el.closest('.modal')) { build(el, el.classList.contains('js-creatable')); }
            });

            // Fields inside a modal are built when that modal is first opened.
            // A page listing 25 items carries 25 hidden edit forms; building
            // them all up front is wasted work, and TomSelect measures a hidden
            // element badly, which leaves the dropdown mis-positioned.
            document.addEventListener('shown.bs.modal', function (event) { init(event.target); });
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', start);
        } else {
            start();
        }
    })();
</script>
