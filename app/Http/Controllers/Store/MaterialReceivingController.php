<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\BookingPo;
use App\Models\ExcelCell;
use App\Models\MaterialReceiving;
use App\Services\HeaderAliasResolver;
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

        // Editable suggested defaults pulled from data other departments already
        // entered on the same BOM row — Store stops re-typing them (still fully
        // overridable, since a physical GRN can differ from the SCM invoice).
        $prefill = $this->bookingPoPrefill($bookingPos);

        return view('store.material-stock.receivings', compact('receivings', 'bookingPos', 'prefill'));
    }

    /**
     * Suggested Receiving defaults per booking_po_id, read from the linked BOM
     * row's existing cells: Invoice No (SCM), Unit Price (Invoiced Rate, else PI
     * Rate) and Vendor Name (display-only). One bulk cell query, exact header ids
     * resolved by key via the shared resolver (not fuzzy name matching).
     *
     * @return array<int, array{invoice_no: ?string, unit_price: ?string, vendor_name: ?string}>
     */
    private function bookingPoPrefill($bookingPos): array
    {
        $resolver = app(HeaderAliasResolver::class);

        $invoiceId = $resolver->resolveHeaderId('invoice_number_scm');
        $invoicedRateId = $resolver->resolveHeaderId('invoiced_rate');
        $piRateId = $resolver->resolveHeaderId('pi_rate');
        $vendorId = $resolver->resolveHeaderId('vendor_name');

        $headerIds = array_values(array_filter([$invoiceId, $invoicedRateId, $piRateId, $vendorId]));
        $rowIds = $bookingPos->pluck('excel_row_id')->filter()->unique()->values();

        if (empty($headerIds) || $rowIds->isEmpty()) {
            return [];
        }

        // cells[row_id][header_id] = value
        $cells = ExcelCell::whereIn('row_id', $rowIds->all())
            ->whereIn('header_id', $headerIds)
            ->get(['row_id', 'header_id', 'value'])
            ->groupBy('row_id')
            ->map(fn ($group) => $group->pluck('value', 'header_id'));

        $clean = static function ($value): ?string {
            $value = trim((string) $value);
            return $value === '' ? null : $value;
        };

        $prefill = [];
        foreach ($bookingPos as $po) {
            $rowCells = $cells->get($po->excel_row_id);
            if (! $rowCells) {
                continue;
            }

            $unitPrice = $clean($invoicedRateId ? $rowCells->get($invoicedRateId) : null)
                ?? $clean($piRateId ? $rowCells->get($piRateId) : null);

            $prefill[$po->id] = [
                'invoice_no' => $clean($invoiceId ? $rowCells->get($invoiceId) : null),
                'unit_price' => $unitPrice,
                'vendor_name' => $clean($vendorId ? $rowCells->get($vendorId) : null),
            ];
        }

        return $prefill;
    }

    public function store(Request $request)
    {
        // GRN No is intentionally NOT accepted from the form — it is always
        // system-generated below. Invoice No stays manual (vendor's own number).
        $validated = $request->validate([
            'booking_po_id' => ['required', 'exists:booking_pos,id'],
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

        $payload = array_merge(
            $po->toStockPayload(),
            [
                'invoice_no' => $validated['invoice_no'] ?? null,
                'receive_date' => $validated['receive_date'],
                'source_type' => $validated['source_type'],
                'qty' => $validated['qty'],
                'unit_price' => $validated['unit_price'] ?? null,
                'remarks' => $validated['remarks'] ?? null,
                'created_by' => auth()->id(),
            ]
        );

        // Generate + insert with a short retry: the DB unique index is the real
        // guard, so a concurrent insert that grabs the same GRN just retries.
        $grnNo = null;
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $grnNo = $this->generateGrnNo($po, $validated['receive_date']);
            try {
                MaterialReceiving::create(array_merge($payload, ['grn_no' => $grnNo]));
                break;
            } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                if ($attempt === 5) {
                    throw $e;
                }
            }
        }

        return back()->with('success', 'Receiving recorded (GRN '.$grnNo.'). Closing stock updated.');
    }

    /**
     * Build a unique, human-readable GRN No that encodes buyer / season / year
     * and a global running sequence, reusing the codes already stored on the PO
     * (same convention as PO numbers). Format: GRN-{buyer}-{season}-{YYYY}-{0001}.
     */
    private function generateGrnNo(BookingPo $po, string $receiveDate): string
    {
        $buyer = $this->shortCode($po->buyer_code, $po->buyer_name, 2);
        $season = $this->seasonCode($po->season_code, $po->season_name);
        $year = date('Y', strtotime($receiveDate) ?: time());
        $prefix = "GRN-{$buyer}-{$season}-{$year}-";

        // Global running number across every GRN (parse the trailing 4 digits of
        // existing auto GRNs) so no two receivings ever share a sequence.
        $lastSeq = (int) MaterialReceiving::where('grn_no', 'like', 'GRN-%')
            ->get(['grn_no'])
            ->map(fn ($r) => preg_match('/-(\d{4})$/', (string) $r->grn_no, $m) ? (int) $m[1] : 0)
            ->max();

        $next = $lastSeq + 1;

        do {
            $grnNo = $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
            $next++;
        } while (MaterialReceiving::where('grn_no', $grnNo)->exists());

        return $grnNo;
    }

    /**
     * Prefer the code already stored on the PO; otherwise derive it from the
     * name using the same rules the PO generator uses.
     */
    private function shortCode(?string $stored, ?string $name, int $length): string
    {
        $stored = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string) $stored));
        if ($stored !== '') {
            return str_pad(substr($stored, 0, $length), $length, 'X');
        }

        $value = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string) $name));
        if ($value === '') {
            $value = str_repeat('X', $length);
        }

        return str_pad(substr($value, 0, $length), $length, 'X');
    }

    private function seasonCode(?string $stored, ?string $name): string
    {
        $stored = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string) $stored));
        if ($stored !== '') {
            return $stored;
        }

        $value = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string) $name));
        if ($value === '') {
            return 'XXXX';
        }

        return str_pad(substr($value, -4), 4, 'X', STR_PAD_LEFT);
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
