<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\BookingPo;
use App\Models\MaterialRequisition;
use Illuminate\Http\Request;

/**
 * Buyer/Style Stock (module B) — requisition REQUEST step. Store only for now:
 * only the store role raises and fulfils requisitions. A bulk issue references
 * the requisition it fulfils (see MaterialBulkIssueController).
 */
class MaterialRequisitionController extends Controller
{
    public function index(Request $request)
    {
        $requisitions = MaterialRequisition::with(['requestedBy', 'approvedBy'])
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        $bookingPos = BookingPo::with('excelFile')->orderByDesc('id')->take(1000)->get();

        return view('store.material-stock.requisitions', compact('requisitions', 'bookingPos'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'booking_po_id' => ['required', 'exists:booking_pos,id'],
            'requisition_no' => ['nullable', 'string', 'max:100'],
            'qty' => ['required', 'numeric', 'min:0.0001'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        $po = BookingPo::findOrFail($validated['booking_po_id']);

        MaterialRequisition::create(array_merge(
            $po->toStockPayload(),
            [
                'requisition_no' => $validated['requisition_no'] ?? null,
                'status' => MaterialRequisition::STATUS_PENDING,
                'qty' => $validated['qty'],
                'requested_by' => auth()->id(),
                'requested_at' => now(),
                'remarks' => $validated['remarks'] ?? null,
                'created_by' => auth()->id(),
            ]
        ));

        return back()->with('success', 'Requisition created.');
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
        if ($materialRequisition->status === MaterialRequisition::STATUS_ISSUED) {
            return back()->with('warning', 'An issued requisition cannot be deleted.');
        }

        $materialRequisition->delete();

        return back()->with('success', 'Requisition removed.');
    }
}
