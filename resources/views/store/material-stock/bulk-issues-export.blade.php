{{-- Excel body for BulkIssueExport. Column order matches the Excel "Bulk
     Issuing" register. Numbers are emitted raw so Excel keeps them numeric. --}}
@php
    $num = fn ($v) => rtrim(rtrim(number_format((float) $v, 4, '.', ''), '0'), '.');
@endphp
<table>
    <thead>
        <tr>
            <th>Issue Date</th>
            <th>Issue No</th>
            <th>Indent Section</th>
            <th>Indent Person</th>
            <th>Requisition No</th>
            <th>Season</th>
            <th>Buyer Name</th>
            <th>Style Number</th>
            <th>PO Number</th>
            <th>GMTS Color Name</th>
            <th>Material Name</th>
            <th>Material Description</th>
            <th>Art. No</th>
            <th>SAP Code</th>
            <th>Material Color</th>
            <th>Size</th>
            <th>Unit</th>
            <th>Bulk Issued Qty</th>
            <th>Sample Issued Qty</th>
            <th>Liability Stock Qty</th>
            <th>Dead Stock Qty</th>
            <th>Remarks</th>
        </tr>
    </thead>
    <tbody>
        @foreach($issues as $i)
            <tr>
                <td>{{ optional($i->issue_date)->format('d-M-Y') }}</td>
                <td>{{ $i->issue_no }}</td>
                <td>{{ $i->indent_section }}</td>
                <td>{{ $i->indent_person }}</td>
                <td>{{ $i->requisition_number }}</td>
                <td>{{ $i->season_name }}</td>
                <td>{{ $i->buyer_name }}</td>
                <td>{{ $i->style_name }}</td>
                <td>{{ $i->po_no }}</td>
                <td>{{ $i->gmts_color_name }}</td>
                <td>{{ $i->material_name }}</td>
                <td>{{ $i->material_description }}</td>
                <td>{{ $i->art_no }}</td>
                <td>{{ $i->sap_code }}</td>
                <td>{{ $i->material_color }}</td>
                <td>{{ $i->size }}</td>
                <td>{{ $i->uom }}</td>
                <td>{{ $num($i->bulk_qty) }}</td>
                <td>{{ $num($i->sample_qty) }}</td>
                <td>{{ $num($i->liability_qty) }}</td>
                <td>{{ $num($i->dead_qty) }}</td>
                <td>{{ $i->remarks }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
