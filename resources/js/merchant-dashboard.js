// Merchandising dashboard charts (Chart.js). The page hands its figures over
// in a <script type="application/json" id="md-chart-data"> block, so nothing
// here talks to the server.
import {
    Chart,
    BarController,
    BarElement,
    LineController,
    LineElement,
    PointElement,
    DoughnutController,
    ArcElement,
    CategoryScale,
    LinearScale,
    Tooltip,
    Legend,
    Filler,
} from 'chart.js';

Chart.register(
    BarController, BarElement, LineController, LineElement, PointElement,
    DoughnutController, ArcElement, CategoryScale, LinearScale, Tooltip, Legend, Filler,
);

const css = getComputedStyle(document.documentElement);
const token = (name, fallback) => (css.getPropertyValue(name) || '').trim() || fallback;

const palette = {
    primary: token('--bs-primary', '#2563eb'),
    success: token('--bs-success', '#16a34a'),
    warning: token('--bs-warning', '#f59e0b'),
    danger: token('--bs-danger', '#dc2626'),
    info: token('--bs-info', '#0891b2'),
    muted: token('--bs-secondary-color', '#64748b'),
    grid: 'rgba(148, 163, 184, 0.18)',
};

// Stage colours, from "not started" (red) to "done" (green).
const stageColours = ['#ef4444', '#f59e0b', '#eab308', '#3b82f6', '#10b981', '#059669'];
const buyerColours = ['#2563eb', '#7c3aed', '#0891b2', '#16a34a', '#f59e0b', '#db2777', '#94a3b8'];

const number = (v) => Number(v || 0).toLocaleString();

Chart.defaults.font.family = token('--bs-body-font-family', 'Inter, system-ui, sans-serif');
Chart.defaults.font.size = 12;
Chart.defaults.color = palette.muted;
Chart.defaults.plugins.tooltip.padding = 10;
Chart.defaults.plugins.tooltip.cornerRadius = 8;
Chart.defaults.plugins.tooltip.callbacks.label = (ctx) => ` ${ctx.label || ctx.dataset.label}: ${number(ctx.parsed.x ?? ctx.parsed.y ?? ctx.parsed)}`;

function mount(id, config) {
    const el = document.getElementById(id);
    if (el) {
        new Chart(el, config);
    }
}

function init() {
    const source = document.getElementById('md-chart-data');
    if (!source) {
        return;
    }

    const data = JSON.parse(source.textContent);
    const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const animation = reduced ? false : { duration: 700 };

    // Material pipeline — how many material lines sit at each stage.
    mount('md-pipeline', {
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

    // Order quantity by buyer.
    mount('md-buyers', {
        type: 'doughnut',
        data: {
            labels: data.byBuyer.labels,
            datasets: [{
                data: data.byBuyer.values,
                backgroundColor: buyerColours,
                borderWidth: 2,
                borderColor: token('--bs-body-bg', '#fff'),
                hoverOffset: 6,
            }],
        },
        options: {
            animation,
            maintainAspectRatio: false,
            cutout: '68%',
            plugins: {
                legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8, padding: 14 } },
                tooltip: { callbacks: { label: (ctx) => ` ${ctx.label}: ${number(ctx.parsed)} pcs` } },
            },
        },
    });

    // Order quantity by ship month.
    mount('md-ship-months', {
        type: 'bar',
        data: {
            labels: data.shipMonths.labels,
            datasets: [{
                label: 'Order qty (pcs)',
                data: data.shipMonths.values,
                backgroundColor: 'rgba(37, 99, 235, 0.85)',
                hoverBackgroundColor: palette.primary,
                borderRadius: 8,
                maxBarThickness: 46,
            }],
        },
        options: {
            animation,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: { callbacks: { label: (ctx) => ` ${number(ctx.parsed.y)} pcs` } },
            },
            scales: {
                x: { grid: { display: false } },
                y: { beginAtZero: true, grid: { color: palette.grid }, ticks: { callback: (v) => number(v) } },
            },
        },
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}
