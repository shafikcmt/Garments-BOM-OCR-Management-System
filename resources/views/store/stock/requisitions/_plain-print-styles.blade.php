    {{-- The office prints on a plain black-and-white printer: black ink on
         light grey, in step with the Excel. These override the shared
         navy styles for this document only. --}}
    <style>
        body, .report-table, .sign-meta, .footer, .note { color: #000; }
        .head-block td, .report-table th, .report-table td, .sign-line { border-color: #000; }
        .head-block .label { background: #ededed; }
        .report-table th { background: #ededed; color: #000; }
        .report-table tfoot .total td { background: #ededed; border-color: #000; }
        .report-table tr.cat-sum td { background: #ededed; border-color: #000; }
        .report-table .src, .report-table .empty { color: #000; }
        .report-table td { padding: 5px 2px; }

        /* Clear of the printer's unprintable edge, so the left and right
           borders are not cut. */
        @page { size: A4 landscape; margin: 28px 36px 32px; }

        /* The top block: label columns wide enough for
           "Checked by (Finance & Commercial Head)" on one line. */
        .head-block .label { width: 20%; white-space: nowrap; }
        .head-block .value { width: 30%; }

        /* Short values stay on one line. */
        .report-table .w-uom { width: 5.2%; }
        .report-table td.one-line { white-space: nowrap; }
        .report-table .w-type { width: 4.4%; }

        /* Room between the last item and the signatures. */
        .sign { margin-top: 48px; }
    </style>
