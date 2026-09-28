{{-- Styles for the Requirement vs Issue / Stock Summary charts. Pushed once
     per page however many chart partials include it. --}}
@once
<style>
    .rvi-chart-card { background: #fff; border: 1px solid #e7ecf3; border-radius: 12px; padding: .85rem 1rem; }
    .rvi-chart-title { font-size: .8rem; font-weight: 700; color: #334155; text-transform: uppercase; letter-spacing: .03em; margin-bottom: .5rem; }
    .rvi-gauge { width: 100%; max-width: 190px; }
    .rvi-gauge-track, .rvi-gauge-fill { fill: none; stroke-width: 14; stroke-linecap: round; }
    .rvi-gauge-track { stroke: #e9eef5; }
    .rvi-gauge-fill { stroke: var(--bs-primary); }
    .rvi-gauge-value { font-size: 1.5rem; font-weight: 800; margin-top: -1.4rem; color: #0f172a; }
    .rvi-stack { display: flex; height: 12px; border-radius: 6px; overflow: hidden; background: #e9eef5; }
    .rvi-stack-part { display: block; height: 100%; }
    .rvi-legend { list-style: none; padding: 0; margin: .5rem 0 0; display: flex; flex-wrap: wrap; gap: .25rem .75rem; font-size: .78rem; }
    .rvi-dot { display: inline-block; width: 9px; height: 9px; border-radius: 50%; margin-right: .3rem; vertical-align: middle; }
    .rvi-key { font-size: .75rem; color: #64748b; margin-bottom: .4rem; }
    .rvi-bar-row { display: grid; grid-template-columns: minmax(0, 38%) 1fr auto; gap: .5rem; align-items: center; padding: .2rem 0; color: inherit; text-decoration: none; font-size: .8rem; }
    a.rvi-bar-row:hover .rvi-bar-label { text-decoration: underline; }
    .rvi-bar-label { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .rvi-bar-track { display: flex; flex-direction: column; gap: 2px; }
    .rvi-bar { display: block; height: 7px; border-radius: 4px; min-width: 2px; }
    .rvi-bar-value { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; line-height: 1.1; }
    .rvi-requested { background: var(--bs-primary); }
    .rvi-issued { background: var(--bs-success); }
    .rvi-balance { background: var(--bs-warning); }
    .rvi-pending { background: var(--bs-warning); }

    /* Issued % cell: a thin bar under the figure. */
    .rvi-pct { min-width: 88px; }
    .rvi-pct-bar { height: 4px; border-radius: 2px; background: #e9eef5; margin-top: 3px; overflow: hidden; }
    .rvi-pct-bar > span { display: block; height: 100%; background: var(--bs-success); }
    .rvi-pct.is-over .rvi-pct-bar > span { background: var(--bs-danger); }
    .rvi-pct.is-none .rvi-pct-bar > span { background: var(--bs-secondary); }
    .rvi-pct.is-over { color: var(--bs-danger); }
    .rvi-pct.is-none { color: var(--bs-secondary); }

    /* Keep the colours when someone prints the page. */
    .rvi-charts, .rvi-pct { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
</style>
@endonce
