{{-- Shipment fields. Needs $shipment and $canEdit (true on create). --}}
@php($v = fn ($k, $default = null) => old($k, $shipment->{$k} instanceof \Illuminate\Support\Carbon ? $shipment->{$k}->toDateString() : ($shipment->{$k} ?? $default)))
<div class="row g-2">
    <div class="col-6 col-md-2"><label class="form-label mb-1">Mode *</label>
        <select name="ship_mode" class="form-select form-select-sm" @disabled(! $canEdit)>@foreach(\App\Models\InboundShipment::MODES as $k => $label)<option value="{{ $k }}" @selected($v('ship_mode', 'sea') === $k)>{{ $label }}</option>@endforeach</select></div>
    <div class="col-6 col-md-3"><label class="form-label mb-1">BL / AWB no.</label><input type="text" name="bl_awb_no" class="form-control form-control-sm" maxlength="80" value="{{ $v('bl_awb_no') }}" @disabled(! $canEdit)></div>
    <div class="col-6 col-md-2"><label class="form-label mb-1">BL status</label>
        <select name="bl_status" class="form-select form-select-sm" @disabled(! $canEdit)><option value="">—</option>@foreach(\App\Models\InboundShipment::BL_STATUSES as $k => $label)<option value="{{ $k }}" @selected($v('bl_status') === $k)>{{ $label }}</option>@endforeach</select></div>
    <div class="col-6 col-md-3"><label class="form-label mb-1">Forwarder</label><input type="text" name="forwarder" class="form-control form-control-sm" maxlength="120" value="{{ $v('forwarder') }}" @disabled(! $canEdit)></div>
    <div class="col-6 col-md-2"><label class="form-label mb-1">Vessel / flight</label><input type="text" name="vessel" class="form-control form-control-sm" maxlength="120" value="{{ $v('vessel') }}" @disabled(! $canEdit)></div>
    <div class="col-12 col-md-4"><label class="form-label mb-1">Container no(s).</label><input type="text" name="container_no" class="form-control form-control-sm" maxlength="160" value="{{ $v('container_no') }}" @disabled(! $canEdit)></div>
    <div class="col-6 col-md-3"><label class="form-label mb-1">Port of loading</label><input type="text" name="port_of_loading" class="form-control form-control-sm" maxlength="120" value="{{ $v('port_of_loading') }}" @disabled(! $canEdit)></div>
    <div class="col-6 col-md-3"><label class="form-label mb-1">Port of discharge</label><input type="text" name="port_of_discharge" class="form-control form-control-sm" maxlength="120" value="{{ $v('port_of_discharge') }}" @disabled(! $canEdit)></div>
    <div class="col-6 col-md-2"><label class="form-label mb-1">Country of origin</label><input type="text" name="country_of_origin" class="form-control form-control-sm" maxlength="80" value="{{ $v('country_of_origin') }}" @disabled(! $canEdit)></div>

    <div class="col-12"><hr class="my-1"><div class="small text-muted">Dates. The status follows them: planned, then in transit once it has left, arrived, customs cleared, in-house.</div></div>
    @foreach(['etd' => 'ETD (expected to leave)', 'atd' => 'ATD (left)', 'eta' => 'ETA (expected to arrive)', 'ata' => 'ATA (arrived)', 'customs_cleared_date' => 'Customs cleared', 'committed_inhouse_date' => 'Committed in-house', 'actual_inhouse_date' => 'Actual in-house'] as $k => $label)
        <div class="col-6 col-md-3 col-xl"><label class="form-label mb-1">{{ $label }}</label><input type="date" name="{{ $k }}" class="form-control form-control-sm" value="{{ $v($k) }}" @disabled(! $canEdit)></div>
    @endforeach
    <div class="col-12"><label class="form-label mb-1">Remarks</label><input type="text" name="remarks" class="form-control form-control-sm" maxlength="2000" value="{{ $v('remarks') }}" @disabled(! $canEdit)></div>
</div>
