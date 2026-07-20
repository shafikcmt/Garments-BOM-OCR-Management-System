/**
 * Stops a form being submitted twice.
 *
 * Two clicks on "Save" here means two receivings or two payment requests, so
 * the button disables itself once its form is actually submitting. Binding to
 * the form's submit event rather than the button's click means browser
 * validation still gets to reject an incomplete form first — disabling on
 * click would leave the user stuck with a dead button on a form that never
 * went anywhere.
 */
export function initSubmitButtons() {
    document.querySelectorAll('[data-submit-button]').forEach((button) => {
        const form = button.form;

        if (!form) {
            return;
        }

        form.addEventListener('submit', () => {
            // The browser skips submit entirely if validation fails, so
            // reaching here means the form really is on its way.
            const label = button.querySelector('[data-submit-label]');

            if (label) {
                label.innerHTML =
                    '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>' +
                    button.dataset.busyLabel;
            }

            // Deferred so the button's own value still posts with the form.
            window.setTimeout(() => {
                button.disabled = true;
            }, 0);
        });
    });
}
