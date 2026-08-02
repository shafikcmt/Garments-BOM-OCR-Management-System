/**
 * Alpine shell for the New Bulk Issue panel: section gating, the save gate,
 * toasts, the remarks counter and draft autosave.
 *
 * One screen, not a wizard: Filters (which hold the Purchase Order itself), the
 * issue matrix, the Indent header and Save are all mounted at once. The matrix
 * card stays live even before a PO exists — it carries the empty state whose
 * button opens the Filters dialog — while the Indent header below it is locked
 * (greyed + `inert`) until a booking is chosen, because an issue with no
 * booking has nothing to authorise.
 *
 * Deliberately narrow. The PO search, the matrix and the stock-balance
 * validation stay in bulk-issue-table.js and keep owning their own DOM — two
 * frameworks writing the same nodes is how these panels rot. The vanilla module
 * publishes what it knows on a `bi:state` event and this component only reads
 * it, so neither side reaches into the other.
 */

const DRAFT_KEY = 'bulkIssueDraft';
const DRAFT_DEBOUNCE_MS = 3000;

// Only the fields the user types. The PO and its items are server-resolved, so
// restoring them from a stale draft could attach an issue to the wrong BOM row.
const DRAFT_FIELDS = ['biSection', 'biPerson', 'biReqNo', 'biIssueNo', 'biRemarks'];

export function registerBulkIssueWizard(Alpine) {
    Alpine.data('bulkIssueWizard', () => ({
        // Mirrored from the vanilla module via bi:state.
        hasPo: false,
        itemCount: 0,
        // Rows ticked into the issue. The whole PO loads into the matrix now,
        // so rows on screen and rows being saved are two different counts.
        includedCount: 0,
        blocked: false,
        editing: false,
        // Rows carrying at least one of the four quantities, and rows whose
        // total exceeds their stock balance.
        withQty: 0,
        errorCount: 0,
        // Mirrored so the Save gate re-evaluates when the date is cleared —
        // reading the input inside saveBlocker() alone would not be reactive.
        issueDate: '',

        remarksLength: 0,
        remarksMax: 1000,
        toasts: [],
        toastSeq: 0,
        draftTimer: null,

        init() {
            this.$el.addEventListener('bi:state', (e) => {
                const d = e.detail || {};
                const hadPo = this.hasPo;

                this.hasPo = !!d.hasPo;
                this.itemCount = d.itemCount || 0;
                this.includedCount = d.includedCount || 0;
                this.blocked = !!d.blocked;
                this.editing = !!d.editing;
                this.withQty = d.withQty || 0;
                this.errorCount = d.errorCount || 0;

                // Re-read on every state change, not only on reset: editing an
                // existing issue fills these fields after the panel is already
                // open, and the Save gate reads the date.
                this.syncRemarks();
                this.syncIssueDate();

                if (d.reset && !d.editing) this.offerDraft();

                // The moment a PO unlocks the rest of the form, bring it into
                // view — the sections below were greyed a second ago, and on a
                // laptop they sit under the fold.
                if (this.hasPo && !hadPo && !d.reset) this.revealItems();
            });

            this.syncRemarks();
            this.syncIssueDate();
        },

        // --- Section gating ---------------------------------------------------
        /** Scrolls to Issue Quantities without taking focus from the search. */
        revealItems() {
            this.$nextTick(() => {
                const el = this.$el.querySelector('[data-bi-section="2"]');
                if (!el) return;
                const motion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                el.scrollIntoView({ behavior: motion ? 'auto' : 'smooth', block: 'start' });
            });
        },

        /**
         * Why Save is not available yet, or null when it is.
         *
         * The same three conditions the server enforces on store/update: a
         * booking, at least one item, and a quantity on it. Stock integrity is
         * a hard rule, so an item over its balance blocks here too. None of the
         * rules changed with the single-page layout — only where they surface.
         */
        saveBlocker() {
            if (!this.hasPo) return 'Select a PO first.';
            // Edit mode loads its one row asynchronously, so an empty list there
            // means "not fetched yet", not "nothing chosen".
            if (!this.itemCount && !this.editing) return 'This PO has no items to issue.';
            if (this.blocked) return 'Some items exceed available stock.';
            // The whole PO is on screen, so having rows is not the same as
            // having an issue: what makes it one is a quantity on a row.
            if (!this.withQty && !this.editing) return 'Enter a quantity on at least one item.';
            if (!this.issueDate) return 'Issue Date is required.';
            return null;
        },

        syncIssueDate() {
            const el = document.getElementById('biIssueDate');
            this.issueDate = el ? el.value : '';
        },

        canSave() {
            return this.saveBlocker() === null;
        },

        /** The left-hand line of the sticky bar. */
        statusText() {
            if (!this.hasPo) return 'No PO selected';
            if (!this.itemCount) return 'Loading items…';
            const blocker = this.saveBlocker();
            if (blocker) return blocker;
            return 'Ready to save ' + this.withQty + (this.withQty === 1 ? ' item' : ' items');
        },

        // --- Closing ----------------------------------------------------------
        /** Cancel: confirmed first when the form carries anything worth losing. */
        requestClose() {
            // Loading a PO is not "worth losing" on its own — the whole booking
            // lands in the matrix the moment one is chosen, so what counts as
            // entered work is a quantity typed, not a row on screen.
            const dirty = this.withQty > 0 || DRAFT_FIELDS.some((id) => {
                const el = document.getElementById(id);
                return el && el.value && !(id === 'biIssueNo' && el.classList.contains('bi-suggested'));
            });

            if (dirty && !window.confirm('Discard this issue? Anything entered will be lost.')) return;

            // Two shells, two ways to leave: the panel closes over the history
            // it was opened from, the page goes back to that history.
            const panelEl = document.getElementById('biPanel');
            if (panelEl && window.bootstrap) {
                window.bootstrap.Offcanvas.getOrCreateInstance(panelEl).hide();
                return;
            }

            const cfg = document.getElementById('bi-config');
            let index = '';
            try { index = (JSON.parse(cfg.textContent).routes || {}).index || ''; } catch (e) { /* fall through */ }
            window.location.href = index || document.referrer || '/';
        },

        // Enter must not submit from a single-line field: on one page every
        // input sits in the same form, so a stray Enter in the PO search would
        // otherwise save the whole issue. The textarea keeps its newlines and
        // the Save button itself still works.
        onKeydown(e) {
            if (e.key !== 'Enter') return;
            const tag = (e.target.tagName || '').toLowerCase();
            if (tag === 'textarea' || e.target.type === 'submit') return;
            e.preventDefault();
        },

        // --- Remarks counter --------------------------------------------------
        syncRemarks() {
            const el = document.getElementById('biRemarks');
            this.remarksLength = el ? el.value.length : 0;
        },

        // --- Toasts -----------------------------------------------------------
        toast(message, type = 'info') {
            const id = ++this.toastSeq;
            this.toasts.push({ id, message, type });
            setTimeout(() => this.dismiss(id), 5000);
        },

        dismiss(id) {
            this.toasts = this.toasts.filter((t) => t.id !== id);
        },

        toastClass(type) {
            return {
                success: 'border-success text-success-emphasis',
                error: 'border-danger text-danger-emphasis',
                info: 'border-primary text-primary-emphasis',
            }[type] || 'border-primary text-primary-emphasis';
        },

        toastIcon(type) {
            return {
                success: 'bi-check-circle-fill',
                error: 'bi-exclamation-triangle-fill',
                info: 'bi-info-circle-fill',
            }[type] || 'bi-info-circle-fill';
        },

        // --- Draft autosave ---------------------------------------------------
        queueDraft() {
            this.syncRemarks();
            clearTimeout(this.draftTimer);
            this.draftTimer = setTimeout(() => this.saveDraft(), DRAFT_DEBOUNCE_MS);
        },

        saveDraft() {
            if (this.editing) return;   // never shadow a real record being corrected

            const draft = {};
            let filled = false;
            DRAFT_FIELDS.forEach((id) => {
                const el = document.getElementById(id);
                if (!el) return;
                draft[id] = el.value;
                // The auto-suggested Issue No is not the user's own input, so a
                // panel carrying only that counts as empty.
                if (el.value && !(id === 'biIssueNo' && el.classList.contains('bi-suggested'))) filled = true;
            });

            try {
                if (filled) localStorage.setItem(DRAFT_KEY, JSON.stringify({ at: Date.now(), draft }));
                else localStorage.removeItem(DRAFT_KEY);
            } catch (e) {
                // Private mode / quota. Losing a draft is not worth an error.
            }
        },

        readDraft() {
            try {
                const raw = localStorage.getItem(DRAFT_KEY);
                return raw ? JSON.parse(raw) : null;
            } catch (e) {
                return null;
            }
        },

        /** Restoring is offered, never automatic — the panel may be a fresh entry. */
        offerDraft() {
            const stored = this.readDraft();
            if (!stored || !stored.draft) return;
            this.toast('Unsaved draft found.', 'info');
            this.draftAvailable = true;
        },

        draftAvailable: false,

        restoreDraft() {
            const stored = this.readDraft();
            if (!stored || !stored.draft) return;

            Object.entries(stored.draft).forEach(([id, value]) => {
                const el = document.getElementById(id);
                if (!el || !value) return;
                el.value = value;
                if (id === 'biIssueNo') el.classList.remove('bi-suggested');
            });

            this.draftAvailable = false;
            this.syncRemarks();
            this.toast('Draft restored.', 'success');
        },

        discardDraft() {
            try { localStorage.removeItem(DRAFT_KEY); } catch (e) { /* nothing to do */ }
            this.draftAvailable = false;
        },
    }));
}
