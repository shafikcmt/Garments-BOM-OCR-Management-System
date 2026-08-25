<!doctype html>
<html>
<head><meta charset="utf-8"></head>
<body>
{{-- Excel sheet: same header information as the PDF, then the shared table.
     colspan matches the real column count so the letterhead merges across the
     whole table — FormatsStoreSheet reads those merges to style the block. --}}
@php $span = $columns->count() + 1; @endphp
<table>
    <tr><td colspan="{{ $span }}"><strong>HUMANA APPARELS PVT. LTD.</strong></td></tr>
    <tr><td colspan="{{ $span }}"><strong>{{ $title }}</strong></td></tr>
    <tr><td colspan="{{ $span }}">@include('merchant.reports._filter-summary')</td></tr>
    <tr><td colspan="{{ $span }}">Printed On: {{ now()->format('d M Y, h:i A') }}</td></tr>
    <tr><td colspan="{{ $span }}">Every Merchandising column is included. Columns owned by other departments are not part of this report.</td></tr>
    <tr><td colspan="{{ $span }}"></td></tr>
</table>

@include('merchant.reports._table')
</body>
</html>
