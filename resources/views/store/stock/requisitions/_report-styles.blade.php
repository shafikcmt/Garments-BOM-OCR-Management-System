{{--
    Print styling shared by the monthly Purchase Requisition report and the
    single-requisition document, so the two downloads look like one system.

    Kept as a partial rather than duplicated: the two PDFs print the same table,
    and a width tuned on one has to hold on the other.
--}}
<style>
    @page { size: A4 landscape; margin: 14px 16px 26px; }
    * { box-sizing: border-box; }
    body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 7.5px; color: #000b6f; margin: 0; }
    table { width: 100%; border-collapse: collapse; }

    .top td { border: 0; vertical-align: top; padding: 0; }
    .company { font-size: 12px; letter-spacing: .06em; font-weight: 800; text-align: center; }
    .title { text-align: center; font-size: 16px; font-weight: 800; line-height: 1.4; }
    .date-area { text-align: right; font-size: 8px; font-weight: 700; line-height: 1.7; }

    /* Header block — rows 3 to 8 of the reference sheet. */
    .head-block { margin: 8px 0 6px; font-size: 8px; }
    .head-block td { border: 1px solid #33439e; padding: 3px 5px; }
    .head-block .label { font-weight: 700; width: 13%; background: #eef2ff; }
    .head-block .value { width: 37%; }

    .section-title {
        margin: 10px 0 4px; font-size: 10px; font-weight: 800;
        letter-spacing: .04em; text-transform: uppercase;
    }

    /* 21 columns on A4 landscape: fixed layout so long item names wrap
       inside their cell instead of pushing columns off the page. */
    .report-table { table-layout: fixed; color: #111827; margin-bottom: 4px; }
    .report-table th {
        background: #000b6f; color: #fff; border: 1px solid #33439e;
        padding: 3px 2px; font-size: 6.4px; font-weight: 700;
        text-align: center; vertical-align: middle; word-wrap: break-word;
    }
    .report-table td {
        border: 1px solid #c7d2fe; padding: 3px 2px; font-size: 6.6px;
        vertical-align: top; word-wrap: break-word;
    }
    .report-table td.num { text-align: right; }
    .report-table th.num { text-align: center; }
    .report-table td.nowrap { white-space: nowrap; }
    .report-table .src { color: #64748b; font-size: 5.8px; margin-top: 1px; }
    .report-table .empty { text-align: center; padding: 10px; color: #64748b; }
    .report-table tfoot .total td { background: #eef2ff; font-weight: 800; border-color: #33439e; }
    .report-table .total-label { text-align: right; }

    .w-sl { width: 2.2%; }
    .w-item { width: 11%; }
    .w-uom { width: 3%; }
    .w-spec { width: 7%; }
    .w-type { width: 4%; }
    .w-dept { width: 8%; }
    .w-remarks { width: 6%; }

    .sign { margin-top: 22px; }
    .sign td { border: 0; width: 33.33%; text-align: center; padding: 0 10px; font-size: 8px; }
    .sign-title { font-weight: 700; margin-bottom: 26px; }
    .sign-line { border-top: 1px solid #33439e; padding-top: 3px; }
    .sign-meta { font-size: 7px; color: #33439e; }

    .footer { margin-top: 10px; font-size: 7px; color: #33439e; }
    .footer .right { float: right; }

    /* Each category starts on a fresh page so a section is never split
       across a break in the middle of its header. */
    .page-break { page-break-before: always; }

    /* The remarks note under a single requisition's table. */
    .note { margin-top: 6px; font-size: 7px; color: #33439e; }
</style>
