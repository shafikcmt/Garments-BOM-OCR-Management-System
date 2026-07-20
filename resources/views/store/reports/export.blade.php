<!doctype html>
<html>
<head><meta charset="utf-8"></head>
<body>
{{-- Excel sheet: same header information as the PDF, then the shared table. --}}
<table>
    <tr><td colspan="11"><strong>HUMANA APPARELS PVT. LTD.</strong></td></tr>
    <tr><td colspan="11"><strong>{{ $title }}</strong></td></tr>
    <tr><td colspan="11">@include('store.reports._filter-summary')</td></tr>
    <tr><td colspan="11">Printed On: {{ now()->format('d M Y, h:i A') }}</td></tr>
    <tr><td colspan="11">Period Movement = Receive - Issue for the selected date range. Current Stock Balance is the lifetime ledger closing and ignores the date filter.</td></tr>
    <tr><td colspan="11"></td></tr>
</table>

@include('store.reports._table')
</body>
</html>
