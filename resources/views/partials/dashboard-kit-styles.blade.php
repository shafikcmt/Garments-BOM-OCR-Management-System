{{-- Shared look of the Merchandising and Supply Chain dashboards (KPI tiles, chips, charts, lists). --}}
<style>
    .md-dash { --md-primary:#2563eb; --md-info:#0891b2; --md-warning:#d97706; --md-purple:#7c3aed; --md-danger:#dc2626; --md-success:#16a34a; }
    .md-kpi {
        display:flex; flex-direction:column; gap:.35rem; padding:1rem 1.1rem; border-radius:var(--gx-radius, 14px);
        background:var(--bs-body-bg, #fff); border:1px solid rgba(148,163,184,.25);
        box-shadow:0 1px 2px rgba(15,23,42,.04); text-decoration:none; color:inherit; position:relative; overflow:hidden;
        transition:transform .15s ease, box-shadow .15s ease;
    }
    .md-kpi::before { content:""; position:absolute; inset:0 auto 0 0; width:4px; background:var(--md-tone); }
    a.md-kpi:hover { transform:translateY(-2px); box-shadow:0 8px 20px rgba(15,23,42,.08); color:inherit; }
    .md-kpi--primary { --md-tone:var(--md-primary); } .md-kpi--info { --md-tone:var(--md-info); }
    .md-kpi--warning { --md-tone:var(--md-warning); } .md-kpi--purple { --md-tone:var(--md-purple); }
    .md-kpi--danger { --md-tone:var(--md-danger); } .md-kpi--success { --md-tone:var(--md-success); }
    .md-kpi-head { display:flex; align-items:center; gap:.55rem; min-height:2.3rem; }
    .md-kpi-icon {
        width:32px; height:32px; border-radius:9px; display:inline-flex; align-items:center; justify-content:center;
        color:var(--md-tone); background:color-mix(in srgb, var(--md-tone) 12%, transparent); font-size:1rem;
    }
    .md-kpi-label { font-size:.78rem; font-weight:600; color:var(--bs-secondary-color, #64748b); text-transform:uppercase; letter-spacing:.03em; }
    .md-kpi-value { font-size:1.75rem; font-weight:700; line-height:1.1; color:var(--bs-emphasis-color, #0f172a); }
    .md-kpi-sub { font-size:.78rem; color:var(--bs-secondary-color, #64748b); }

    .md-chip {
        display:flex; align-items:center; gap:.5rem; padding:.65rem .85rem; border-radius:12px; text-decoration:none;
        background:var(--bs-body-bg, #fff); border:1px solid rgba(148,163,184,.25); color:var(--bs-secondary-color, #64748b); height:100%;
    }
    .md-chip i { color:var(--md-tone); font-size:1.05rem; }
    .md-chip-value { font-weight:700; font-size:1.1rem; color:var(--bs-emphasis-color, #0f172a); }
    .md-chip-label { font-size:.8rem; line-height:1.2; }
    .md-chip.is-hot { background:color-mix(in srgb, var(--md-tone) 7%, var(--bs-body-bg, #fff)); border-color:color-mix(in srgb, var(--md-tone) 35%, transparent); }
    a.md-chip:hover { border-color:var(--md-tone); }
    .md-chip--danger { --md-tone:var(--md-danger); } .md-chip--warning { --md-tone:var(--md-warning); }
    .md-chip--purple { --md-tone:var(--md-purple); } .md-chip--info { --md-tone:var(--md-info); } .md-chip--primary { --md-tone:var(--md-primary); }

    .md-chart { position:relative; }
    .md-chart--pipeline { height:240px; }
    .md-chart--doughnut { height:300px; }
    .md-chart--bars { height:260px; }
    .md-doughnut-center { position:absolute; left:0; right:0; top:38%; text-align:center; pointer-events:none; }
    .md-doughnut-value { font-size:1.4rem; font-weight:700; color:var(--bs-emphasis-color, #0f172a); }
    .md-doughnut-label { font-size:.75rem; color:var(--bs-secondary-color, #64748b); }
    .md-progress-row { display:grid; grid-template-columns:repeat(3, 1fr); gap:1rem; margin-top:1rem; }
    .md-bar--primary { background:var(--md-primary); } .md-bar--purple { background:var(--md-purple); } .md-bar--success { background:var(--md-success); }

    /* The app's tables keep a 760px minimum for wide lists; this one sits in a half-width card. */
    .md-dash .md-table { min-width: 0 !important; }
    .md-table th { font-size:.72rem; text-transform:uppercase; letter-spacing:.04em; color:var(--bs-secondary-color, #64748b); font-weight:600; padding:.65rem 1rem; }
    .md-table td { padding:.65rem 1rem; }
    .md-attention li { display:flex; justify-content:space-between; align-items:center; gap:.75rem; padding:.75rem 1rem; border-bottom:1px solid rgba(148,163,184,.2); }
    .md-attention li:last-child { border-bottom:0; }
    .md-empty { display:flex; flex-direction:column; align-items:center; gap:.4rem; padding:2.5rem 1rem; color:var(--bs-secondary-color, #64748b); font-size:.9rem; }
    .md-empty i { font-size:1.6rem; }
    @media (max-width: 575.98px) { .md-progress-row { grid-template-columns:1fr; } }
</style>
