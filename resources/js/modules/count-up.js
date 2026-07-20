/**
 * Counts a number up to its final value when it scrolls into view.
 *
 * Progressive enhancement only: the element already contains the final,
 * correctly formatted number server-side, so with JS disabled — or with
 * reduced motion requested — the figure is simply shown as-is. A dashboard
 * number must never depend on a script having run.
 */

const DURATION = 900;

function formatLike(sample, value) {
    // Mirror the server's thousands separators; keep any decimals it used.
    const decimals = (sample.split('.')[1] || '').length;

    return value.toLocaleString(undefined, {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
    });
}

function animate(el) {
    const target = parseFloat(el.dataset.countTo);

    if (!Number.isFinite(target)) {
        return;
    }

    const sample = el.textContent.trim();
    const start = performance.now();

    function step(now) {
        const progress = Math.min((now - start) / DURATION, 1);
        // easeOutCubic — fast first, settles gently on the final figure.
        const eased = 1 - Math.pow(1 - progress, 3);

        el.textContent = formatLike(sample, target * eased);

        if (progress < 1) {
            requestAnimationFrame(step);
        } else {
            el.textContent = sample;
        }
    }

    requestAnimationFrame(step);
}

export function initCountUp() {
    const targets = document.querySelectorAll('[data-count-to]');

    if (!targets.length) {
        return;
    }

    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        return;
    }

    if (!('IntersectionObserver' in window)) {
        targets.forEach(animate);

        return;
    }

    const observer = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            if (!entry.isIntersecting) {
                return;
            }

            animate(entry.target);
            observer.unobserve(entry.target);
        });
    }, { threshold: 0.4 });

    targets.forEach((el) => observer.observe(el));
}
