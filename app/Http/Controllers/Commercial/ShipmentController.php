<?php

namespace App\Http\Controllers\Commercial;

use App\Http\Controllers\Controller;
use App\Models\BookingPo;
use App\Models\Buyer;
use App\Models\InboundShipment;
use App\Models\InboundShipmentPo;
use App\Services\InboundShipments;
use App\Support\Concerns\ScopesToAssignedBuyers;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Inbound shipments. Commercial and Admin record them for the buyers they hold;
 * Management reads. The status comes from the dates. Saving also carries the
 * BL / AWB no and the dates into the workspace rows of the POs it carries.
 */
class ShipmentController extends Controller
{
    use ScopesToAssignedBuyers;

    public function index(Request $request, InboundShipments $service)
    {
        $user = $request->user();
        $scoped = $this->scopedQuery($request);

        $status = $request->query('status');
        $mode = array_key_exists($request->query('mode'), InboundShipment::MODES) ? $request->query('mode') : null;
        $term = trim((string) $request->query('q', ''));

        $all = (clone $scoped)->with('buyer:id,buyer_name')->withCount('links')->orderByRaw('case when eta is null then 1 else 0 end')->orderBy('eta')->orderByDesc('id')->limit(1000)->get();
        $shipments = $all
            ->when($mode, fn ($c) => $c->where('ship_mode', $mode))
            ->when($term !== '', fn ($c) => $c->filter(fn ($s) => collect([$s->shipment_no, $s->bl_awb_no, $s->container_no, $s->forwarder, $s->vessel, $s->port_of_discharge])
                ->contains(fn ($v) => $v !== null && mb_stripos($v, $term) !== false)))
            ->when($status === 'open', fn ($c) => $c->filter(fn ($s) => $s->status() !== 'in_house'))
            ->when(array_key_exists((string) $status, InboundShipment::STATUSES), fn ($c) => $c->filter(fn ($s) => $s->status() === $status))
            ->when($status === 'late', fn ($c) => $c->filter(fn ($s) => $s->isLate()))
            ->values();
        $status = in_array($status, ['open', 'late'], true) || array_key_exists((string) $status, InboundShipment::STATUSES) ? $status : null;

        return view('commercial.shipments.index', [
            'shipments' => $shipments->take(300),
            'summary' => $service->clearance($all),
            'status' => $status, 'mode' => $mode, 'term' => $term,
            'canEdit' => $user->hasAnyRole(['commercial', 'admin']),
            'today' => Carbon::today(),
        ]);
    }

    public function clearance(Request $request, InboundShipments $service)
    {
        $all = $this->scopedQuery($request)->with('buyer:id,buyer_name')->limit(1000)->get();

        return view('commercial.shipments.clearance', ['summary' => $service->clearance($all), 'today' => Carbon::today(), 'waitDays' => InboundShipments::CLEARANCE_WAIT_DAYS]);
    }

    public function create(Request $request)
    {
        abort_unless($request->user()->hasAnyRole(['commercial', 'admin']), 403);

        return view('commercial.shipments.create', ['buyers' => $this->buyers($request), 'shipment' => new InboundShipment()]);
    }

    public function store(Request $request, InboundShipments $service)
    {
        abort_unless($request->user()->hasAnyRole(['commercial', 'admin']), 403);
        $data = $this->validated($request, true);
        abort_unless($request->user()->hasBuyerAccess((int) $data['buyer_id']), 403);

        $shipment = InboundShipment::create($data + ['created_by' => $request->user()->id, 'updated_by' => $request->user()->id]);
        $service->assignNumber($shipment);

        return redirect()->route('commercial.shipments.show', $shipment)->with('success', 'Shipment ' . $shipment->shipment_no . ' added. Now add the POs it carries.');
    }

    public function show(Request $request, InboundShipment $shipment)
    {
        $this->authorizeView($request, $shipment);
        $shipment->load(['buyer', 'creator:id,name', 'links.bookingPo']);

        $term = trim((string) $request->query('q', ''));
        $found = collect();
        if ($term !== '' && $request->user()->hasAnyRole(['commercial', 'admin'])) {
            $query = BookingPo::query()->where('status', '!=', 'preview')
                ->whereNotIn('id', $shipment->links->pluck('booking_po_id'))
                ->whereHas('excelFile', fn ($q) => $q->where('buyer_id', $shipment->buyer_id))
                ->where(fn ($w) => $w->whereLike('po_no', '%' . $term . '%')->orWhereLike('vendor_name', '%' . $term . '%')
                    ->orWhereLike('style_name', '%' . $term . '%')->orWhereLike('description', '%' . $term . '%')->orWhereLike('item_name', '%' . $term . '%'))
                ->orderByDesc('id')->limit(20);
            $this->applyBuyerScope($query);
            $found = $query->get();
        }

        return view('commercial.shipments.show', [
            'shipment' => $shipment, 'found' => $found, 'term' => $term,
            'canEdit' => $request->user()->hasAnyRole(['commercial', 'admin']), 'today' => Carbon::today(),
        ]);
    }

    public function update(Request $request, InboundShipment $shipment, InboundShipments $service)
    {
        $this->authorizeEdit($request, $shipment);
        $shipment->update($this->validated($request, false) + ['updated_by' => $request->user()->id]);
        $written = $service->writeToWorkspace($shipment, $request->user());

        return back()->with('success', 'Shipment saved.' . ($written > 0 ? ' ' . $written . ' workspace cell(s) updated.' : ''));
    }

    public function addPo(Request $request, InboundShipment $shipment, InboundShipments $service)
    {
        $this->authorizeEdit($request, $shipment);
        $data = $request->validate(['booking_po_id' => ['required', 'integer', 'exists:booking_pos,id'], 'qty' => ['nullable', 'numeric', 'min:0', 'max:999999999']]);

        $po = BookingPo::with('excelFile:id,buyer_id')->findOrFail($data['booking_po_id']);
        abort_unless((int) $po->excelFile?->buyer_id === (int) $shipment->buyer_id, 403);
        abort_unless($request->user()->hasBuyerAccess((int) $po->excelFile?->buyer_id), 403);

        InboundShipmentPo::firstOrCreate(['inbound_shipment_id' => $shipment->id, 'booking_po_id' => $po->id], ['qty' => $data['qty'] ?? $po->qty]);
        $service->writeToWorkspace($shipment, $request->user());

        return redirect()->route('commercial.shipments.show', $shipment)->with('success', 'PO ' . $po->po_no . ' added.');
    }

    public function updatePo(Request $request, InboundShipment $shipment, InboundShipmentPo $link)
    {
        $this->authorizeEdit($request, $shipment);
        abort_unless($link->inbound_shipment_id === $shipment->id, 404);
        $link->update($request->validate(['qty' => ['nullable', 'numeric', 'min:0', 'max:999999999']]));

        return back()->with('success', 'Quantity saved.');
    }

    public function removePo(Request $request, InboundShipment $shipment, InboundShipmentPo $link)
    {
        $this->authorizeEdit($request, $shipment);
        abort_unless($link->inbound_shipment_id === $shipment->id, 404);
        $link->delete();

        return back()->with('success', 'PO removed from the shipment.');
    }

    public function destroy(Request $request, InboundShipment $shipment)
    {
        $this->authorizeEdit($request, $shipment);
        $shipment->delete();

        return redirect()->route('commercial.shipments.index')->with('success', 'Shipment deleted.');
    }

    // ------------------------------------------------------------------

    private function scopedQuery(Request $request)
    {
        $user = $request->user();
        $query = InboundShipment::query();
        if ($user->isBuyerConfined()) {
            $query->where(fn ($q) => $q->whereNull('buyer_id')->orWhereIn('buyer_id', $user->scopedBuyerIds()));
        }

        return $query;
    }

    private function buyers(Request $request)
    {
        $user = $request->user();

        return Buyer::active()->when($user->isBuyerConfined(), fn ($q) => $q->whereIn('id', $user->scopedBuyerIds()))->orderBy('buyer_name')->get();
    }

    private function validated(Request $request, bool $creating): array
    {
        $rules = [
            'ship_mode' => ['required', Rule::in(array_keys(InboundShipment::MODES))],
            'forwarder' => ['nullable', 'string', 'max:120'],
            'vessel' => ['nullable', 'string', 'max:120'],
            'bl_awb_no' => ['nullable', 'string', 'max:80'],
            'bl_status' => ['nullable', Rule::in(array_keys(InboundShipment::BL_STATUSES))],
            'container_no' => ['nullable', 'string', 'max:160'],
            'port_of_loading' => ['nullable', 'string', 'max:120'],
            'port_of_discharge' => ['nullable', 'string', 'max:120'],
            'country_of_origin' => ['nullable', 'string', 'max:80'],
            'etd' => ['nullable', 'date'], 'atd' => ['nullable', 'date'], 'eta' => ['nullable', 'date'], 'ata' => ['nullable', 'date'],
            'customs_cleared_date' => ['nullable', 'date'], 'committed_inhouse_date' => ['nullable', 'date'], 'actual_inhouse_date' => ['nullable', 'date'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
        if ($creating) {
            $rules['buyer_id'] = ['required', 'integer', 'exists:buyers,id'];
        }
        $data = $request->validate($rules, ['buyer_id.required' => 'Choose the buyer.']);

        // A later step cannot come before an earlier one.
        $order = [['atd', 'ata', 'Arrived (ATA) cannot be before Left (ATD).'], ['ata', 'customs_cleared_date', 'Customs cleared cannot be before arrival (ATA).'], ['ata', 'actual_inhouse_date', 'In-house cannot be before arrival (ATA).']];
        foreach ($order as [$first, $second, $message]) {
            if (! empty($data[$first]) && ! empty($data[$second]) && $data[$second] < $data[$first]) {
                throw ValidationException::withMessages([$second => $message]);
            }
        }
        if (empty($data['ata']) && (! empty($data['customs_cleared_date']) || ! empty($data['actual_inhouse_date']))) {
            throw ValidationException::withMessages(['ata' => 'Enter the arrival date (ATA) before the clearance or in-house date.']);
        }

        return $data;
    }

    private function authorizeView(Request $request, InboundShipment $shipment): void
    {
        abort_unless($request->user()->hasBuyerAccess($shipment->buyer_id ? (int) $shipment->buyer_id : null), 403);
    }

    private function authorizeEdit(Request $request, InboundShipment $shipment): void
    {
        abort_unless($request->user()->hasAnyRole(['commercial', 'admin']) && $request->user()->hasBuyerAccess($shipment->buyer_id ? (int) $shipment->buyer_id : null), 403);
    }
}
