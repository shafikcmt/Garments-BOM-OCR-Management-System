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
    </style>
