<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AuthorizesStoreCorrections;
use App\Models\BookingPo;
use App\Models\MaterialRequisition;
use App\Models\StockItem;
use Illuminate\Http\Request;

/**
 * Buyer/Style Stock (module B) — requisition REQUEST step. Store only for now:
 * only the store role raises and fulfils requisitions. A bulk issue references
 * the requisition it fulfils (see MaterialBulkIssueController).
 */
class MaterialRequisitionController extends Controller
{
    use AuthorizesStoreCorrections;

    public function index(Request $request)
    {
        $requisitions = MaterialRequisition::with(['requestedBy', 'approvedBy'])
            ->withCount('items')
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        $bookingPos = BookingPo::with('excelFile')->orderByDesc('id')->take(1000)->get();

        // Group PO material lines by PO number. Selecting a PO on the form loads
        // its full item list (one row per BOM/PO material) with Required Qty.
        $poGroups = $bookingPos
            ->filter(fn ($po) => filled($po->po_no))
            ->groupBy('po_no')
            ->map(function ($group, $poNo) {
                $first = $group->first();

                return [
                    'po_no' => (string) $poNo,
                    'buyer_name' => $first->buyer_name,
                    'season_name' => $first->season_name,
                    'style_name' => $first->style_name,
                    'color' => $first->color,
                    'items' => $group->map(fn ($po) => [
                        'booking_po_id' => $po->id,
                        'material_description' => $po->item_name ?: $po->description,
                        'material_color' => $po->color,
                        'size' => $po->size_width,
                        'uom' => $po->uom,
                        'required_qty' => (float) $po->qty,
                    ])->values(),
                ];
            })
            ->values();

        // Same stock item master used by the General Stock issue screen.
        $stockItems = StockItem::where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'uom']);

        ['edit' => $canEdit, 'delete' => $canDelete] = $this->storeCorrectionAbilities();

        return view('store.material-stock.requisitions', compact('requisitions', 'poGroups', 'stockItems', 'canEdit', 'canDelete'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'po_no' => ['required', 'string', 'max:100'],
            'requisition_no' => ['nullable', 'string', 'max:100'],
            'remarks' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.issued_stock_item_id' => ['nullable', 'exists:stock_items,id'],
            'items.*.issued_qty' => ['nullable', 'numeric', 'min:0'],
            'items.*.received_stock_item_id' => ['nullable', 'exists:stock_items,id'],
            'items.*.received_qty' => ['nullable', 'numeric', 'min:0'],
            'items.*.remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        // Authoritative item list = every BOM/PO material line for this PO number.
        // Required Qty and material identity are taken from the server, never the
        // form, so they always match the approved PO data.
        $pos = BookingPo::where('po_no', $validated['po_no'])->orderBy('id')->get();

        if ($pos->isEmpty()) {
            return back()->with('warning', 'Selected PO has no material lines.');
        }

        $submitted = $request->input('items', []);
        $first = $pos->first();

        $header = MaterialRequisition::create([
            'excel_file_id' => $first->excel_file_id,
            'excel_row_id' => $first->excel_row_id,
            'booking_po_id' => $first->id,
            'po_no' => $first->po_no,
            'buyer_name' => $first->buyer_name,
            'season_name' => $first->season_name,
            'style_name' => $first->style_name,
            'material_color' => $first->color,
            'requisition_no' => $validated['requisition_no'] ?? null,
            'status' => MaterialRequisition::STATUS_PENDING,
            'qty' => 0, // set to total issued below
            'requested_by' => auth()->id(),
            'requested_at' => now(),
            'remarks' => $validated['remarks'] ?? null,
            'created_by' => auth()->id(),
        ]);

        $totalIssued = 0.0;

        foreach ($pos as $po) {
            $line = $submitted[$po->id] ?? [];
            $payload = $po->toStockPayload();

            $required = (float) $po->qty;
            $issued = (isset($line['issued_qty']) && $line['issued_qty'] !== '')
                ? (float) $line['issued_qty']
                : $required;
            $received = (isset($line['received_qty']) && $line['received_qty'] !== '')
                ? (float) $line['received_qty']
                : $issued;

            $totalIssued += $issued;

            $header->items()->create([
                'booking_po_id' => $po->id,
                'excel_row_id' => $po->excel_row_id,
                'material_description' => $payload['material_description'],
                'sap_code' => $payload['sap_code'],
                'material_color' => $payload['material_color'],
                'size' => $payload['size'],
                'uom' => $payload['uom'],
                'required_qty' => $required,
                'issued_stock_item_id' => $line['issued_stock_item_id'] ?? null,
                'issued_qty' => $issued,
                'received_stock_item_id' => $line['received_stock_item_id'] ?? null,
                'received_qty' => $received,
                'remarks' => $line['remarks'] ?? null,
            ]);
        }

        $header->update(['qty' => $totalIssued]);

        return back()->with('success', 'Requisition created with '.$pos->count().' item(s).');
    }

    public function approve(MaterialRequisition $materialRequisition)
    {
        if ($materialRequisition->status !== MaterialRequisition::STATUS_PENDING) {
            return back()->with('warning', 'Only a pending requisition can be approved.');
        }

        $materialRequisition->update([
            'status' => MaterialRequisition::STATUS_APPROVED,
            'approved_by' => auth()->id(),
            'approved_at' => now(),
        ]);

        return back()->with('success', 'Requisition approved. It can now be fulfilled by a bulk issue.');
    }

    public function destroy(MaterialRequisition $materialRequisition)
    {
        $this->authorizeStoreDelete('requisition');

        if ($materialRequisition->status === MaterialRequisition::STATUS_ISSUED) {
            return back()->with('warning', 'An issued requisition cannot be deleted.');
        }

        $materialRequisition->delete();

        return back()->with('success', 'Requisition removed.');
    }
}
