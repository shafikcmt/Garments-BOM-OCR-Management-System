@extends('layouts.app')

@section('title', 'Booking Format - ' . $bookingPo->po_no)

@php
    $revisionNo = max(0, (int) (($bookingData['revision_no'] ?? 0)));
@endphp

@section('styles')
<style>
    .booking-format-wrap {
        background: #f4f8fb;
        min-height: calc(100vh - 110px);
    }
    .booking-format-shell {
        max-width: 980px;
        margin: 0 auto;
    }
</style>
@endsection

@section('content')
<div class="booking-format-wrap p-2 p-md-3">
    <div class="booking-format-shell">
        <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap mb-3">
            <div>
                <h4 class="mb-1 fw-bold text-primary">Booking Format</h4>
                <div class="small text-muted">PO {{ $bookingPo->po_no }}@if($revisionNo > 0) · R-{{ $revisionNo }}@endif. Generated PO is read-only.</div>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <a href="{{ route('supply_chain.bookings.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</a>
                <a href="{{ route('supply_chain.bookings.print', $bookingPo) }}" target="_blank" class="btn btn-outline-primary btn-sm"><i class="bi bi-printer me-1"></i>Print</a>
                <a href="{{ route('supply_chain.bookings.download', $bookingPo) }}" target="_blank" class="btn btn-outline-success btn-sm"><i class="bi bi-filetype-pdf me-1"></i>Download PDF</a>
                <a href="{{ route('supply_chain.bookings.download_excel', $bookingPo) }}" target="_blank" class="btn btn-outline-success btn-sm"><i class="bi bi-file-earmark-excel me-1"></i>Export Excel</a>
            </div>
        </div>

        <div id="bookingShowAlert"></div>
        <div id="bookingShowPreviewContent">
            @include('supply-chain.bookings.partials.preview', [
                'bookingPo' => $bookingPo,
                'bookingData' => $bookingData,
                'previewMode' => false,
                'generateUrl' => null,
                'instructionOptions' => $instructionOptions ?? collect(),
                'deliveryDestinationOptions' => $deliveryDestinationOptions ?? collect(),
            ])
        </div>
    </div>
</div>
@endsection
