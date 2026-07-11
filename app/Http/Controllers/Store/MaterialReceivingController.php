<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\BookingPo;
use App\Models\MaterialReceiving;
use Illuminate\Http\Request;

/**
 * Buyer/Style Stock (module B) — GRN-level receive (Excel "Receiving" sheet).
 * Attaches to an existing Booking PO so buyer/style/PO/material identity is
 * copied onto the row (and the BOM row is linked via excel_row_id). Store only.
 */
class MaterialReceivingController extends Controller
{
    public function index(Request $request)
    {
        $receivings = MaterialReceiving::with('createdBy')
            ->latest('receive_date')
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        $bookingPos = BookingPo::with('excelFile')
            ->orderByDesc('id')
            ->take(1000)
            ->get();

        return view('store.material-stock.receivings', compact('receivings', 'bookingPos'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'booking_po_id' => ['required', 'exists:booking_pos,id'],
            'grn_no' => ['nullable', 'string', 'max:100'],
            'invoice_no' => ['nullable', 'string', 'max:100'],
            'receive_date' => ['required', 'date'],
            'source_type' => ['required', 'in:booking,internal_po'],
            'qty' => ['required', 'numeric', 'min:0.0001'],
            'unit_price' => ['nullable', 'numeric', 'min:0'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        $po = BookingPo::with('excelFile')->findOrFail($validated['booking_po_id']);

        if ($locked = $this->lockGuard($po)) {
            return $locked;
        }

        MaterialReceiving::create(array_merge(
            $po->toStockPayload(),
            [
                'grn_no' => $validated['grn_no'] ?? null,
                'invoice_no' => $validated['invoice_no'] ?? null,
                'receive_date' => $validated['receive_date'],
                'source_type' => $validated['source_type'],
                'qty' => $validated['qty'],
                'unit_price' => $validated['unit_price'] ?? null,
                'remarks' => $validated['remarks'] ?? null,
                'created_by' => auth()->id(),
            ]
        ));

        return back()->with('success', 'Receiving recorded. Closing stock updated.');
    }

    public function destroy(MaterialReceiving $materialReceiving)
    {
        $materialReceiving->delete();

        return back()->with('success', 'Receiving entry removed. Closing stock updated.');
    }

    /**
     * Block writes when the source file is locked for the current user.
     */
    protected function lockGuard(BookingPo $po)
    {
        if ($po->excelFile && $po->excelFile->isLockedForUser(auth()->user())) {
            return back()->with('warning', 'This file/style is locked. Stock entry is not allowed.');
        }

        return null;
    }
}
