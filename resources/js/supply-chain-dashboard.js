// Supply Chain dashboard charts (Chart.js). The page hands its figures over in a
// <script type="application/json" id="sc-chart-data"> block, so nothing here
// talks to the server.
import {
    Chart,
    BarController,
    BarElement,
    CategoryScale,
    LinearScale,
    Tooltip,
    Legend,
} from 'chart.js';

Chart.register(BarController, BarElement, CategoryScale, LinearScale, Tooltip, Legend);

const css = getComputedStyle(document.documentElement);
const token = (name, fallback) => (css.getPropertyValue(name) || '').trim() || fallback;

const palette = {
    primary: token('--bs-primary', '#2563eb'),
    muted: token('--bs-secondary-color', '#64748b'),
    grid: 'rgba(148, 163, 184, 0.18)',
};

// Pipeline stages, from "not started" (red) to "done" (green): same colours as the
// Merchandising dashboard, so the two read alike.
const stageColours = ['#ef4444', '#f59e0b', '#eab308', '#3b82f6', '#10b981', '#059669'];
// Pending kinds: booking, PI, payment.
const kindColours = { booking: '#f59e0b', pi: '#7c3aed', payment: '#dc2626', paid: '#3b82f6' };

const number = (v) => Number(v || 0).toLocaleString();

Chart.defaults.font.family = token('--bs-body-font-family', 'Inter, system-ui, sans-serif');
Chart.defaults.font.size = 12;
Chart.defaults.color = palette.muted;
Chart.defaults.plugins.tooltip.padding = 10;
Chart.defaults.plugins.tooltip.cornerRadius = 8;
Chart.defaults.plugins.tooltip.callbacks.label = (ctx) => ` ${ctx.dataset.label || ctx.label}: ${number(ctx.parsed.x ?? ctx.parsed.y ?? ctx.parsed)}`;

function mount(id, config) {
    const el = document.getElementById(id);
    if (el) {
        new Chart(el, config);
    }
}

// One horizontal stacked bar per label, one segment per kind.
function stackedBars(id, labels, series, animation) {
    mount(id, {
        type: 'bar',
        data: {
            labels,
            datasets: series.map(([label, data, colour]) => ({
                label, data, backgroundColor: colour, borderRadius: 4, barThickness: 18,
            })),
        },
        options: {
            indexAxis: 'y',
            animation,
            maintainAspectRatio: false,
            plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8, padding: 14 } } },
            scales: {
                x: { stacked: true, beginAtZero: true, grid: { color: palette.grid }, ticks: { precision: 0 } },
                y: { stacked: true, grid: { display: false } },
            },
        },
    });
}

function init() {
    const source = document.getElementById('sc-chart-data');
    if (!source) {
        return;
    }

    const data = JSON.parse(source.textContent);
    const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    // Bars grow in one after another; skipped for people who ask for less motion.
    const animation = reduced ? false : { duration: 800, easing: 'easeOutQuart', delay: (ctx) => ctx.dataIndex * 60 };

    // Material pipeline: how many material lines sit at each stage.
    mount('sc-pipeline', {
        type: 'bar',
        data: {
            labels: Object.keys(data.pipeline),
            datasets: [{
                label: 'Material lines',
                data: Object.values(data.pipeline),
                backgroundColor: stageColours,
                borderRadius: 6,
                barThickness: 22,
            }],
        },
        options: {
            indexAxis: 'y',
            animation,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                x: { beginAtZero: true, grid: { color: palette.grid }, ticks: { precision: 0 } },
                y: { grid: { display: false } },
            },
        },
    });

    // Open work per buyer.
    stackedBars('sc-buyers', data.byBuyer.labels, [
        ['Booking pending', data.byBuyer.booking, kindColours.booking],
        ['PI pending', data.byBuyer.pi, kindColours.pi],
        ['Payment pending', data.byBuyer.payment, kindColours.payment],
    ], animation);

    // Lines waiting on a vendor.
    stackedBars('sc-vendors', data.byVendor.labels, [
        ['PI pending', data.byVendor.pi, kindColours.pi],
        ['Payment pending', data.byVendor.payment, kindColours.payment],
        ['Paid, goods awaited', data.byVendor.paid, kindColours.paid],
    ], animation);

    // Lines still to arrive, by Committed ETA month; the overdue bar is red.
    mount('sc-eta', {
        type: 'bar',
        data: {
            labels: data.etaMonths.labels,
            datasets: [{
                label: 'Material lines',
                data: data.etaMonths.values,
                backgroundColor: data.etaMonths.labels.map((_, i) => (i === 0 ? '#ef4444' : 'rgba(37, 99, 235, 0.85)')),
                borderRadius: 8,
                maxBarThickness: 46,
            }],
        },
        options: {
            animation,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                x: { grid: { display: false } },
                y: { beginAtZero: true, grid: { color: palette.grid }, ticks: { precision: 0 } },
            },
        },
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}
