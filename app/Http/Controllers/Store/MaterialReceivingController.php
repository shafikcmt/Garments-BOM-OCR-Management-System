<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AuthorizesStoreCorrections;
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
    use AuthorizesStoreCorrections;

    /**
     * How many browse options the search field will list before it stops being
     * a complete picture and typing has to reach the server instead.
     */
    private const BROWSE_LIMIT = 500;

    public function index(Request $request)
    {
        $receivings = MaterialReceiving::with('createdBy')
            ->latest('receive_date')
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        // POs are now found through the PO No / SAP Code / PI No search rather
        // than a preloaded dropdown, so the page only needs to know whether any
        // exist at all.
        $hasBookingPos = BookingPo::query()->exists();

        // Correction rights decide whether the action column renders at all —
        // the buttons are absent for a role that cannot use them, not disabled.
        ['edit' => $canEdit, 'delete' => $canDelete] = $this->storeCorrectionAbilities();

        return view('store.material-stock.receivings', compact(
            'receivings', 'hasBookingPos', 'canEdit', 'canDelete'
        ));
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
     * Physical qty already received against each worksheet row by earlier GRNs,
     * summed in one grouped query rather than per row.
     *
     * Keyed on excel_row_id because that is what a receiving is booked against —
     * the same key the stock ledger uses.
     *
     * @param  \Illuminate\Support\Collection<int, int>  $rowIds
     * @return array<int, float>
     */
    private function receivedQtyByRow($rowIds): array
    {
        $rowIds = collect($rowIds)->filter()->unique()->values();

        if ($rowIds->isEmpty()) {
            return [];
        }

        return MaterialReceiving::query()
            ->whereIn('excel_row_id', $rowIds->all())
            ->groupBy('excel_row_id')
            ->selectRaw('excel_row_id, SUM(qty) AS total')
            ->pluck('total', 'excel_row_id')
            ->map(fn ($total) => (float) $total)
            ->all();
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
     * Booking POs matching a search on PO No, SAP Code, PI No or Invoice No.
     *
     * All four handles resolve to the same booking record. A value can reach
     * more than one PO (a SAP code may appear under several), so the caller gets
     * the full list and picks — a receiving still belongs to exactly one PO.
     */
    public function poSearch(Request $request)
    {
        // SAP Code was dropped as a search handle: a SAP code identifies a
        // material, not the paperwork a receiving arrives under, so it routinely
        // fanned out to POs that had nothing to do with the delivery. The column
        // itself is untouched and still auto-fills onto the receiving row.
        $validated = $request->validate([
            'type' => ['required', 'in:po_no,pi_number,invoice_no'],
            'term' => ['nullable', 'string', 'max:100'],
        ]);

        $term = trim((string) ($validated['term'] ?? ''));
        $source = app(\App\Services\BookingPoSourceService::class);

        // Empty term = browse. Every filter type is now listable, so opening the
        // field shows what exists instead of demanding a number up front.
        if ($term === '') {
            $options = $source->browseOptionsForGroup($validated['type'], self::BROWSE_LIMIT);

            return response()->json([
                // Whether the browse list is the whole dataset. When it is, the
                // browser filters it locally and stops calling back on every
                // keystroke; when it was capped, typing falls through to the
                // search below so nothing becomes unreachable.
                'complete' => $options->count() < self::BROWSE_LIMIT,
                'results' => $options->map(fn (array $option) => [
                    'id' => $option['po']->id,
                    'value' => $option['value'],
                    'po_no' => $option['po']->po_no,
                    'buyer_name' => $option['po']->buyer_name,
                    'season_name' => $option['po']->season_name,
                    'vendor_name' => $option['po']->vendor_name,
                ])->values(),
            ]);
        }

        $matches = $source->bookingPosMatching($validated['type'], $term);

        return response()->json([
            'complete' => false,
            'results' => $matches->map(fn (BookingPo $po) => [
                'id' => $po->id,
                // The typed path matches POs rather than individual cell values,
                // so the PO number is the only handle it can label a row with.
                'value' => $po->po_no,
                'po_no' => $po->po_no,
                'buyer_name' => $po->buyer_name,
                'season_name' => $po->season_name,
                'vendor_name' => $po->vendor_name,
            ])->values(),
        ]);
    }

    /**
     * Existing buyer/style pairs, for the Independent flow's style picker.
     *
     * Read from booking_pos with the same distinct-style idiom the Style Budget
     * screen already uses, so Store sees the same style vocabulary everywhere.
     * Buyer and season travel with the style because a style name alone is not
     * unique across buyers, and the GRN number needs both.
     */
    public function styleSearch(Request $request)
    {
        $validated = $request->validate([
            'term' => ['nullable', 'string', 'max:100'],
        ]);

        $term = trim((string) ($validated['term'] ?? ''));

        $query = BookingPo::query()
            ->whereNotNull('style_name')
            ->where('style_name', '!=', '');

        if ($term !== '') {
            $query->where(function ($q) use ($term) {
                $q->where('style_name', 'like', '%'.$term.'%')
                    ->orWhere('buyer_name', 'like', '%'.$term.'%');
            });
        }

        // One option per buyer/style/season, not per PO: the user is choosing
        // which style the material belongs to, and a style spans many POs.
        $options = $query->orderBy('buyer_name')->orderBy('style_name')
            ->get(['buyer_name', 'season_name', 'style_name', 'buyer_code', 'season_code'])
            ->unique(fn ($po) => $po->buyer_name.'|'.$po->style_name.'|'.$po->season_name)
            ->take(self::BROWSE_LIMIT)
            ->values();

        return response()->json([
            'complete' => $term === '' && $options->count() < self::BROWSE_LIMIT,
            'results' => $options->map(fn ($po) => [
                'value' => $po->style_name,
                'style_name' => $po->style_name,
                'buyer_name' => $po->buyer_name,
                'season_name' => $po->season_name,
                'buyer_code' => $po->buyer_code,
                'season_code' => $po->season_code,
            ])->values(),
        ]);
    }

    /**
     * The material lines a style already carries on its BOM, for the Independent
     * form's field suggestions.
     *
     * Returns whole rows rather than six separate lists of distinct values: the
     * browser needs to know which colour belongs to which material name so that
     * choosing a material can narrow the other fields to combinations that
     * genuinely exist. Deriving the distinct lists from these rows is cheap and
     * keeps the relationship between the fields intact.
     *
     * Scoped to the one style, so a large workbook does not travel to the
     * browser. Every field is still free-text on the form — this only offers
     * what is already known.
     */
    public function styleBom(Request $request)
    {
        $validated = $request->validate([
            'style_name' => ['required', 'string', 'max:255'],
        ]);

        $source = app(\App\Services\BookingPoSourceService::class);
        $rows = $source->rowsMatchingGroupValue('style', $validated['style_name']);

        $clean = static function (?string $value): ?string {
            $value = trim((string) $value);

            return $value === '' ? null : $value;
        };

        // One entry per distinct combination: a BOM routinely repeats the same
        // material line across sizes, and duplicates would only pad the lists.
        $lines = $rows->map(fn (\App\Models\ExcelRow $row) => [
            'material_name' => $clean($source->sourceValueForRow($row, 'material_name')),
            'material_description' => $clean($source->sourceValueForRow($row, 'material_description')),
            'supplier_name' => $clean($source->sourceValueForRow($row, 'vendor')),
            'material_color' => $clean($source->sourceValueForRow($row, 'material_color')),
            'size' => $clean($source->sourceValueForRow($row, 'size')),
            'uom' => $clean($source->sourceValueForRow($row, 'uom')),
        ])
            ->reject(fn (array $line) => collect($line)->filter()->isEmpty())
            ->unique(fn (array $line) => implode('|', array_map(fn ($v) => (string) $v, $line)))
            ->values();

        return response()->json(['lines' => $lines]);
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
        $alreadyReceived = $this->receivedQtyByRow($rows->pluck('id'));

        $items = $rows->map(function ($row) use ($bookingPo, $prefill, $alreadyReceived) {
            $suggested = $prefill[$row->id] ?? [];

            return array_merge($this->autoFieldsForRow($bookingPo, $row), [
                // Editable suggestions from what other departments already
                // entered on this BOM line — Store may override either.
                'suggested_invoice_no' => $suggested['invoice_no'] ?? null,
                'suggested_unit_price' => $suggested['unit_price'] ?? null,
                // What earlier GRNs already booked against this line, so Store
                // can see how much of the order is still outstanding.
                'received_qty' => $alreadyReceived[$row->id] ?? 0.0,
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
            // Optional: left blank it follows the receive date below.
            'rows.*.grn_date' => ['nullable', 'date'],
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
                //
                // Valued on PHYSICAL Rcv Qty, not Invoice Qty. The invoice says
                // what the vendor billed; the value booked against stock has to
                // be what actually arrived, or a short delivery invoiced in full
                // would carry its full value into the ledger. This is the same
                // basis the Store reports already use (SUM(qty * unit_price)).
                $invoiceQty = $row['invoice_qty'] ?? null;
                $unitPrice = $row['unit_price'] ?? null;
                $invoiceValue = ($unitPrice !== null)
                    ? round((float) $row['qty'] * (float) $unitPrice, 4)
                    : null;

                $payload = array_merge(
                    $this->autoFieldsForRow($po, $excelRow),
                    [
                        'invoice_no' => $row['invoice_no'] ?? null,
                        'receive_date' => $row['receive_date'],
                        // Defaults to the receive date so a GRN always carries a
                        // date, whether or not Store set one explicitly.
                        'grn_date' => $row['grn_date'] ?? $row['receive_date'],
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
     * Record a receiving whose paperwork does not match any PO / PI / Invoice.
     *
     * The material physically arrived, so refusing to record it just pushes the
     * entry back into a spreadsheet. It is stored against the style the user
     * picked and flagged `independent`.
     *
     * booking_po_id and excel_row_id stay NULL by design. That is what keeps it
     * out of the stock ledger — MaterialStockLedgerService keys on
     * (excel_row_id, size) and ignores unlinked events — so an unmatched
     * delivery can never inflate closing stock. It joins the ledger the moment
     * link() attaches it to a real BOM row.
     */
    public function storeIndependent(Request $request)
    {
        $validated = $request->validate([
            'buyer_name' => ['required', 'string', 'max:255'],
            'style_name' => ['required', 'string', 'max:255'],
            'season_name' => ['nullable', 'string', 'max:255'],

            // No BOM row to copy identity from, so Store types the material.
            'material_name' => ['required', 'string', 'max:255'],
            'material_description' => ['nullable', 'string', 'max:1000'],
            'material_color' => ['nullable', 'string', 'max:255'],
            'size' => ['nullable', 'string', 'max:255'],
            'uom' => ['nullable', 'string', 'max:50'],
            'supplier_name' => ['nullable', 'string', 'max:255'],

            'invoice_no' => ['nullable', 'string', 'max:100'],
            'receive_date' => ['required', 'date'],
            'grn_date' => ['nullable', 'date'],
            'source_type' => ['required', 'in:booking,internal_po'],
            'qty' => ['required', 'numeric', 'min:0.0001'],
            'invoice_qty' => ['nullable', 'numeric', 'min:0'],
            'unit_price' => ['nullable', 'numeric', 'min:0'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ], [], [
            'qty' => 'Physical Rcv Qty',
            'invoice_qty' => 'Invoice Qty',
            'receive_date' => 'Receive Date',
            'material_name' => 'Material Name',
        ]);

        // The style came from the booking_pos list, so its buyer/season codes are
        // available for the GRN number without trusting anything from the form.
        $reference = BookingPo::query()
            ->where('buyer_name', $validated['buyer_name'])
            ->where('style_name', $validated['style_name'])
            ->first();

        // Same rule as the PO path: Invoice Value is always recomputed, never
        // accepted from the browser, and always valued on the physical qty.
        $invoiceQty = $validated['invoice_qty'] ?? null;
        $unitPrice = $validated['unit_price'] ?? null;
        $invoiceValue = ($unitPrice !== null)
            ? round((float) $validated['qty'] * (float) $unitPrice, 4)
            : null;

        $grnNo = null;

        \Illuminate\Support\Facades\DB::transaction(function () use (
            $validated, $reference, $invoiceQty, $unitPrice, $invoiceValue, &$grnNo
        ) {
            $payload = [
                'buyer_name' => $validated['buyer_name'],
                'style_name' => $validated['style_name'],
                'season_name' => $validated['season_name'] ?? $reference?->season_name,
                'supplier_name' => $validated['supplier_name'] ?? null,
                'material_name' => $validated['material_name'],
                'material_description' => $validated['material_description'] ?? null,
                'material_color' => $validated['material_color'] ?? null,
                'size' => $validated['size'] ?? null,
                'uom' => $validated['uom'] ?? null,
                'invoice_no' => $validated['invoice_no'] ?? null,
                'receive_date' => $validated['receive_date'],
                'grn_date' => $validated['grn_date'] ?? $validated['receive_date'],
                'source_type' => $validated['source_type'],
                'match_status' => MaterialReceiving::MATCH_INDEPENDENT,
                'qty' => $validated['qty'],
                'invoice_qty' => $invoiceQty,
                'unit_price' => $unitPrice,
                'invoice_value' => $invoiceValue,
                'remarks' => $validated['remarks'] ?? null,
                'created_by' => auth()->id(),
            ];

            // Same short retry as the PO path — the unique index is the real guard.
            for ($attempt = 1; $attempt <= 5; $attempt++) {
                $candidate = $this->generateGrnNoFrom(
                    $reference?->buyer_code, $validated['buyer_name'],
                    $reference?->season_code, $validated['season_name'] ?? $reference?->season_name,
                    $validated['receive_date']
                );

                try {
                    MaterialReceiving::create(array_merge($payload, ['grn_no' => $candidate]));
                    $grnNo = $candidate;
                    break;
                } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                    if ($attempt === 5) {
                        throw $e;
                    }
                }
            }
        });

        return back()->with('success',
            'Independent receiving recorded (GRN '.$grnNo.'). It will not affect closing stock until it is linked to a PO.');
    }

    /**
     * Attach an Independent receiving to the PO and BOM line it turned out to
     * belong to.
     *
     * There is no automatic OCR re-match in this application — Booking POs are
     * generated from BOM rows, and nothing walks back over orphan receivings. So
     * this is the deliberate, human-confirmed step: it reuses the same poSearch
     * and poItems endpoints the normal flow already uses, rather than adding a
     * second matching system that could guess the wrong material line.
     *
     * Saving re-resolves identity from the chosen BOM row, so the row stops
     * being independent and starts behaving exactly like a normal receiving —
     * including feeding the stock ledger, which the model's save hook triggers.
     */
    public function link(Request $request, MaterialReceiving $materialReceiving)
    {
        // Linking changes a recorded receiving and pushes its quantity into the
        // stock ledger, so it is a correction under the same rule as edit — not
        // ordinary data entry.
        $this->authorizeStoreEdit('receiving');

        if (! $materialReceiving->isIndependent()) {
            return back()->with('warning', 'This receiving is already linked to a PO.');
        }

        $validated = $request->validate([
            'booking_po_id' => ['required', 'exists:booking_pos,id'],
            'excel_row_id' => ['required', 'integer'],
        ]);

        $po = BookingPo::with('excelFile')->findOrFail($validated['booking_po_id']);

        if ($locked = $this->lockGuard($po)) {
            return $locked;
        }

        // Same guard as store(): the line must genuinely belong to this PO, or a
        // tampered id would book stock onto another buyer's BOM row.
        $excelRow = app(\App\Services\BookingPoSourceService::class)
            ->itemRowsForBookingPo($po)
            ->keyBy('id')
            ->get((int) $validated['excel_row_id']);

        if (! $excelRow) {
            return back()->withErrors(['excel_row_id' => 'That item does not belong to the selected PO.']);
        }

        // Quantities, dates, remarks and the GRN number are all left alone — the
        // delivery itself did not change, only what it is now known to be against.
        $materialReceiving->fill(array_merge(
            $this->autoFieldsForRow($po, $excelRow),
            [
                'match_status' => MaterialReceiving::MATCH_LINKED,
                'matched_at' => now(),
                'matched_by' => auth()->id(),
            ]
        ))->save();

        return back()->with('success',
            'Receiving '.$materialReceiving->grn_no.' linked to PO '.$po->po_no.'. Closing stock updated.');
    }

    /**
     * Build a unique, human-readable GRN No that encodes buyer / season / year
     * and a global running sequence, reusing the codes already stored on the PO
     * (same convention as PO numbers). Format: GRN-{buyer}-{season}-{YYYY}-{0001}.
     */
    private function generateGrnNo(BookingPo $po, string $receiveDate): string
    {
        return $this->generateGrnNoFrom(
            $po->buyer_code, $po->buyer_name, $po->season_code, $po->season_name, $receiveDate
        );
    }

    /**
     * Same GRN convention, but from loose buyer/season values rather than a PO —
     * an Independent receiving has no PO to read them from, only the style the
     * user picked. The numbering sequence is shared, so an Independent GRN can
     * never collide with a PO-linked one.
     */
    private function generateGrnNoFrom(
        ?string $buyerCode, ?string $buyerName, ?string $seasonCode, ?string $seasonName, string $receiveDate
    ): string {
        $buyer = $this->shortCode($buyerCode, $buyerName, 2);
        $season = $this->seasonCode($seasonCode, $seasonName);
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
        $this->authorizeStoreDelete('receiving');

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
