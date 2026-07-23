/**
 * Alpine shell for the New Bulk Issue panel: step navigation, toasts, the
 * remarks counter and draft autosave.
 *
 * Deliberately narrow. The PO search, the item picker and the stock-balance
 * validation stay in bulk-issue-table.js and keep owning their own DOM — two
 * frameworks writing the same nodes is how these panels rot. The vanilla module
 * publishes what it knows on a `bi:state` event and this component only reads
 * it, so neither side reaches into the other.
 *
 * Steps use x-show rather than x-if on purpose: hidden inputs must stay in the
 * form so a submit from step 3 still carries the values entered on steps 1-2.
 */

const DRAFT_KEY = 'bulkIssueDraft';
const DRAFT_DEBOUNCE_MS = 3000;

// Only the fields the user types. The PO and its items are server-resolved, so
// restoring them from a stale draft could attach an issue to the wrong BOM row.
const DRAFT_FIELDS = ['biSection', 'biPerson', 'biReqNo', 'biIssueNo', 'biRemarks'];

export function registerBulkIssueWizard(Alpine) {
    Alpine.data('bulkIssueWizard', () => ({
        step: 1,
        lastStep: 3,

        // Mirrored from the vanilla module via bi:state.
        hasPo: false,
        itemCount: 0,
        blocked: false,
        editing: false,

        remarksLength: 0,
        remarksMax: 1000,
        toasts: [],
        toastSeq: 0,
        draftTimer: null,

        init() {
            this.$el.addEventListener('bi:state', (e) => {
                const d = e.detail || {};
                this.hasPo = !!d.hasPo;
                this.itemCount = d.itemCount || 0;
                this.blocked = !!d.blocked;
                this.editing = !!d.editing;

                // Opening the panel resets it to the first step; editing an
                // existing issue skips the PO picker and starts on step 2, the
                // quantities it is there to correct.
                if (d.reset) {
                    this.step = d.editing ? 2 : 1;
                    this.syncRemarks();
                    if (!d.editing) this.offerDraft();
                }
            });

            this.$watch('step', () => {
                // Move focus to the newly shown step so keyboard and screen
                // reader users are not left behind on a hidden panel.
                this.$nextTick(() => {
                    const target = this.$el.querySelector('[data-bi-step="' + this.step + '"] [autofocus], ' +
                        '[data-bi-step="' + this.step + '"] input:not([type=hidden]), ' +
                        '[data-bi-step="' + this.step + '"] select');
                    if (target) target.focus({ preventScroll: true });
                });
            });

            this.syncRemarks();
        },

        // --- Step navigation --------------------------------------------------
        stepTitle(n) {
            return { 1: 'Select PO', 2: 'Issue Quantities', 3: 'Indent Details' }[n];
        },

        /**
         * Why the current step cannot be left yet, or null when it can.
         *
         * Quantities sit ahead of the indent header so the stock balance gates
         * the flow early: an issue the ledger cannot cover is stopped here,
         * before anyone types indent details for it. The rules themselves are
         * unchanged — the vanilla module still owns the per-item check and the
         * server re-runs it on save; this only decides when they are enforced.
         */
        blockerFor(step) {
            if (step === 1 && !this.hasPo) return 'Select a PO, PI or Invoice first.';
            if (step === 2) {
                // Edit mode loads its one row asynchronously, so an empty list
                // there means "not fetched yet", not "nothing chosen".
                if (!this.itemCount && !this.editing) return 'Select at least one item to issue.';
                if (this.blocked) return 'One or more items exceed the available stock. Fix the quantities to continue.';
            }
            if (step === 3) {
                const date = document.getElementById('biIssueDate');
                if (date && !date.value) return 'Issue Date is required.';
            }
            return null;
        },

        next() {
            const blocker = this.blockerFor(this.step);
            if (blocker) {
                this.toast(blocker, 'error');
                this.shake();
                return;
            }
            if (this.step < this.lastStep) this.step += 1;
        },

        prev() {
            if (this.step > 1) this.step -= 1;
        },

        /** Jumping via the indicator, but never past an unmet requirement. */
        goTo(n) {
            if (n === this.step) return;
            if (n < this.step) { this.step = n; return; }

            for (let s = this.step; s < n; s++) {
                const blocker = this.blockerFor(s);
                if (blocker) {
                    this.toast(blocker, 'error');
                    this.shake();
                    return;
                }
            }
            this.step = n;
        },

        shake() {
            const el = this.$el.querySelector('[data-bi-step="' + this.step + '"]');
            if (!el || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
            el.classList.remove('bi-shake');
            void el.offsetWidth;   // restart the animation
            el.classList.add('bi-shake');
        },

        // Enter advances, except inside a textarea or on the final step where it
        // would race the form's own submit handling.
        onKeydown(e) {
            if (e.key !== 'Enter') return;
            const tag = (e.target.tagName || '').toLowerCase();
            if (tag === 'textarea' || e.target.type === 'submit') return;
            if (this.step >= this.lastStep) return;
            e.preventDefault();
            this.next();
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
            this.toast('Unsaved details from an earlier entry are available.', 'info');
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
