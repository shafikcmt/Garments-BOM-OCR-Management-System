{{-- Excel body for MaterialReceivingExport. Column order and values come from
     the shared register partial, so the sheet and the PDF are the same report.
     'excel' mode emits numbers raw so Excel keeps the cells numeric. --}}
@include('store.material-stock._receivings-report-table', [
    'receivings' => $receivings,
    'docs' => $docs,
    'mode' => 'excel',
])
