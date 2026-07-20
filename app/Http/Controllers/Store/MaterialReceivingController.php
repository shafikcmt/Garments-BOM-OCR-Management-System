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

        // PO-level selector. po_no is unique per booking_pos row, so one option
        // per record already IS one option per PO — the individual materials
        // under it are worksheet lines, loaded on demand by poItems().
        $bookingPos = BookingPo::with('excelFile')
            ->orderByDesc('id')
            ->take(1000)
            ->get();

        return view('store.material-stock.receivings', compact('receivings', 'bookingPos'));
    }

    /**
     * Suggested Receiving defaults per worksheet row id, read from that BOM
     * row's existing cells: Invoice No (SCM) and Unit Price (Invoiced Rate, else
     * PI Rate). One bulk cell query, exact header ids resolved by key via the
     * shared resolver (not fuzzy name matching).
     *
     * Keyed by row rather than by PO because a single PO covers several material
     * lines, each with its own invoice number and rate.
     *
     * @param  \Illuminate\Support\Collection<int, int>  $rowIds
     * @return array<int, array{invoice_no: ?string, unit_price: ?string}>
     */
    private function rowPrefill($rowIds): array
    {
        $resolver = app(HeaderAliasResolver::class);

        $invoiceId = $resolver->resolveHeaderId('invoice_number_scm');
        $invoicedRateId = $resolver->resolveHeaderId('invoiced_rate');
        $piRateId = $resolver->resolveHeaderId('pi_rate');

        $headerIds = array_values(array_filter([$invoiceId, $invoicedRateId, $piRateId]));
        $rowIds = collect($rowIds)->filter()->unique()->values();

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
        foreach ($rowIds as $rowId) {
            $rowCells = $cells->get($rowId);
            if (! $rowCells) {
                continue;
            }

            $unitPrice = $clean($invoicedRateId ? $rowCells->get($invoicedRateId) : null)
                ?? $clean($piRateId ? $rowCells->get($piRateId) : null);

            $prefill[$rowId] = [
                'invoice_no' => $clean($invoiceId ? $rowCells->get($invoiceId) : null),
                'unit_price' => $unitPrice,
            ];
        }

        return $prefill;
    }

    /**
     * Auto-fill payload for one Booking PO, used by the form's PO dropdown.
     * Same resolver the save path uses, so what Store sees is what gets stored.
     */
    public function poDetails(BookingPo $bookingPo)
    {
        if ($bookingPo->excelFile && $bookingPo->excelFile->isLockedForUser(auth()->user())) {
            return response()->json(['locked' => true], 423);
        }

        return response()->json($this->autoFields($bookingPo));
    }

    /**
     * Every material line under one PO, for the "Select Items" picker.
     *
     * A PO number routinely spans several styles/materials, but only its primary
     * line owns a BookingPo record — the rest exist purely as worksheet rows. So
     * the list is built from ExcelRow and each line's identity is resolved from
     * its own cells, which is also what makes the stock ledger key
     * (excel_row_id, size) land on the correct BOM row.
     */
    public function poItems(BookingPo $bookingPo)
    {
        if ($bookingPo->excelFile && $bookingPo->excelFile->isLockedForUser(auth()->user())) {
            return response()->json(['locked' => true], 423);
        }

        $rows = app(\App\Services\BookingPoSourceService::class)->itemRowsForBookingPo($bookingPo);
        $prefill = $this->rowPrefill($rows->pluck('id'));

        $items = $rows->map(function ($row) use ($bookingPo, $prefill) {
            $suggested = $prefill[$row->id] ?? [];

            return array_merge($this->autoFieldsForRow($bookingPo, $row), [
                // Editable suggestions from what other departments already
                // entered on this BOM line — Store may override either.
                'suggested_invoice_no' => $suggested['invoice_no'] ?? null,
                'suggested_unit_price' => $suggested['unit_price'] ?? null,
            ]);
        })->values();

        return response()->json([
            'po_no' => $bookingPo->po_no,
            'items' => $items,
        ]);
    }

    /**
     * Receiving-sheet identity columns that Store never types. Resolution order
     * per field is handled by BookingPoSourceService: the OCR workspace BOM cell
     * wins (exact header-alias match first, then fuzzy), and only when that cell
     * is blank does it fall back to the booking_pos master column.
     *
     * Receiving-only — deliberately NOT folded into BookingPo::toStockPayload(),
     * which is shared with issue/movement/requisition tables that have no such
     * columns.
     *
     * @return array<string, mixed>
     */
    private function autoFields(BookingPo $po): array
    {
        $source = app(\App\Services\BookingPoSourceService::class);

        $internalPoQty = $source->sourceValueForBookingPo($po, 'materials_ordered') ?? $po->qty;
        $internalPoQty = is_numeric($internalPoQty) ? (float) $internalPoQty : null;

        return array_merge($po->toStockPayload(), [
            'supplier_name' => $source->sourceValueForBookingPo($po, 'vendor'),
            'material_name' => $source->sourceValueForBookingPo($po, 'material_name'),
            'gmts_color_name' => $source->sourceValueForBookingPo($po, 'gmts_color'),
            'art_no' => $source->sourceValueForBookingPo($po, 'art_no'),
            'internal_po_qty' => $internalPoQty,
        ]);
    }

    /**
     * Same identity payload as autoFields(), but for one specific material line
     * under the PO.
     *
     * The primary line is exactly what booking_pos already describes, so it is
     * delegated to autoFields() unchanged — single-item receiving keeps its
     * existing resolution byte for byte. For a sibling line there is no
     * BookingPo record, so item-level identity is read from that worksheet row
     * and is deliberately NOT allowed to fall back to the booking_pos master:
     * that master describes the primary material, and using it here would stamp
     * the wrong item's style/colour/size onto the receiving. PO-level identity
     * (buyer, season, PO no, supplier) is shared by every line, so the master is
     * a valid source for those.
     *
     * @return array<string, mixed>
     */
    private function autoFieldsForRow(BookingPo $po, \App\Models\ExcelRow $row): array
    {
        if ((int) $row->id === (int) $po->excel_row_id) {
            return $this->autoFields($po);
        }

        $source = app(\App\Services\BookingPoSourceService::class);

        $item = function (string $group) use ($source, $row): ?string {
            $value = $source->sourceValueForRow($row, $group);

            return ($value !== null && trim($value) !== '') ? $value : null;
        };

        $internalPoQty = $item('materials_ordered');
        $internalPoQty = is_numeric($internalPoQty) ? (float) $internalPoQty : null;

        return [
            'excel_file_id' => $po->excel_file_id,
            'excel_row_id' => $row->id,
            'booking_po_id' => $po->id,
            // Shared across every line under this PO.
            'po_no' => $po->po_no,
            'buyer_name' => $po->buyer_name,
            'season_name' => $po->season_name,
            'supplier_name' => $item('vendor') ?? $source->sourceValueForBookingPo($po, 'vendor'),
            // No UOM column exists in the current workbooks; the PO master is
            // the only available source until one appears.
            'uom' => $item('uom') ?? $po->uom,
            // Specific to this material line.
            'style_name' => $item('style'),
            'material_name' => $item('material_name'),
            'material_description' => $item('material_description'),
            'gmts_color_name' => $item('gmts_color'),
            'art_no' => $item('art_no'),
            'sap_code' => $item('sap_code'),
            'material_color' => $item('material_color'),
            'size' => $item('size'),
            'internal_po_qty' => $internalPoQty,
        ];
    }

    public function store(Request $request)
    {
        // GRN No is intentionally NOT accepted from the form — it is always
        // system-generated below. Invoice No stays manual (vendor's own number).
        // The auto-filled identity fields are also NOT accepted from the request;
        // they are re-resolved server-side so a tampered readonly input is inert.
        // One submission carries several material lines from the same PO. Each
        // row is validated independently and becomes its own receiving record
        // with its own GRN — the per-record logic below is unchanged, only
        // repeated.
        $validated = $request->validate([
            'booking_po_id' => ['required', 'exists:booking_pos,id'],
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.excel_row_id' => ['required', 'integer'],
            'rows.*.invoice_no' => ['nullable', 'string', 'max:100'],
            'rows.*.receive_date' => ['required', 'date'],
            'rows.*.source_type' => ['required', 'in:booking,internal_po'],
            // qty = Physical Rcv Qty; it alone drives the stock ledger.
            'rows.*.qty' => ['required', 'numeric', 'min:0.0001'],
            'rows.*.invoice_qty' => ['nullable', 'numeric', 'min:0'],
            'rows.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'rows.*.remarks' => ['nullable', 'string', 'max:1000'],
        ], [
            'rows.required' => 'Add at least one item before saving.',
            'rows.*.qty.required' => 'Physical Rcv Qty is required for every selected item.',
        ], [
            'rows.*.qty' => 'Physical Rcv Qty',
            'rows.*.invoice_qty' => 'Invoice Qty',
            'rows.*.receive_date' => 'Receive Date',
        ]);

        $po = BookingPo::with('excelFile')->findOrFail($validated['booking_po_id']);

        if ($locked = $this->lockGuard($po)) {
            return $locked;
        }

        // Only worksheet lines that genuinely belong to this PO may be received
        // against it — a tampered excel_row_id must not attach stock to some
        // other buyer's BOM row.
        $allowedRows = app(\App\Services\BookingPoSourceService::class)
            ->itemRowsForBookingPo($po)
            ->keyBy('id');

        $grnNos = [];

        \Illuminate\Support\Facades\DB::transaction(function () use ($validated, $po, $allowedRows, &$grnNos) {
            foreach ($validated['rows'] as $index => $row) {
                $excelRow = $allowedRows->get((int) $row['excel_row_id']);

                if (! $excelRow) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        "rows.$index.excel_row_id" => 'That item does not belong to the selected PO.',
                    ]);
                }

                // Invoice Value is never taken from the form — the browser only
                // previews it. Recomputed here so the stored figure always
                // matches its inputs.
                $invoiceQty = $row['invoice_qty'] ?? null;
                $unitPrice = $row['unit_price'] ?? null;
                $invoiceValue = ($invoiceQty !== null && $unitPrice !== null)
                    ? round((float) $invoiceQty * (float) $unitPrice, 4)
                    : null;

                $payload = array_merge(
                    $this->autoFieldsForRow($po, $excelRow),
                    [
                        'invoice_no' => $row['invoice_no'] ?? null,
                        'receive_date' => $row['receive_date'],
                        'source_type' => $row['source_type'],
                        'qty' => $row['qty'],
                        'invoice_qty' => $invoiceQty,
                        'unit_price' => $unitPrice,
                        'invoice_value' => $invoiceValue,
                        'remarks' => $row['remarks'] ?? null,
                        'created_by' => auth()->id(),
                    ]
                );

                // Generate + insert with a short retry: the DB unique index is
                // the real guard, so a concurrent insert that grabs the same GRN
                // just retries.
                for ($attempt = 1; $attempt <= 5; $attempt++) {
                    $grnNo = $this->generateGrnNo($po, $row['receive_date']);
                    try {
                        MaterialReceiving::create(array_merge($payload, ['grn_no' => $grnNo]));
                        $grnNos[] = $grnNo;
                        break;
                    } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                        if ($attempt === 5) {
                            throw $e;
                        }
                    }
                }
            }
        });

        $count = count($grnNos);
        $message = $count === 1
            ? 'Receiving recorded (GRN '.$grnNos[0].'). Closing stock updated.'
            : $count.' receivings recorded (GRN '.$grnNos[0].' … '.end($grnNos).'). Closing stock updated.';

        return back()->with('success', $message);
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
