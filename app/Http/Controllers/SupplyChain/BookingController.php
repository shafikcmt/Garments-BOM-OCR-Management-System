<?php

namespace App\Http\Controllers\SupplyChain;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\BookingPo;
use App\Models\BookingInstruction;
use App\Models\BookingDeliveryDestination;
use App\Models\ExcelCell;
use App\Models\ExcelFileChangeLog;
use App\Models\ExcelHeader;
use App\Models\ExcelRow;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class BookingController extends Controller
{
    protected function bookingRoutePrefix(): string
    {
        return 'supply_chain.bookings';
    }

    protected function bookingRouteName(string $action): string
    {
        return $this->bookingRoutePrefix() . '.' . $action;
    }

    protected function bookingRoute(string $action, mixed $parameters = []): string
    {
        return route($this->bookingRouteName($action), $parameters);
    }

    protected function canControlPo(): bool
    {
        return false;
    }
    public function index(Request $request)
    {
        [$pendingRows, $generatedPos, $filterOptions] = $this->indexData($request);

        return view('supply-chain.bookings.index', compact('pendingRows', 'generatedPos', 'filterOptions'));
    }

    public function data(Request $request)
    {
        [$pendingRows, $generatedPos] = $this->indexData($request, false);

        return response()->json([
            'rows_html' => view('supply-chain.bookings.partials.rows', compact('pendingRows'))->render(),
            'pagination_html' => view('supply-chain.bookings.partials.pagination', compact('pendingRows'))->render(),
            'pending_total' => $pendingRows->total(),
            'recent_total' => $generatedPos->count(),
        ]);
    }

    public function preview(Request $request, ExcelRow $excelRow)
    {
        [$bookingPo, $bookingData, $groupRows] = $this->previewBookingForRow($excelRow);
        $previewMode = ! $bookingPo->exists;
        $generateUrl = $previewMode ? $this->bookingRoute('generate', $excelRow) : null;

        $instructionOptions = $this->bookingInstructionOptions();
        $deliveryDestinationOptions = $this->deliveryDestinationOptions();

        return response()->json([
            'success' => true,
            'message' => 'Booking format preview ready. PO number has not been created yet.',
            'group_rows' => $groupRows->count(),
            'preview_html' => view('supply-chain.bookings.partials.preview', compact('bookingPo', 'bookingData', 'previewMode', 'generateUrl', 'instructionOptions', 'deliveryDestinationOptions'))->render(),
        ]);
    }

    public function bulkPreview(Request $request)
    {
        $rowIds = collect($request->input('rows', []))
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->take(50)
            ->values();

        if ($rowIds->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Please select at least one pending order.',
            ], 422);
        }

        $rows = ExcelRow::query()
            ->whereIn('id', $rowIds)
            ->with(['excelFile', 'cells.header', 'bookingPo'])
            ->get();

        $seenGroups = [];
        $previewHtml = '';
        $previewCount = 0;
        $instructionOptions = $this->bookingInstructionOptions();
        $deliveryDestinationOptions = $this->deliveryDestinationOptions();

        foreach ($rows as $row) {
            $rowData = $this->extractRowData($row);
            $groupKey = $this->bookingGroupKey($rowData) ?: 'row-' . $row->id;

            if (isset($seenGroups[$groupKey])) {
                continue;
            }

            $seenGroups[$groupKey] = true;
            [$bookingPo, $bookingData, $groupRows] = $this->previewBookingForRow($row);
            $previewMode = ! $bookingPo->exists;
            $generateUrl = $previewMode ? $this->bookingRoute('generate', $row) : null;
            $previewHtml .= view('supply-chain.bookings.partials.preview', compact('bookingPo', 'bookingData', 'previewMode', 'generateUrl', 'instructionOptions', 'deliveryDestinationOptions'))->render();
            $previewCount++;
        }

        return response()->json([
            'success' => true,
            'message' => $previewCount . ' booking preview(s) ready. Click Generate PO inside each preview to create PO number.',
            'preview_html' => $previewHtml,
        ]);
    }

    public function generate(Request $request, ExcelRow $excelRow)
    {
        $editData = $this->requestHasBookingEdits($request) ? $this->validateBookingEditRequest($request) : null;
        $bookingPo = $this->generateBookingForRow($excelRow, $editData, $request);
        $bookingPo = $this->syncBookingPoSourceControl($bookingPo, true);
        $bookingPo = $this->appendBookingGenerationHistory($bookingPo, 'generated');
        $this->markBookingPoCompleted($bookingPo);

        $message = 'PO generated successfully: ' . $bookingPo->po_no . '. Booking completed and hidden from pending list.';

        if ($this->isAjaxRequest($request)) {
            $bookingData = $this->bookingData($bookingPo);

            return response()->json([
                'success' => true,
                'message' => $message,
                'po_no' => $bookingPo->po_no,
                'booking_po_id' => $bookingPo->id,
                'preview_html' => view('supply-chain.bookings.partials.preview', array_merge(
                    compact('bookingPo', 'bookingData'),
                    [
                        'previewMode' => false,
                        'generateUrl' => null,
                        'instructionOptions' => $this->bookingInstructionOptions(),
                        'deliveryDestinationOptions' => $this->deliveryDestinationOptions(),
                        'bookingRoutePrefix' => $this->bookingRoutePrefix(),
                        'canControlPo' => $this->canControlPo(),
                    ]
                ))->render(),
            ]);
        }

        return redirect()
            ->route($this->bookingRouteName('index'))
            ->with('success', e($message));
    }


    public function regeneratePreview(Request $request, BookingPo $bookingPo)
    {
        $this->authorizeBookingPo($bookingPo);
        abort_if(! $this->canControlPo(), 403);
        $bookingPo->loadMissing(['excelFile', 'excelRow.cells.header', 'generatedBy']);
        $bookingPo = $this->refreshBookingPoQtyFromSource($bookingPo);
        $bookingPo = $this->syncBookingPoSourceControl($bookingPo);

        $bookingData = $this->bookingDataWithLatestSource($bookingPo);
        $bookingData['po_number'] = $bookingPo->po_no;
        $bookingData['revision_no'] = $this->bookingRevisionNo($bookingPo);

        return response()->json([
            'success' => true,
            'message' => 'Re-generate preview ready. Edit/change first, then click Re-generate PO to confirm.',
            'preview_html' => view('supply-chain.bookings.partials.preview', [
                'bookingPo' => $bookingPo,
                'bookingData' => $bookingData,
                'previewMode' => true,
                'regenerateMode' => true,
                'editPanelOpen' => true,
                'generateUrl' => $this->bookingRoute('regenerate', $bookingPo),
                'instructionOptions' => $this->bookingInstructionOptions(),
                'deliveryDestinationOptions' => $this->deliveryDestinationOptions(),
                'bookingRoutePrefix' => $this->bookingRoutePrefix(),
                'canControlPo' => $this->canControlPo(),
            ])->render(),
        ]);
    }

    public function regenerate(Request $request, BookingPo $bookingPo)
    {
        $this->authorizeBookingPo($bookingPo);
        abort_if(! $this->canControlPo(), 403);
        $bookingPo = $this->syncBookingPoSourceControl($bookingPo);
        $beforeAudit = $this->bookingAuditSnapshot($bookingPo->booking_data ?: []);
        $validated = $this->requestHasBookingEdits($request) ? $this->validateBookingEditRequest($request) : null;

        if ($validated !== null) {
            $bookingPo = $this->applyLatestSourceDataToBookingPo($bookingPo);
            $bookingPo = $this->applyBookingEditDataToPo($bookingPo, $validated, $request);
        } else {
            $bookingPo = $this->applyLatestSourceDataToBookingPo($bookingPo);
        }

        $bookingPo = $this->incrementBookingRevision($bookingPo);
        $bookingPo = $this->syncBookingPoSourceControl($bookingPo, true);
        $bookingPo = $this->appendBookingGenerationHistory($bookingPo, 'regenerated', $beforeAudit);
        $bookingData = $this->bookingData($bookingPo);
        $message = 'PO re-generated successfully: ' . $bookingPo->po_no . ' (R-' . $this->bookingRevisionNo($bookingPo) . ').';

        if ($this->isAjaxRequest($request)) {
            return response()->json([
                'success' => true,
                'message' => $message,
                'po_no' => $bookingPo->po_no,
                'revision_no' => $this->bookingRevisionNo($bookingPo),
                'preview_html' => view('supply-chain.bookings.partials.preview', [
                    'bookingPo' => $bookingPo,
                    'bookingData' => $bookingData,
                    'previewMode' => false,
                    'regenerateMode' => false,
                    'generateUrl' => null,
                    'instructionOptions' => $this->bookingInstructionOptions(),
                    'deliveryDestinationOptions' => $this->deliveryDestinationOptions(),
                    'bookingRoutePrefix' => $this->bookingRoutePrefix(),
                    'canControlPo' => $this->canControlPo(),
                ])->render(),
            ]);
        }

        return redirect()
            ->route($this->bookingRouteName('show'), $bookingPo)
            ->with('success', $message);
    }

    public function bulkGenerate(Request $request)
    {
        $rowIds = collect($request->input('rows', []))
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->take(200)
            ->values();

        if ($rowIds->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Please select at least one pending order.',
            ], 422);
        }

        $rows = ExcelRow::query()
            ->whereIn('id', $rowIds)
            ->with(['excelFile', 'cells.header', 'bookingPo'])
            ->get();

        $generated = [];
        $seenGroups = [];
        $previewPo = null;

        foreach ($rows as $row) {
            $rowData = $this->extractRowData($row);
            $groupKey = $this->bookingGroupKey($rowData) ?: 'row-' . $row->id;

            if (isset($seenGroups[$groupKey])) {
                continue;
            }

            $seenGroups[$groupKey] = true;
            $po = $this->generateBookingForRow($row);
            $po = $this->syncBookingPoSourceControl($po, true);
            $po = $this->appendBookingGenerationHistory($po, 'generated');
            $this->markBookingPoCompleted($po);
            $generated[] = $po->po_no;
            $previewPo ??= $po;
        }

        $message = count($generated) . ' booking group(s) PO generated and completed successfully.';

        $previewHtml = '';
        if ($previewPo) {
            $bookingPo = $previewPo;
            $bookingData = $this->bookingData($bookingPo);
            $previewHtml = view('supply-chain.bookings.partials.preview', compact('bookingPo', 'bookingData'))->render();
        }

        return response()->json([
            'success' => true,
            'message' => $message,
            'po_nos' => $generated,
            'preview_html' => $previewHtml,
        ]);
    }

    /**
     * Combined preview for one or many selected pending orders.
     *
     * - One selected order keeps the existing single-order behavior unchanged.
     * - Multiple selected orders are combined into one preview that will
     *   generate a single PO number for the whole selected batch.
     */
    public function batchPreview(Request $request)
    {
        $rowIds = $this->normalizeSelectedRowIds($request->input('rows', []));

        if ($rowIds->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Please select at least one pending order.',
            ], 422);
        }

        // Single order -> reuse the existing single preview formula as-is.
        if ($rowIds->count() === 1) {
            $row = ExcelRow::query()
                ->with(['excelFile', 'cells.header', 'bookingPo'])
                ->find($rowIds->first());

            if (! $row) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unable to generate PO. Please check selected orders and try again.',
                ], 422);
            }

            return $this->preview($request, $row);
        }

        try {
            $rows = $this->collectBatchRows($rowIds);
        } catch (\RuntimeException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }

        $lineData = $rows
            ->map(fn (ExcelRow $row) => $this->extractRowData($row))
            ->filter(fn (array $data) => $this->hasAnyBookingSourceData($data))
            ->values()
            ->all();

        if (empty($lineData)) {
            return response()->json([
                'success' => false,
                'message' => 'Unable to generate PO. Please check selected orders and try again.',
            ], 422);
        }

        $primaryRow = $rows->first();
        $primaryData = $this->extractRowData($primaryRow);
        $totalQty = collect($lineData)->sum(fn (array $data) => (float) ($this->numericValue($data['qty'] ?? null) ?? 0));

        $bookingPo = new BookingPo([
            'excel_file_id' => $primaryRow->excel_file_id,
            'excel_row_id' => $primaryRow->id,
            'po_no' => '[Generate after preview]',
            'buyer_code' => $this->makeCode($primaryData['buyer_name'] ?? '', 2),
            'season_code' => $this->makeSeasonCode($primaryData['season_name'] ?? ''),
            'buyer_name' => $primaryData['buyer_name'] ?? null,
            'season_name' => $primaryData['season_name'] ?? null,
            'ihod' => $primaryData['ihod'] ?? null,
            'vendor_name' => $primaryData['vendor_name'] ?? null,
            'style_name' => $primaryData['style_name'] ?? null,
            'item_name' => $primaryData['item_name'] ?? null,
            'qty' => $totalQty ?: $this->numericValue($primaryData['qty'] ?? null),
            'uom' => $primaryData['uom'] ?? null,
            'item_type' => $primaryData['item_type'] ?? null,
            'description' => $primaryData['description'] ?? null,
            'color' => $primaryData['color'] ?? null,
            'size_width' => $primaryData['size_width'] ?? null,
            'supplier_article' => $primaryData['supplier_article'] ?? null,
            'consumption' => $this->numericValue($primaryData['consumption'] ?? null),
            'remarks' => $primaryData['remarks'] ?? null,
            'status' => 'preview',
            'generated_by' => auth()->id(),
            'generated_at' => now(),
        ]);

        $bookingData = $this->defaultBookingData($bookingPo, $lineData);
        $bookingData['po_number'] = 'Will be generated after confirmation';

        $selectedOrderCount = $rowIds->count();

        return response()->json([
            'success' => true,
            'message' => 'Selected orders combined. One PO number will be generated for all selected orders.',
            'multiple' => true,
            'selected_count' => $selectedOrderCount,
            'preview_html' => view('supply-chain.bookings.partials.preview', [
                'bookingPo' => $bookingPo,
                'bookingData' => $bookingData,
                'previewMode' => true,
                'generateUrl' => $this->bookingRoute('batch_generate'),
                'instructionOptions' => $this->bookingInstructionOptions(),
                'deliveryDestinationOptions' => $this->deliveryDestinationOptions(),
                'batchMode' => true,
                'batchRowIds' => $rowIds->all(),
                'selectedOrderCount' => $selectedOrderCount,
            ])->render(),
        ]);
    }

    /**
     * Generate a single PO number for many selected orders inside one safe transaction.
     *
     * One selected order is delegated to the existing single-order generate formula.
     */
    public function batchGenerate(Request $request)
    {
        $rowIds = $this->normalizeSelectedRowIds($request->input('rows', []));

        if ($rowIds->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Please select at least one pending order.',
            ], 422);
        }

        // Single order -> reuse the existing single generate formula as-is.
        if ($rowIds->count() === 1) {
            $row = ExcelRow::query()->find($rowIds->first());

            if (! $row) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unable to generate PO. Please check selected orders and try again.',
                ], 422);
            }

            return $this->generate($request, $row);
        }

        $editData = $this->requestHasBookingEdits($request) ? $this->validateBookingEditRequest($request) : null;

        try {
            $bookingPo = DB::transaction(function () use ($rowIds, $editData, $request) {
                $rows = $this->collectBatchRows($rowIds);

                $lockedRows = ExcelRow::query()
                    ->whereIn('id', $rows->pluck('id')->all())
                    ->lockForUpdate()
                    ->with(['excelFile', 'cells.header', 'bookingPo'])
                    ->get()
                    ->sortBy(fn (ExcelRow $row) => $rows->search(fn (ExcelRow $original) => $original->id === $row->id))
                    ->values();

                // Re-validate under lock so no row was generated by a parallel request.
                foreach ($lockedRows as $row) {
                    if ($row->bookingPo) {
                        throw new \RuntimeException('Some selected orders already have PO generated.');
                    }
                }

                $primaryRow = $lockedRows->first();
                $primaryData = $this->extractRowData($primaryRow);

                $buyerCode = $this->makeCode($primaryData['buyer_name'] ?? '', 2);
                $seasonCode = $this->makeSeasonCode($primaryData['season_name'] ?? '');
                $generatedAt = now();
                $poNo = $this->makeUniquePoNo($buyerCode, $seasonCode);

                $lineData = $lockedRows
                    ->map(fn (ExcelRow $row) => $this->extractRowData($row))
                    ->filter(fn (array $data) => $this->hasAnyBookingSourceData($data))
                    ->values()
                    ->all();

                if (empty($lineData)) {
                    $lineData = [$primaryData];
                }

                $totalQty = collect($lineData)->sum(fn (array $data) => (float) ($this->numericValue($data['qty'] ?? null) ?? 0));

                $bookingPo = BookingPo::create([
                    'excel_file_id' => $primaryRow->excel_file_id,
                    'excel_row_id' => $primaryRow->id,
                    'po_no' => $poNo,
                    'buyer_code' => $buyerCode,
                    'season_code' => $seasonCode,
                    'buyer_name' => $primaryData['buyer_name'] ?? null,
                    'season_name' => $primaryData['season_name'] ?? null,
                    'ihod' => $primaryData['ihod'] ?? null,
                    'vendor_name' => $primaryData['vendor_name'] ?? null,
                    'style_name' => $primaryData['style_name'] ?? null,
                    'item_name' => $primaryData['item_name'] ?? null,
                    'qty' => $totalQty ?: $this->numericValue($primaryData['qty'] ?? null),
                    'uom' => $primaryData['uom'] ?? null,
                    'item_type' => $primaryData['item_type'] ?? null,
                    'description' => $primaryData['description'] ?? null,
                    'color' => $primaryData['color'] ?? null,
                    'size_width' => $primaryData['size_width'] ?? null,
                    'supplier_article' => $primaryData['supplier_article'] ?? null,
                    'consumption' => $this->numericValue($primaryData['consumption'] ?? null),
                    'remarks' => $primaryData['remarks'] ?? null,
                    'booking_data' => null,
                    'status' => 'applied',
                    'generated_by' => auth()->id(),
                    'generated_at' => $generatedAt,
                ]);

                $bookingPo->booking_data = $this->defaultBookingData($bookingPo, $lineData);
                $bookingPo->save();

                if ($editData !== null) {
                    $bookingPo = $this->applyBookingEditDataToPo($bookingPo, $editData, $request);
                }

                // Assign the same PO number to every selected related row.
                foreach ($lockedRows as $row) {
                    $this->syncPoToWorkspace($row, $poNo, $generatedAt);
                }

                return $bookingPo;
            });
        } catch (\RuntimeException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }

        $bookingPo = $this->syncBookingPoSourceControl($bookingPo, true);
        $bookingPo = $this->appendBookingGenerationHistory($bookingPo, 'generated');
        $this->markBookingPoCompleted($bookingPo);

        $bookingData = $this->bookingData($bookingPo);
        $message = 'PO generated successfully. One PO number has been assigned to all selected orders: ' . $bookingPo->po_no . '.';

        return response()->json([
            'success' => true,
            'message' => $message,
            'po_no' => $bookingPo->po_no,
            'booking_po_id' => $bookingPo->id,
            'preview_html' => view('supply-chain.bookings.partials.preview', array_merge(
                compact('bookingPo', 'bookingData'),
                [
                    'previewMode' => false,
                    'generateUrl' => null,
                    'instructionOptions' => $this->bookingInstructionOptions(),
                    'deliveryDestinationOptions' => $this->deliveryDestinationOptions(),
                    'bookingRoutePrefix' => $this->bookingRoutePrefix(),
                    'canControlPo' => $this->canControlPo(),
                ]
            ))->render(),
        ]);
    }

    protected function normalizeSelectedRowIds($input): \Illuminate\Support\Collection
    {
        return collect(is_array($input) ? $input : [$input])
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->take(200)
            ->values();
    }

    /**
     * Validate selected leader rows and expand them into one combinable batch.
     *
     * Throws a RuntimeException with a friendly message when the selection
     * is invalid, already generated, or spans more than one vendor/supplier.
     */
    protected function collectBatchRows(\Illuminate\Support\Collection $leaderIds): \Illuminate\Support\Collection
    {
        $leaders = ExcelRow::query()
            ->whereIn('id', $leaderIds->all())
            ->with(['excelFile', 'cells.header', 'bookingPo'])
            ->get();

        if ($leaders->count() !== $leaderIds->count()) {
            throw new \RuntimeException('Unable to generate PO. Please check selected orders and try again.');
        }

        foreach ($leaders as $leader) {
            if (! $leader->excelFile) {
                throw new \RuntimeException('Unable to generate PO. Please check selected orders and try again.');
            }

            if ($leader->bookingPo) {
                throw new \RuntimeException('Some selected orders already have PO generated.');
            }
        }

        // Keep the user-selected order and expand each selection to its pending group.
        $rows = collect();
        foreach ($leaderIds as $leaderId) {
            $leader = $leaders->firstWhere('id', $leaderId);
            if (! $leader) {
                continue;
            }

            $groupRows = $this->pendingRowsForSameBookingGroup($leader, $this->extractRowData($leader));
            if ($groupRows->isEmpty()) {
                $groupRows = collect([$leader]);
            }

            foreach ($groupRows as $row) {
                $rows->put($row->id, $row);
            }
        }

        $rows = $rows->values();

        if ($rows->isEmpty()) {
            throw new \RuntimeException('Unable to generate PO. Please check selected orders and try again.');
        }

        foreach ($rows as $row) {
            if ($row->bookingPo) {
                throw new \RuntimeException('Some selected orders already have PO generated.');
            }
        }

        // A PO is issued to a single supplier, so every selected order must share the same vendor.
        $vendors = $rows
            ->map(fn (ExcelRow $row) => $this->normalize($this->extractRowData($row)['vendor_name'] ?? ''))
            ->filter(fn (string $vendor) => $vendor !== '')
            ->unique()
            ->values();

        if ($vendors->count() > 1) {
            throw new \RuntimeException('Selected orders cannot be combined because vendor/supplier is different.');
        }

        return $rows;
    }

    protected function bookingSourceControlFields(): array
    {
        return [
            'buyer_name' => 'Buyer',
            'season_name' => 'Season',
            'ihod' => 'IHOD',
            'vendor_name' => 'Vendor',
            'style_name' => 'Style',
            'item_name' => 'Item',
            'qty' => 'Booking Qty',
            'pp_qty' => 'PP Qty',
            'uom' => 'UOM',
            'item_type' => 'Item Type',
            'description' => 'Description',
            'color' => 'Color',
            'size' => 'Size',
            'width' => 'Width',
            'size_width' => 'Size / Width',
            'supplier_article' => 'Supplier Article',
            'consumption' => 'Consumption',
            'remarks' => 'Remarks',
        ];
    }

    protected function bookingSourceSnapshot(BookingPo $bookingPo): array
    {
        $bookingPo->loadMissing(['excelRow.cells.header']);

        if (! $bookingPo->excelRow) {
            return [];
        }

        $rowData = $this->extractRowData($bookingPo->excelRow);
        $snapshot = [];

        foreach ($this->bookingSourceControlFields() as $key => $label) {
            $snapshot[$key] = [
                'label' => $label,
                'value' => $this->normalizeSnapshotValue($rowData[$key] ?? null),
            ];
        }

        return $snapshot;
    }

    protected function normalizeSnapshotValue($value): string
    {
        if ($value === null) {
            return '';
        }

        return trim(preg_replace('/\s+/', ' ', (string) $value));
    }

    protected function bookingSourceSnapshotChanges(array $baseline, array $current): array
    {
        $changes = [];

        foreach ($this->bookingSourceControlFields() as $key => $label) {
            $before = $this->normalizeSnapshotValue($baseline[$key]['value'] ?? $baseline[$key] ?? null);
            $after = $this->normalizeSnapshotValue($current[$key]['value'] ?? $current[$key] ?? null);

            if ($before === $after) {
                continue;
            }

            $changes[] = [
                'key' => $key,
                'label' => $label,
                'before' => $before,
                'after' => $after,
            ];
        }

        return $changes;
    }

    protected function syncBookingPoSourceControl(BookingPo $bookingPo, bool $resetBaseline = false): BookingPo
    {
        $bookingPo = $bookingPo->fresh(['excelRow.cells.header', 'generatedBy', 'completedBy']) ?: $bookingPo;
        $data = $bookingPo->booking_data ?: [];
        $currentSnapshot = $this->bookingSourceSnapshot($bookingPo);

        if (empty($currentSnapshot)) {
            return $bookingPo;
        }

        if ($resetBaseline || empty($data['source_snapshot']) || ! is_array($data['source_snapshot'])) {
            $data['source_snapshot'] = $currentSnapshot;
            $data['source_current_snapshot'] = $currentSnapshot;
            $data['source_change_log'] = [];
            $data['source_changed_at'] = null;
            $data['needs_regenerate'] = false;
        } else {
            $changes = $this->bookingSourceSnapshotChanges($data['source_snapshot'], $currentSnapshot);
            $data['source_current_snapshot'] = $currentSnapshot;
            $data['source_change_log'] = $changes;
            $data['source_changed_at'] = ! empty($changes) ? now()->format('Y-m-d H:i:s') : null;
            $data['needs_regenerate'] = ! empty($changes);
        }

        $bookingPo->booking_data = $data;
        $bookingPo->save();

        return $bookingPo->fresh(['excelRow.cells.header', 'generatedBy', 'completedBy']) ?: $bookingPo;
    }

    protected function bookingAuditSnapshot(array $bookingData): array
    {
        $snapshot = [];
        $scalarLabels = [
            'to' => 'Vendor / To',
            'attn' => 'Attention',
            'email' => 'Email',
            'address' => 'Supplier Address',
            'date' => 'Date',
            'buyer' => 'Buyer',
            'season' => 'Season',
            'from' => 'From',
            'po_number' => 'PO Number',
            'supplier' => 'Supplier',
            'incoterm' => 'Incoterm',
            'item_type' => 'Item Type',
            'ship_mode' => 'Ship Mode',
            'order_style_no' => 'Style No',
            'tolerance' => 'Tolerance',
            'consignee' => 'Consignee',
            'delivery_destination_name' => 'Delivery Destination',
            'delivery_destination_details' => 'Delivery Details',
            'best_regards' => 'Best Regards',
        ];

        foreach ($scalarLabels as $key => $label) {
            $snapshot[$key] = [
                'label' => $label,
                'value' => $this->normalizeSnapshotValue($bookingData[$key] ?? null),
            ];
        }

        foreach (($bookingData['items'] ?? []) as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            foreach ([
                'style_order' => 'Style',
                'item_name' => 'Item',
                'description' => 'Description',
                'color' => 'Color',
                'size' => 'Size',
                'width' => 'Width',
                'supplier_article' => 'Supplier Article',
                'booking_qty' => 'Booking Qty',
                'pp_qty' => 'PP Qty',
                'uom' => 'UOM',
                'remarks' => 'Remarks',
            ] as $key => $label) {
                $snapshot['items.' . ($index + 1) . '.' . $key] = [
                    'label' => 'Line ' . ($index + 1) . ' ' . $label,
                    'value' => $this->normalizeSnapshotValue($item[$key] ?? null),
                ];
            }
        }

        return $snapshot;
    }

    protected function bookingAuditChanges(array $before, array $after): array
    {
        $keys = collect(array_keys($before))->merge(array_keys($after))->unique()->values()->all();
        $changes = [];

        foreach ($keys as $key) {
            $beforeValue = $this->normalizeSnapshotValue($before[$key]['value'] ?? null);
            $afterValue = $this->normalizeSnapshotValue($after[$key]['value'] ?? null);

            if ($beforeValue === $afterValue) {
                continue;
            }

            $changes[] = [
                'key' => $key,
                'label' => $after[$key]['label'] ?? $before[$key]['label'] ?? Str::headline(str_replace('.', ' ', $key)),
                'before' => $beforeValue,
                'after' => $afterValue,
            ];
        }

        return $changes;
    }

    protected function appendBookingGenerationHistory(BookingPo $bookingPo, string $action, ?array $beforeAudit = null): BookingPo
    {
        $bookingPo = $bookingPo->fresh(['generatedBy', 'completedBy']) ?: $bookingPo;
        $data = $bookingPo->booking_data ?: [];
        $afterAudit = $this->bookingAuditSnapshot($data);
        $history = collect($data['generation_history'] ?? []);

        if ($action === 'generated' && $history->contains(fn ($entry) => ($entry['action'] ?? '') === 'generated')) {
            return $bookingPo;
        }

        $history->push([
            'action' => $action,
            'revision_no' => $this->bookingRevisionNo($bookingPo),
            'po_no' => $bookingPo->po_no,
            'changed_by' => auth()->id(),
            'changed_by_name' => optional(auth()->user())->name,
            'changed_at' => now()->format('Y-m-d H:i:s'),
            'changes' => $beforeAudit ? $this->bookingAuditChanges($beforeAudit, $afterAudit) : [],
            'source_changes' => $data['source_change_log'] ?? [],
        ]);

        $data['generation_history'] = $history->take(-25)->values()->all();
        $bookingPo->booking_data = $data;
        $bookingPo->save();

        return $bookingPo->fresh(['excelRow.cells.header', 'generatedBy', 'completedBy']) ?: $bookingPo;
    }


    protected function bookingRevisionNo(BookingPo $bookingPo): int
    {
        $data = $bookingPo->booking_data ?: [];
        return max(0, (int) ($data['revision_no'] ?? 0));
    }

    protected function incrementBookingRevision(BookingPo $bookingPo): BookingPo
    {
        $bookingPo = $bookingPo->fresh() ?: $bookingPo;
        $data = $bookingPo->booking_data ?: [];
        $data['revision_no'] = $this->bookingRevisionNo($bookingPo) + 1;
        $data['po_number'] = $bookingPo->po_no;
        $data['needs_regenerate'] = false;

        $bookingPo->booking_data = $data;
        $bookingPo->generated_at = now();
        $bookingPo->generated_by = auth()->id() ?: $bookingPo->generated_by;
        if (($bookingPo->status ?? null) !== 'completed') {
            $bookingPo->status = 'completed';
            $bookingPo->completed_at = $bookingPo->completed_at ?: now();
            $bookingPo->completed_by = $bookingPo->completed_by ?: auth()->id();
        }
        $bookingPo->save();

        return $bookingPo->fresh() ?: $bookingPo;
    }

    protected function markBookingPoCompleted(BookingPo $bookingPo): void
    {
        if (($bookingPo->status ?? null) === 'completed') {
            return;
        }

        $bookingPo->update([
            'status' => 'completed',
            'completed_at' => now(),
            'completed_by' => auth()->id(),
        ]);
    }

    public function complete(Request $request, BookingPo $bookingPo)
    {
        $this->authorizeBookingPo($bookingPo);
        abort_if(! $this->canControlPo(), 403);

        $bookingPo->update([
            'status' => 'completed',
            'completed_at' => now(),
            'completed_by' => auth()->id(),
        ]);

        $message = 'Booking confirmed complete. Order hidden from pending table.';

        if ($this->isAjaxRequest($request)) {
            return response()->json([
                'success' => true,
                'message' => $message,
            ]);
        }

        return redirect()
            ->route($this->bookingRouteName('index'))
            ->with('success', $message);
    }

    public function bulkComplete(Request $request)
    {
        abort_if(! $this->canControlPo(), 403);
        $poIds = collect($request->input('booking_pos', []))
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->take(200)
            ->values();

        if ($poIds->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Please select at least one PO applied order.',
            ], 422);
        }

        $updated = BookingPo::query()
            ->whereIn('id', $poIds)
            ->where(function ($query) {
                $query->whereNull('status')->orWhere('status', '!=', 'completed');
            })
            ->update([
                'status' => 'completed',
                'completed_at' => now(),
                'completed_by' => auth()->id(),
                'updated_at' => now(),
            ]);

        return response()->json([
            'success' => true,
            'message' => $updated . ' booking order(s) confirmed complete and hidden from pending table.',
        ]);
    }

    public function show(BookingPo $bookingPo)
    {
        $this->authorizeBookingPo($bookingPo);
        $bookingPo->loadMissing(['excelFile', 'excelRow.cells.header', 'generatedBy']);
        $bookingPo = $this->syncBookingPoSourceControl($bookingPo);

        $bookingData = $this->bookingData($bookingPo);
        $instructionOptions = $this->bookingInstructionOptions();
        $deliveryDestinationOptions = $this->deliveryDestinationOptions();

        return view('supply-chain.bookings.show', compact('bookingPo', 'bookingData', 'instructionOptions', 'deliveryDestinationOptions'));
    }

    public function update(Request $request, BookingPo $bookingPo)
    {
        $this->authorizeBookingPo($bookingPo);
        abort_if(! $this->canControlPo(), 403);

        $validated = $this->validateBookingEditRequest($request);
        $beforeAudit = $this->bookingAuditSnapshot($bookingPo->booking_data ?: []);
        $bookingPo = $this->applyBookingEditDataToPo($bookingPo, $validated, $request);
        $this->appendBookingGenerationHistory($bookingPo, 'updated', $beforeAudit);

        return redirect()
            ->route($this->bookingRouteName('show'), $bookingPo)
            ->with('success', 'Booking format updated successfully.');
    }

    public function print(BookingPo $bookingPo)
    {
        $this->authorizeBookingPo($bookingPo);

        $bookingData = $this->bookingData($bookingPo);
        $autoPrint = false;
        $isPdf = false;

        return view('supply-chain.bookings.print', compact('bookingPo', 'bookingData', 'autoPrint', 'isPdf'));
    }

    public function download(BookingPo $bookingPo)
    {
        $this->authorizeBookingPo($bookingPo);

        $bookingData = $this->bookingData($bookingPo);

        if (class_exists('Barryvdh\\DomPDF\\Facade\\Pdf')) {
            $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('supply-chain.bookings.print', [
                'bookingPo' => $bookingPo,
                'bookingData' => $bookingData,
                'autoPrint' => false,
                'isPdf' => true,
            ])->setPaper('a4', 'portrait');

            return $pdf->download($bookingPo->po_no . '_booking_format.pdf');
        }

        $autoPrint = true;
        $isPdf = false;

        return view('supply-chain.bookings.print', compact('bookingPo', 'bookingData', 'autoPrint', 'isPdf'));
    }

    public function downloadExcel(BookingPo $bookingPo)
    {
        $this->authorizeBookingPo($bookingPo);

        $bookingData = $this->bookingData($bookingPo);
        $fileName = $bookingPo->po_no . '_booking_format.xls';

        return response()
            ->view('supply-chain.bookings.excel', compact('bookingPo', 'bookingData'))
            ->header('Content-Type', 'application/vnd.ms-excel; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="' . $fileName . '"')
            ->header('Pragma', 'no-cache')
            ->header('Expires', '0');
    }

    protected function authorizeBookingPo(BookingPo $bookingPo): void
    {
        abort_if(! auth()->user()?->hasRole('supply_chain'), 403);
    }

    protected function indexData(Request $request, bool $includeFilters = true): array
    {
        $pendingRows = $this->buildPendingRowsQuery($request)
            ->latest('id')
            ->paginate(50)
            ->withQueryString();

        $this->decoratePendingRows($pendingRows);

        $generatedPos = BookingPo::query()
            ->with(['excelFile', 'excelRow.cells.header', 'generatedBy', 'completedBy'])
            ->latest('id')
            ->take(50)
            ->get()
            ->map(function (BookingPo $po) {
                $po = $this->refreshBookingPoQtyFromSource($po);
                return $this->syncBookingPoSourceControl($po);
            });

        $filterOptions = [];
        if ($includeFilters) {
            $filterOptions = [
                'buyers' => $this->dropdownOptions('buyer'),
                'seasons' => $this->dropdownOptions('season'),
                'sap_codes' => $this->dropdownOptions('sap_code'),
                'vendors' => $this->dropdownOptions('vendor'),
                'ihods' => $this->dropdownOptions('ihod'),
            ];
        }

        return [$pendingRows, $generatedPos, $filterOptions];
    }

    protected function buildPendingRowsQuery(Request $request)
    {
        $pendingRowsQuery = ExcelRow::query()
            ->with(['excelFile', 'cells.header', 'bookingPo'])
            ->whereHas('excelFile')
            ->where(function ($query) {
                $query->where(function ($notApplied) {
                    $notApplied->whereDoesntHave('bookingPo');
                    $this->applyPendingPoFilter($notApplied);
                })->orWhereHas('bookingPo', function ($poQuery) {
                    $poQuery->whereNull('status')->orWhere('status', '!=', 'completed');
                });
            });

        $this->applyHasBookingSourceData($pendingRowsQuery);
        $this->applyCellFilter($pendingRowsQuery, 'buyer', $request->input('buyer'));
        $this->applyCellFilter($pendingRowsQuery, 'season', $request->input('season'));
        $this->applyCellFilter($pendingRowsQuery, 'sap_code', $request->input('sap_code'));
        $this->applyCellFilter($pendingRowsQuery, 'vendor', $request->input('vendor'));
        $this->applyCellFilter($pendingRowsQuery, 'ihod', $request->input('ihod'));
        $this->applyKeywordFilter($pendingRowsQuery, $request->input('keyword'));

        return $pendingRowsQuery;
    }

    protected function decoratePendingRows($pendingRows): void
    {
        $seenGroups = [];
        $decorated = collect();

        foreach ($pendingRows->getCollection() as $row) {
            $row->booking_preview = $this->extractRowData($row);
            $row->booking_status = $this->rowBookingStatus($row);
            $row->booking_group_count = 1;
            $row->booking_group_qty_total = $this->numericValue($row->booking_preview['qty'] ?? null);
            $row->booking_group_items = [$row->booking_preview['item_name'] ?? null];

            $groupKey = $this->bookingGroupKey($row->booking_preview);

            if (! $row->bookingPo && $groupKey !== '') {
                if (isset($seenGroups[$groupKey])) {
                    $existing = $seenGroups[$groupKey];
                    $existing->booking_group_count = (int) ($existing->booking_group_count ?? 1) + 1;
                    $existing->booking_group_qty_total = (float) ($existing->booking_group_qty_total ?? 0) + (float) ($this->numericValue($row->booking_preview['qty'] ?? null) ?? 0);
                    $items = collect($existing->booking_group_items ?? [])
                        ->push($row->booking_preview['item_name'] ?? null)
                        ->filter()
                        ->unique()
                        ->values()
                        ->all();
                    $existing->booking_group_items = $items;
                    continue;
                }

                $seenGroups[$groupKey] = $row;
            }

            if ($row->bookingPo) {
                $controlledPo = $this->syncBookingPoSourceControl($row->bookingPo);
                $row->setRelation('bookingPo', $controlledPo);
                $row->booking_needs_regenerate = (bool) ($controlledPo->needs_regenerate ?? false);
                $row->booking_revision_no = (int) ($controlledPo->revision_no ?? 0);

                $storedItems = $controlledPo->booking_data['items'] ?? [];
                if (count($storedItems) > 1) {
                    $row->booking_group_count = count($storedItems);
                    $row->booking_group_items = collect($storedItems)->pluck('item_name')->filter()->unique()->values()->all();
                }
            }

            $decorated->push($row);
        }

        $pendingRows->setCollection($decorated->values());
    }

    protected function rowBookingStatus(ExcelRow $row): string
    {
        if (! $row->bookingPo) {
            return 'pending';
        }

        return $row->bookingPo->status ?: 'applied';
    }

    protected function isAjaxRequest(Request $request): bool
    {
        return $request->ajax() || $request->wantsJson();
    }

    protected function previewBookingForRow(ExcelRow $excelRow): array
    {
        $excelRow->loadMissing(['excelFile', 'cells.header', 'bookingPo']);

        abort_if(! $excelRow->excelFile, 404);

        if ($excelRow->bookingPo && $excelRow->bookingPo->status !== 'completed') {
            $bookingPo = $excelRow->bookingPo->loadMissing(['excelFile', 'excelRow.cells.header', 'generatedBy']);
            $bookingPo = $this->refreshBookingPoQtyFromSource($bookingPo);
            return [$bookingPo, $this->bookingData($bookingPo), collect([$excelRow])];
        }

        $rowData = $this->extractRowData($excelRow);
        $groupRows = $this->pendingRowsForSameBookingGroup($excelRow, $rowData);

        if ($groupRows->isEmpty()) {
            $groupRows = collect([$excelRow]);
        }

        $primaryRow = $groupRows->firstWhere('id', $excelRow->id) ?: $groupRows->first();
        $primaryData = $this->extractRowData($primaryRow);
        $lineData = $groupRows
            ->map(fn (ExcelRow $row) => $this->extractRowData($row))
            ->filter(fn (array $data) => $this->hasAnyBookingSourceData($data))
            ->values()
            ->all();

        if (empty($lineData)) {
            $lineData = [$primaryData];
        }

        $totalQty = collect($lineData)->sum(fn (array $data) => (float) ($this->numericValue($data['qty'] ?? null) ?? 0));

        $bookingPo = new BookingPo([
            'excel_file_id' => $primaryRow->excel_file_id,
            'excel_row_id' => $primaryRow->id,
            'po_no' => '[Generate after preview]',
            'buyer_code' => $this->makeCode($primaryData['buyer_name'] ?? '', 2),
            'season_code' => $this->makeSeasonCode($primaryData['season_name'] ?? ''),
            'buyer_name' => $primaryData['buyer_name'] ?? null,
            'season_name' => $primaryData['season_name'] ?? null,
            'ihod' => $primaryData['ihod'] ?? null,
            'vendor_name' => $primaryData['vendor_name'] ?? null,
            'style_name' => $primaryData['style_name'] ?? null,
            'item_name' => $primaryData['item_name'] ?? null,
            'qty' => $totalQty ?: $this->numericValue($primaryData['qty'] ?? null),
            'uom' => $primaryData['uom'] ?? null,
            'item_type' => $primaryData['item_type'] ?? null,
            'description' => $primaryData['description'] ?? null,
            'color' => $primaryData['color'] ?? null,
            'size_width' => $primaryData['size_width'] ?? null,
            'supplier_article' => $primaryData['supplier_article'] ?? null,
            'consumption' => $this->numericValue($primaryData['consumption'] ?? null),
            'remarks' => $primaryData['remarks'] ?? null,
            'status' => 'preview',
            'generated_by' => auth()->id(),
            'generated_at' => now(),
        ]);

        $bookingData = $this->defaultBookingData($bookingPo, $lineData);
        $bookingData['po_number'] = 'Will be generated after confirmation';

        return [$bookingPo, $bookingData, $groupRows];
    }

    protected function generateBookingForRow(ExcelRow $excelRow, ?array $editData = null, ?Request $request = null): BookingPo
    {
        $excelRow->loadMissing(['excelFile', 'cells.header', 'bookingPo']);

        abort_if(! $excelRow->excelFile, 404);

        return DB::transaction(function () use ($excelRow, $editData, $request) {
            $excelRow = ExcelRow::query()
                ->whereKey($excelRow->id)
                ->lockForUpdate()
                ->with(['excelFile', 'cells.header', 'bookingPo'])
                ->firstOrFail();

            if ($excelRow->bookingPo) {
                if ($excelRow->bookingPo->status === 'completed') {
                    $completedPo = $this->refreshBookingPoQtyFromSource($excelRow->bookingPo);
                    if ($editData !== null) {
                        $completedPo = $this->applyBookingEditDataToPo($completedPo, $editData, $request);
                    }

                    return $completedPo;
                }

                if (! $excelRow->bookingPo->status) {
                    $excelRow->bookingPo->status = 'applied';
                    $excelRow->bookingPo->save();
                }

                $existingPo = $this->refreshBookingPoQtyFromSource($excelRow->bookingPo);
                if ($editData !== null) {
                    $existingPo = $this->applyBookingEditDataToPo($existingPo, $editData, $request);
                }

                return $existingPo;
            }

            $rowData = $this->extractRowData($excelRow);
            $groupRows = $this->pendingRowsForSameBookingGroup($excelRow, $rowData);

            if ($groupRows->isEmpty()) {
                $groupRows = collect([$excelRow]);
            }

            $primaryRow = $groupRows->firstWhere('id', $excelRow->id) ?: $groupRows->first();
            $primaryData = $this->extractRowData($primaryRow);

            $buyerCode = $this->makeCode($primaryData['buyer_name'] ?? '', 2);
            $seasonCode = $this->makeSeasonCode($primaryData['season_name'] ?? '');
            $generatedAt = now();
            $poNo = $this->makeUniquePoNo($buyerCode, $seasonCode);

            $lineData = $groupRows
                ->map(fn (ExcelRow $row) => $this->extractRowData($row))
                ->filter(fn (array $data) => $this->hasAnyBookingSourceData($data))
                ->values()
                ->all();

            if (empty($lineData)) {
                $lineData = [$primaryData];
            }

            $totalQty = collect($lineData)->sum(fn (array $data) => (float) ($this->numericValue($data['qty'] ?? null) ?? 0));

            $bookingPo = BookingPo::create([
                'excel_file_id' => $primaryRow->excel_file_id,
                'excel_row_id' => $primaryRow->id,
                'po_no' => $poNo,
                'buyer_code' => $buyerCode,
                'season_code' => $seasonCode,
                'buyer_name' => $primaryData['buyer_name'] ?? null,
                'season_name' => $primaryData['season_name'] ?? null,
                'ihod' => $primaryData['ihod'] ?? null,
                'vendor_name' => $primaryData['vendor_name'] ?? null,
                'style_name' => $primaryData['style_name'] ?? null,
                'item_name' => $primaryData['item_name'] ?? null,
                'qty' => $totalQty ?: $this->numericValue($primaryData['qty'] ?? null),
                'uom' => $primaryData['uom'] ?? null,
                'item_type' => $primaryData['item_type'] ?? null,
                'description' => $primaryData['description'] ?? null,
                'color' => $primaryData['color'] ?? null,
                'size_width' => $primaryData['size_width'] ?? null,
                'supplier_article' => $primaryData['supplier_article'] ?? null,
                'consumption' => $this->numericValue($primaryData['consumption'] ?? null),
                'remarks' => $primaryData['remarks'] ?? null,
                'booking_data' => null,
                'status' => 'applied',
                'generated_by' => auth()->id(),
                'generated_at' => $generatedAt,
            ]);

            $bookingPo->booking_data = $this->defaultBookingData($bookingPo, $lineData);
            $bookingPo->save();

            if ($editData !== null) {
                $bookingPo = $this->applyBookingEditDataToPo($bookingPo, $editData, $request);
            }

            foreach ($groupRows as $row) {
                $this->syncPoToWorkspace($row, $poNo, $generatedAt);
            }

            return $bookingPo;
        });
    }

    protected function pendingRowsForSameBookingGroup(ExcelRow $primaryRow, array $rowData)
    {
        $groupKey = $this->bookingGroupKey($rowData);

        if ($groupKey === '') {
            return collect([$primaryRow]);
        }

        $query = ExcelRow::query()
            ->with(['excelFile', 'cells.header', 'bookingPo'])
            ->whereHas('excelFile')
            ->whereDoesntHave('bookingPo');

        $this->applyPendingPoFilter($query);
        $this->applyHasBookingSourceData($query);

        // Coarse narrowing must follow the same scheme as bookingGroupKey(): when a SAP
        // Code drives the group, narrow by vendor + SAP Code (styles/buyers may differ);
        // otherwise keep the legacy buyer/season/ihod/vendor narrowing.
        if (trim((string) ($rowData['sap_code'] ?? '')) !== '') {
            $this->applyCellFilter($query, 'vendor', $rowData['vendor_name'] ?? null);
            $this->applyCellFilter($query, 'sap_code', $rowData['sap_code'] ?? null);
        } else {
            $this->applyCellFilter($query, 'buyer', $rowData['buyer_name'] ?? null);
            $this->applyCellFilter($query, 'season', $rowData['season_name'] ?? null);
            $this->applyCellFilter($query, 'ihod', $rowData['ihod'] ?? null);
            $this->applyCellFilter($query, 'vendor', $rowData['vendor_name'] ?? null);
        }

        return $query
            ->orderBy('excel_file_id')
            ->orderBy('row_number')
            ->limit(500)
            ->get()
            ->filter(function (ExcelRow $row) use ($groupKey) {
                return $this->bookingGroupKey($this->extractRowData($row)) === $groupKey;
            })
            ->values();
    }

    protected function bookingGroupKey(array $data): string
    {
        // Business rule: group same SAP Code (material) for the same vendor into one
        // booking/PO, even when the styles differ. When a SAP Code is present it drives
        // the grouping; otherwise fall back to the legacy buyer/season/ihod/vendor key so
        // existing data without a SAP Code keeps its previous behaviour.
        $sapCode = $this->normalize($data['sap_code'] ?? '');

        if ($sapCode !== '') {
            return 'sap|' . $this->normalize($data['vendor_name'] ?? '') . '|' . $sapCode;
        }

        $parts = [
            $this->normalize($data['buyer_name'] ?? ''),
            $this->normalize($data['season_name'] ?? ''),
            $this->normalize($data['ihod'] ?? ''),
            $this->normalize($data['vendor_name'] ?? ''),
        ];

        if (collect($parts)->filter()->isEmpty()) {
            return '';
        }

        return implode('|', $parts);
    }

    protected array $headerIdCache = [];

    protected function dropdownOptions(string $group)
    {
        $headerIds = $this->headerIdsForGroup($group);

        if (empty($headerIds)) {
            return collect();
        }

        return ExcelCell::query()
            ->whereIn('header_id', $headerIds)
            ->whereNotNull('value')
            ->whereRaw("TRIM(value) <> ''")
            ->distinct()
            ->orderBy('value')
            ->limit(800)
            ->pluck('value')
            ->map(fn ($value) => trim((string) $value))
            ->filter(fn ($value) => $value !== '')
            ->unique(fn ($value) => $this->normalize($value))
            ->values();
    }

    protected function applyPendingPoFilter($query): void
    {
        // Pending means both workspace columns are still empty or contain placeholder values:
        // 1) Material PO Number  2) PO Date
        $this->whereHeaderGroupHasNoRealValue($query, 'po_no');
        $this->whereHeaderGroupHasNoRealValue($query, 'po_date');
    }

    protected function whereHeaderGroupHasNoRealValue($query, string $group): void
    {
        $headerIds = $this->headerIdsForGroup($group);

        if (empty($headerIds)) {
            return;
        }

        $pendingValues = [
            'na', 'n/a', 'n_a', 'none', 'nil', 'no', 'not available', 'not_available',
            'pending', 'po pending', 'pending po', 'waiting for po', 'wait for po',
            'waiting for po number', 'blank', 'empty', '0', '-', '--', '.',
            'mm/dd/yyyy', 'dd/mm/yyyy', 'yyyy-mm-dd',
        ];

        $placeholders = implode(',', array_fill(0, count($pendingValues), '?'));

        $query->whereDoesntHave('cells', function ($cellQuery) use ($headerIds, $pendingValues, $placeholders) {
            $cellQuery->whereIn('header_id', $headerIds)
                ->whereNotNull('value')
                ->whereRaw("TRIM(value) <> ''")
                ->whereRaw("LOWER(TRIM(value)) NOT IN ($placeholders)", $pendingValues);
        });
    }

    protected function applyHasBookingSourceData($query): void
    {
        $sourceHeaderIds = collect(['buyer', 'season', 'ihod', 'vendor', 'style', 'item', 'qty'])
            ->flatMap(fn ($group) => $this->headerIdsForGroup($group))
            ->unique()
            ->values()
            ->all();

        if (empty($sourceHeaderIds)) {
            return;
        }

        $query->whereHas('cells', function ($cellQuery) use ($sourceHeaderIds) {
            $cellQuery->whereIn('header_id', $sourceHeaderIds)
                ->whereNotNull('value')
                ->whereRaw("TRIM(value) <> ''");
        });
    }

    protected function applyCellFilter($query, string $group, ?string $value): void
    {
        $value = trim((string) $value);

        if ($value === '') {
            return;
        }

        $headerIds = $this->headerIdsForGroup($group);

        if (empty($headerIds)) {
            $query->whereRaw('1 = 0');
            return;
        }

        $value = mb_strtolower($value);

        $query->whereHas('cells', function ($cellQuery) use ($headerIds, $value) {
            $cellQuery->whereIn('header_id', $headerIds)
                ->whereRaw('LOWER(value) LIKE ?', ['%' . $value . '%']);
        });
    }

    protected function applyKeywordFilter($query, ?string $keyword): void
    {
        $keyword = trim((string) $keyword);

        if ($keyword === '') {
            return;
        }

        $terms = collect(preg_split('/\s+/', $keyword))
            ->map(fn ($term) => trim((string) $term))
            ->filter()
            ->take(5)
            ->values();

        foreach ($terms as $term) {
            $term = mb_strtolower($term);
            $query->whereHas('cells', function ($cellQuery) use ($term) {
                $cellQuery->whereRaw('LOWER(value) LIKE ?', ['%' . $term . '%']);
            });
        }
    }

    protected function headerIdsForGroup(string $group): array
    {
        if (array_key_exists($group, $this->headerIdCache)) {
            return $this->headerIdCache[$group];
        }

        $aliases = collect($this->headerAliases($group))->map(fn ($alias) => $this->normalize($alias))->all();
        $names = collect($this->headerNameAliases($group))->map(fn ($name) => $this->normalize($name))->all();

        $headerIds = ExcelHeader::query()
            ->where('is_active', true)
            ->orderBy('position')
            ->get()
            ->filter(function (ExcelHeader $header) use ($group, $aliases, $names) {
                $headerKey = $this->normalize($header->header_key);
                $headerName = $this->normalize($header->header_name);

                return in_array($headerKey, $aliases, true)
                    || in_array($headerName, $aliases, true)
                    || in_array($headerName, $names, true)
                    || $this->fallbackMatch($group, $headerKey)
                    || $this->fallbackMatch($group, $headerName);
            })
            ->pluck('id')
            ->unique()
            ->values()
            ->all();

        return $this->headerIdCache[$group] = $headerIds;
    }

    protected function hasAnyBookingSourceData(array $data): bool
    {
        foreach (['buyer_name', 'season_name', 'ihod', 'vendor_name', 'style_name', 'item_name', 'qty'] as $key) {
            if (trim((string) ($data[$key] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    protected function hasRealPoNumber($value): bool
    {
        $value = trim((string) $value);

        if ($value === '') {
            return false;
        }

        $normalized = $this->normalize($value);

        return ! in_array($normalized, [
            'na',
            'n_a',
            'none',
            'nil',
            'no',
            'not_available',
            'pending',
            'po_pending',
            'waiting_for_po',
            'wait_for_po',
            'blank',
            'empty',
            '0',
        ], true);
    }

    protected function syncPoToWorkspace(ExcelRow $row, string $poNo, $generatedAt): void
    {
        $batchId = (string) Str::uuid();
        $poDate = $generatedAt->format('Y-m-d');

        $poHeader = $this->findHeader('po_no');
        $dateHeader = $this->findHeader('po_date');

        $this->writeWorkspaceCell($row, $poHeader, $poNo, $batchId);
        $this->writeWorkspaceCell($row, $dateHeader, $poDate, $batchId);
    }

    protected function writeWorkspaceCell(ExcelRow $row, ?ExcelHeader $header, string $value, string $batchId): void
    {
        if (! $header) {
            return;
        }

        $cell = ExcelCell::firstOrNew([
            'row_id' => $row->id,
            'header_id' => $header->id,
        ]);

        $oldValue = $cell->exists ? $cell->value : null;
        $newValue = trim($value);

        if ((string) $oldValue === (string) $newValue) {
            return;
        }

        $cell->value = $newValue;
        $cell->updated_by = auth()->id();
        $cell->save();

        ActivityLog::create([
            'excel_file_id' => $row->excel_file_id,
            'row_id' => $row->id,
            'header_id' => $header->id,
            'old_value' => $oldValue,
            'new_value' => $newValue,
            'action' => $cell->wasRecentlyCreated ? 'created' : 'updated',
            'user_id' => auth()->id(),
        ]);

        ExcelFileChangeLog::create([
            'excel_file_id' => $row->excel_file_id,
            'excel_row_id' => $row->id,
            'excel_header_id' => $header->id,
            'row_number' => $row->row_number,
            'header_name' => $header->header_name,
            'old_value' => $oldValue,
            'new_value' => $newValue,
            'changed_by' => auth()->id(),
            'batch_id' => $batchId,
        ]);
    }

    protected function findHeader(string $group): ?ExcelHeader
    {
        $aliases = collect($this->headerAliases($group))->map(fn ($alias) => $this->normalize($alias))->all();
        $names = collect($this->headerNameAliases($group))->map(fn ($name) => $this->normalize($name))->all();

        $headers = ExcelHeader::query()
            ->where('is_active', true)
            ->orderBy('position')
            ->get();

        foreach ($headers as $header) {
            $headerKey = $this->normalize($header->header_key);
            $headerName = $this->normalize($header->header_name);

            if (in_array($headerKey, $aliases, true) || in_array($headerName, $aliases, true) || in_array($headerName, $names, true)) {
                return $header;
            }
        }

        foreach ($headers as $header) {
            $headerKey = $this->normalize($header->header_key ?: $header->header_name);
            $headerName = $this->normalize($header->header_name);

            if ($this->fallbackMatch($group, $headerKey) || $this->fallbackMatch($group, $headerName)) {
                return $header;
            }
        }

        return null;
    }

    protected function constrainPoNumberHeaders($headerQuery): void
    {
        $headerQuery->where(function ($inner) {
            $inner->whereIn('header_key', $this->headerAliases('po_no'))
                ->orWhere('header_name', 'like', '%Material PO Number%')
                ->orWhere('header_name', 'like', '%Material PO No%')
                ->orWhere('header_name', 'like', '%Material Purchase Order%');
        });
    }

    protected function extractRowData(ExcelRow $row): array
    {
        $row->loadMissing(['cells.header', 'excelFile']);

        return [
            'po_no' => $this->valueFor($row, 'po_no'),
            'buyer_name' => $this->valueFor($row, 'buyer'),
            'season_name' => $this->valueFor($row, 'season'),
            'ihod' => $this->valueFor($row, 'ihod'),
            'vendor_name' => $this->valueFor($row, 'vendor'),
            'style_name' => $this->valueFor($row, 'style'),
            'item_name' => $this->valueFor($row, 'item'),
            'qty' => $this->bookingQtyValueFor($row),
            'pp_qty' => $this->valueFor($row, 'pp_qty'),
            'uom' => $this->valueFor($row, 'uom'),
            'item_type' => $this->valueFor($row, 'item_type'),
            'description' => $this->valueFor($row, 'description'),
            'color' => $this->valueFor($row, 'color'),
            'size' => $this->valueFor($row, 'size'),
            'width' => $this->valueFor($row, 'width'),
            'fabric_cw' => $this->valueFor($row, 'fabric_cw'),
            'size_width' => $this->valueFor($row, 'size_width'),
            'supplier_article' => $this->valueFor($row, 'supplier_article'),
            'sap_code' => $this->valueFor($row, 'sap_code'),
            'consumption' => $this->valueFor($row, 'consumption'),
            'remarks' => $this->valueFor($row, 'remarks'),
        ];
    }

    protected function bookingQtyValueFor(ExcelRow $row): ?string
    {
        $storedQty = $this->valueFor($row, 'qty');
        $storedQtyNumber = $this->numericValue($storedQty);

        // The workspace screen can show formula values live from source columns while
        // the saved formula cell can still be 0. Booking must use the same source
        // calculation as the workspace for "Materials to be Ordered".
        if ($storedQty !== null && trim((string) $storedQty) !== '' && ($storedQtyNumber === null || abs($storedQtyNumber) > 0.000001)) {
            return $storedQty;
        }

        $calculatedQty = $this->calculateMaterialsToBeOrderedQty($row);

        if ($calculatedQty !== null && abs($calculatedQty) > 0.000001) {
            return $this->formatBookingNumber($calculatedQty);
        }

        return $storedQty;
    }

    protected function calculateMaterialsToBeOrderedQty(ExcelRow $row): ?float
    {
        $sourceQty = $this->numericFormulaValueAny($row, [
            'bom_quantity',
            'customer_contract_quantity',
            'customer_po_quantity',
            'order_qty',
            'gmts_order_qty',
            'gmts_order_quantity',
        ]);

        if ($sourceQty <= 0) {
            return null;
        }

        $existingConsumptionInclYy = $this->numericFormulaValueAny($row, [
            'consumption_based_on_which_materials_order_including_yy',
            'consumption_incl_yy',
            'consumption_including_yy',
            'yy_waste',
        ]);

        if ($existingConsumptionInclYy > 0) {
            return round($existingConsumptionInclYy * $sourceQty, 0);
        }

        $bookingConsumption = $this->numericFormulaValueAny($row, [
            'booking_consumption_from_cad',
            'initial_consumption',
            'booking_yy',
            'consumption',
            'costing_yy_in_sms',
            'costing_yy',
        ]);

        if ($bookingConsumption <= 0) {
            return null;
        }

        $orderingWastage = $this->percentFormulaValue($this->formulaValueForKey($row, 'wastage_for_ordering_percent'));

        return round(($bookingConsumption * (1 + $orderingWastage)) * $sourceQty, 0);
    }

    protected function numericFormulaValueAny(ExcelRow $row, array $keys): float
    {
        $fallback = null;

        foreach ($keys as $key) {
            $value = $this->formulaValueForKey($row, $key);

            if ($value === null || trim((string) $value) === '') {
                continue;
            }

            $number = $this->numericValue($value);

            if ($number === null) {
                continue;
            }

            if (abs($number) > 0.000001) {
                return $number;
            }

            $fallback ??= $number;
        }

        return $fallback ?? 0.0;
    }

    protected function formulaValueForKey(ExcelRow $row, string $key): ?string
    {
        $candidates = collect([$key])
            ->merge($this->formulaAliases($key))
            ->map(fn ($alias) => $this->normalize($alias))
            ->filter()
            ->unique()
            ->values()
            ->all();

        foreach ($row->cells as $cell) {
            if (! $cell->header) {
                continue;
            }

            $value = trim((string) $cell->value);
            if ($value === '') {
                continue;
            }

            $headerKey = $this->normalize($cell->header->header_key);
            $headerName = $this->normalize($cell->header->header_name);
            $formulaKey = $this->normalize($cell->header->formula_key ?? '');

            if (in_array($headerKey, $candidates, true) || in_array($headerName, $candidates, true) || in_array($formulaKey, $candidates, true)) {
                return $value;
            }
        }

        return null;
    }

    protected function formulaAliases(string $key): array
    {
        return match ($key) {
            'bom_quantity' => ['BOM Quantity', 'BOM Qty', 'BOM Qnty'],
            'customer_contract_quantity' => ['Customer Contract Quantity', 'Customer Contract Qty', 'Customer PO Quantity', 'Order Qty', 'GMTS Order Qty', 'GMTS Order Quantity'],
            'customer_po_quantity' => ['Customer PO Quantity', 'Customer PO Qty'],
            'order_qty' => ['Order Qty', 'Order Quantity'],
            'gmts_order_qty' => ['GMTS Order Qty', 'GMTS Order Quantity', 'GMT Order Qty'],
            'gmts_order_quantity' => ['GMTS Order Quantity', 'GMTS Order Qty'],
            'booking_consumption_from_cad' => ['Booking Consumption from CAD', 'Booking Consumption', 'CAD Consumption', 'Booking Cons from CAD'],
            'initial_consumption' => ['Initial Consumption', 'Booking YY', 'Consumption'],
            'booking_yy' => ['Booking YY', 'Booking Consumption'],
            'consumption' => ['Consumption'],
            'costing_yy_in_sms' => ['Costing YY in SMS', 'Costing YY', 'YY in SMS', 'Costing YY SMS'],
            'costing_yy' => ['Costing YY', 'Costing YY in SMS'],
            'wastage_for_ordering_percent' => ['% Wastage for ordering', 'Wastage for ordering %', 'Waste %', 'Wastage %', 'Waste', 'Wastage Percent'],
            'consumption_based_on_which_materials_order_including_yy' => ['Consumption based on which materials order including YY', 'Consumption including YY', 'Consumption incl YY', 'YY + Waste %'],
            'consumption_incl_yy' => ['Consumption based on which materials order including YY', 'Consumption including YY', 'Consumption incl YY', 'YY + Waste %'],
            'consumption_including_yy' => ['Consumption including YY', 'Consumption incl YY'],
            'yy_waste' => ['YY + Waste %', 'YY Waste'],
            default => [],
        };
    }

    protected function percentFormulaValue($value): float
    {
        $number = $this->numericValue($value) ?? 0.0;

        return $number > 1 ? ($number / 100) : $number;
    }

    protected function formatBookingNumber(float $value): string
    {
        if (abs($value - round($value)) < 0.000001) {
            return (string) round($value);
        }

        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
    }

    protected function valueFor(ExcelRow $row, string $group): ?string
    {
        $aliases = collect($this->headerAliases($group))
            ->merge($this->headerNameAliases($group))
            ->map(fn ($alias) => $this->normalize($alias))
            ->unique()
            ->values()
            ->all();

        foreach ($row->cells as $cell) {
            if (! $cell->header) {
                continue;
            }

            $value = trim((string) $cell->value);
            if ($value === '') {
                continue;
            }

            $headerKey = $this->normalize($cell->header->header_key);
            $headerName = $this->normalize($cell->header->header_name);

            if (in_array($headerKey, $aliases, true) || in_array($headerName, $aliases, true)) {
                return $value;
            }
        }

        foreach ($row->cells as $cell) {
            if (! $cell->header) {
                continue;
            }

            $value = trim((string) $cell->value);
            if ($value === '') {
                continue;
            }

            $headerKey = $this->normalize($cell->header->header_key);
            $headerName = $this->normalize($cell->header->header_name);

            if ($this->fallbackMatch($group, $headerKey) || $this->fallbackMatch($group, $headerName)) {
                return $value;
            }
        }

        return null;
    }

    protected function fallbackMatch(string $group, string $header): bool
    {
        return match ($group) {
            'buyer' => str_contains($header, 'buyer') && ! str_contains($header, 'liability') && ! str_contains($header, 'value'),
            'season' => str_contains($header, 'season'),
            'vendor' => str_contains($header, 'vendor') || str_contains($header, 'supplier'),
            'ihod' => str_contains($header, 'contract_shipment_date'),
            'style' => str_contains($header, 'style') || str_contains($header, 'order_style') || str_contains($header, 'contract_number'),
            'item' => str_contains($header, 'material_type') || str_contains($header, 'item_name') || str_contains($header, 'material_description') || str_contains($header, 'description') || str_contains($header, 'sap_code'),
            'qty' => str_contains($header, 'materials_to_be_ordered')
                || str_contains($header, 'material_to_be_ordered')
                || str_contains($header, 'materials_to_be_order')
                || str_contains($header, 'material_to_be_order')
                || str_contains($header, 'materials_order_qty')
                || str_contains($header, 'material_order_qty')
                || str_contains($header, 'booking_qty')
                || str_contains($header, 'booking_quantity'),
            'pp_qty' => str_contains($header, 'pp_qty')
                || str_contains($header, 'p_p_qty')
                || str_contains($header, 'pp_quantity')
                || str_contains($header, 'p_p_quantity')
                || (str_contains($header, 'pp') && (str_contains($header, 'qty') || str_contains($header, 'quantity'))),
            'uom' => str_contains($header, 'uom') || str_contains($header, 'unit'),
            'item_type' => str_contains($header, 'item_type') || str_contains($header, 'material_type'),
            'description' => str_contains($header, 'description'),
            'color' => str_contains($header, 'color') || str_contains($header, 'colour'),
            'size' => (str_contains($header, 'size') || str_contains($header, 'measurement')) && ! str_contains($header, 'width') && ! str_contains($header, 'size_width'),
            'width' => str_contains($header, 'width') && ! str_contains($header, 'size_width'),
            'fabric_cw' => str_contains($header, 'fabric_cw') || (str_contains($header, 'fabric') && str_contains($header, 'cw')),
            'size_width' => str_contains($header, 'size_width') || str_contains($header, 'size_or_width') || str_contains($header, 'size_and_width') || str_contains($header, 'size_width'),
            'supplier_article' => str_contains($header, 'article') || str_contains($header, 'sap_code'),
            'sap_code' => str_contains($header, 'sap_code') || str_contains($header, 'sapcode'),
            'consumption' => str_contains($header, 'consumption') || str_contains($header, 'bulk_cons'),
            'remarks' => str_contains($header, 'remarks') || str_contains($header, 'comments'),
            'po_no' => str_contains($header, 'material_po_number')
                || str_contains($header, 'material_po_no')
                || str_contains($header, 'material_purchase_order_number')
                || str_contains($header, 'material_purchase_order_no'),
            'po_date' => str_contains($header, 'po_date') || str_contains($header, 'material_po_date'),
            default => false,
        };
    }

    protected function headerAliases(string $group): array
    {
        return match ($group) {
            'buyer' => ['buyer_name', 'buyer'],
            'season' => ['season_name', 'season'],
            'vendor' => ['vendor_name', 'supplier_name', 'supplier', 'vendor'],
            'ihod' => ['contract_shipment_date'],
            'style' => ['style_name', 'style_no', 'style_order', 'order_style_no', 'order_style', 'initial_contract_number', 'contract_number', 'sales_contract'],
            'item' => ['material_type', 'item_name', 'item', 'material_description', 'description', 'sap_code'],
            'qty' => [
                    'materials_to_be_ordered',
                    'material_to_be_ordered',
                    'materials_to_be_order',
                    'material_to_be_order',
                    'materials_order_qty',
                    'material_order_qty',
                    'booking_qty',
                    'booking_quantity',
                ],
            'pp_qty' => ['pp_qty', 'p_p_qty', 'pp_quantity', 'p_p_quantity', 'pp'],
            'uom' => ['uom', 'unit'],
            'item_type' => ['item_type', 'material_type'],
            'description' => ['description', 'material_description'],
            'color' => ['material_color', 'color', 'colour', 'gmts_color_name'],
            'size' => ['size', 'item_size', 'material_size', 'auto_size'],
            'width' => ['width', 'item_width', 'material_width', 'fabric_width', 'cuttable_width'],
            'fabric_cw' => ['fabric_cw', 'fabric_c_w', 'fabriccw', 'fab_cw'],
            'size_width' => ['size_width', 'size_or_width', 'size_and_width', 'size_width_value'],
            'supplier_article' => ['supplier_article', 'article', 'sap_code'],
            'sap_code' => ['sap_code', 'sapcode'],
            'consumption' => ['bulk_cons', 'consumption', 'booking_consumption_from_cad', 'consumption_based_on_which_materials_order_including_yy'],
            'remarks' => ['remarks', 'comments', 'merchant_remarks'],
            'po_no' => [
                    'material_po_number',
                    'material_po_no',
                    'material_purchase_order_number',
                    'material_purchase_order_no',
                ],
            'po_date' => ['po_date', 'material_po_date'],
            default => [],
        };
    }

    protected function headerNameAliases(string $group): array
    {
        return match ($group) {
            'buyer' => ['Buyer Name'],
            'season' => ['Season Name'],
            'vendor' => ['Vendor Name', 'Supplier'],
            'ihod' => ['Contract Shipment Date'],
            'po_no' => ['Material PO Number', 'Material PO No', 'Material Purchase Order', 'Material Purchase Order Number'],
            'po_date' => ['PO Date', 'Material PO Date'],
            'qty' => [
                'Materials to be Ordered',
                'Material to be Ordered',
                'Materials To Be Ordered',
                'Material To Be Ordered',
                'Material Qty to be Ordered',
                'Booking Qty',
                'Booking Quantity',
            ],
            'pp_qty' => ['PP Qty', 'P/P Qty', 'PP Quantity', 'P/P Quantity'],
            'size' => ['Size', 'Item Size', 'Material Size'],
            'width' => ['Width', 'Item Width', 'Material Width', 'Fabric Width'],
            'fabric_cw' => ['Fabric CW', 'FABRIC CW', 'Fabric C/W', 'Fabric C W'],
            'sap_code' => ['SAP Code', 'SAP CODE', 'Sap Code'],
            'size_width' => ['Size / Width', 'Size & Width', 'Size Width'],
            default => [],
        };
    }

    protected function makeCode(?string $value, int $length): string
    {
        $value = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string) $value));

        if ($value === '') {
            $value = str_repeat('X', $length);
        }

        return str_pad(substr($value, 0, $length), $length, 'X');
    }

    protected function makeSeasonCode(?string $value): string
    {
        $value = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string) $value));

        if ($value === '') {
            return 'XXXX';
        }

        return str_pad(substr($value, -4), 4, 'X', STR_PAD_LEFT);
    }

    protected function makeUniquePoNo(string $buyerCode, string $seasonCode): string
    {
        $prefix = $buyerCode . $seasonCode;
        $lastPo = BookingPo::where('po_no', 'like', $prefix . '%')
            ->orderByDesc('po_no')
            ->value('po_no');

        $next = 1;
        if ($lastPo && preg_match('/(\d{4})$/', $lastPo, $matches)) {
            $next = ((int) $matches[1]) + 1;
        }

        do {
            $poNo = $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
            $next++;
        } while (BookingPo::where('po_no', $poNo)->exists());

        return $poNo;
    }

    protected function refreshBookingPoQtyFromSource(BookingPo $bookingPo): BookingPo
    {
        $bookingPo->loadMissing(['excelRow.cells.header']);

        if (! $bookingPo->excelRow) {
            return $bookingPo;
        }

        $rowData = $this->extractRowData($bookingPo->excelRow);
        $qty = $this->numericValue($rowData['qty'] ?? null);
        $ppQtyValue = trim((string) ($rowData['pp_qty'] ?? ''));

        if (($qty === null || $qty <= 0) && $ppQtyValue === '') {
            return $bookingPo;
        }

        $currentQty = $this->numericValue($bookingPo->qty);
        $bookingData = $bookingPo->booking_data ?: [];
        $changed = false;

        if ($qty !== null && $qty > 0 && ($currentQty === null || $currentQty <= 0)) {
            $bookingPo->qty = $qty;
            $changed = true;
        }

        if (! empty($bookingData['items']) && is_array($bookingData['items'])) {
            $firstItemQty = $this->numericValue($bookingData['items'][0]['booking_qty'] ?? null);

            if ($qty !== null && $qty > 0 && ($firstItemQty === null || $firstItemQty <= 0)) {
                $bookingData['items'][0]['booking_qty'] = $this->formatBookingNumber($qty);

                $supplier = $this->findSupplier($bookingPo->vendor_name);
                $tolerancePercent = (float) ($supplier?->tolerance_percent ?? 3);
                $bookingData['items'][0]['tolerance_qty'] = round(($qty * $tolerancePercent) / 100, 2);

                $bookingPo->booking_data = $bookingData;
                $changed = true;
            }

            if ($ppQtyValue !== '' && trim((string) ($bookingData['items'][0]['pp_qty'] ?? '')) === '') {
                $bookingData['items'][0]['pp_qty'] = $ppQtyValue;
                $bookingPo->booking_data = $bookingData;
                $changed = true;
            }
        }

        if ($changed) {
            $bookingPo->save();
        }

        return $bookingPo;
    }

    protected function defaultBookingData(BookingPo $bookingPo, ?array $sourceRows = null): array
    {
        $supplier = $this->findSupplier($bookingPo->vendor_name);
        $tolerancePercent = $supplier?->tolerance_percent ?? 3;

        if ($sourceRows === null && $bookingPo->relationLoaded('excelRow') && $bookingPo->excelRow) {
            $sourceRows = [$this->extractRowData($bookingPo->excelRow)];
        }

        if ($sourceRows === null) {
            $sourceRows = [[
                'style_name' => $bookingPo->style_name,
                'item_type' => $bookingPo->item_type,
                'item_name' => $bookingPo->item_name,
                'description' => $bookingPo->description,
                'color' => $bookingPo->color,
                'size' => null,
                'width' => null,
                'fabric_cw' => null,
                'size_width' => $bookingPo->size_width,
                'supplier_article' => $bookingPo->supplier_article,
                'consumption' => $bookingPo->consumption,
                'qty' => $bookingPo->qty,
                'pp_qty' => null,
                'uom' => $bookingPo->uom,
                'remarks' => $bookingPo->remarks,
                'vendor_name' => $bookingPo->vendor_name,
            ]];
        }

        $items = collect($sourceRows)
            ->map(fn (array $rowData) => $this->bookingLineItemFromData($rowData, $supplier, (float) $tolerancePercent))
            ->values()
            ->all();

        if (empty($items)) {
            $items = [$this->bookingLineItemFromData([], $supplier, (float) $tolerancePercent)];
        }

        $firstItem = $items[0] ?? [];
        $styleList = collect($items)->pluck('style_order')->filter()->unique()->take(3)->implode(', ');
        $itemTypeList = collect($items)->pluck('item_type')->filter()->unique()->take(3)->implode(', ');

        return [
            'to' => $supplier?->display_name ?: ($bookingPo->vendor_name ?: '[Supplier company name]'),
            'date' => optional($bookingPo->generated_at ?: now())->format('d/m/Y'),
            'attn' => $supplier?->contact_person ?: '[Contact person]',
            'buyer' => $bookingPo->buyer_name ?: '[Buyer name]',
            'email' => $supplier?->email ?: '[Supplier email]',
            'address' => $supplier?->full_address ?: '',
            'season' => $bookingPo->season_name ?: '[Season]',
            'from' => optional($bookingPo->generatedBy)->name ?: optional(auth()->user())->name ?: '[Supply-chain user]',
            'po_number' => $bookingPo->po_no,
            'supplier' => $supplier?->display_name ?: ($bookingPo->vendor_name ?: '[Supplier legal name]'),
            'incoterm' => $supplier?->incoterm ?: '',
            'item_type' => $itemTypeList ?: ($bookingPo->item_type ?: ($supplier?->item_type ?: '[Fabric / Zipper / Hook & Bar / Trim / Interlining / Scrim]')),
            'ship_mode' => $supplier?->ship_mode ?: '',
            'order_style_no' => $styleList ?: ($bookingPo->style_name ?: '[Order or style no]'),
            'tolerance' => $tolerancePercent ? $tolerancePercent . '% - no shortage' : '3% - no shortage',
            'consignee' => "Humana Apparels Private Limited
Momin Nagar, Gorai, Mirzapur, Tangail - 1941, Bangladesh
Attn: Robin / Ashif Contact: +8801992371918 / +8801914650402
BIN: 005635381-0406 TIN: 780096271681",
            'delivery_destination_id' => '',
            'delivery_destination_name' => '',
            'delivery_destination_details' => '',
            'items' => $items,
            'notes' => $this->defaultInstructionTexts(),
            'best_regards' => optional($bookingPo->generatedBy)->name ?: optional(auth()->user())->name ?: '[Supply-chain user]',
        ];
    }

    protected function bookingLineItemFromData(array $rowData, ?Supplier $supplier, float $tolerancePercent): array
    {
        $qty = $this->numericValue($rowData['qty'] ?? null) ?? 0;
        $toleranceQty = $qty > 0 ? round(($qty * $tolerancePercent) / 100, 2) : null;
        [$size, $width] = $this->sizeWidthPairForItem($rowData);

        return [
            'style_order' => $rowData['style_name'] ?? '[Style]',
            'item_type' => $rowData['item_type'] ?? ($rowData['item_name'] ?? ($supplier?->item_type ?: '[Type]')),
            'item_name' => $rowData['item_name'] ?? '[Item]',
            'description' => $rowData['description'] ?? '[Description]',
            'color' => $rowData['color'] ?? '[Color]',
            'size' => $size,
            'width' => $width,
            'fabric_cw' => ($cw = trim((string) ($rowData['fabric_cw'] ?? ''))) !== '' ? $cw : 'N/A',
            'size_width' => trim((string) ($rowData['size_width'] ?? '')),
            'supplier_article' => $rowData['supplier_article'] ?? ($supplier?->display_name ?: ($rowData['vendor_name'] ?? '')),
            'bulk_cons' => $rowData['consumption'] ?? '1.00',
            'booking_qty' => $qty,
            'tolerance_qty' => $toleranceQty,
            'pp_qty' => $rowData['pp_qty'] ?? '',
            'uom' => $rowData['uom'] ?? 'Pcs',
            'remarks' => $rowData['remarks'] ?? '',
        ];
    }

    protected function sizeWidthPairForItem(array $rowData): array
    {
        $size = trim((string) ($rowData['size'] ?? ''));
        $width = trim((string) ($rowData['width'] ?? ''));
        $combined = trim((string) ($rowData['size_width'] ?? ''));

        $isBlank = fn ($value): bool => trim((string) $value) === '' || in_array($this->normalize($value), ['na', 'n_a', 'none', 'nil', 'blank', 'empty', '0', '-', '--'], true);

        if ($isBlank($size)) {
            $size = '';
        }

        if ($isBlank($width)) {
            $width = '';
        }

        if ($isBlank($combined)) {
            $combined = '';
        }

        if ($combined !== '' && ($size === '' || $width === '')) {
            if ($this->itemUsuallyUsesWidth($rowData)) {
                $width = $width !== '' ? $width : $combined;
            } else {
                $size = $size !== '' ? $size : $combined;
            }
        }

        return [$size !== '' ? $size : 'N/A', $width !== '' ? $width : 'N/A'];
    }

    protected function itemUsuallyUsesWidth(array $item): bool
    {
        $text = $this->normalize(implode(' ', [
            $item['item_name'] ?? '',
            $item['item_type'] ?? '',
            $item['description'] ?? '',
        ]));

        $widthKeywords = [
            'fabric', 'shell', 'lining', 'interlining', 'scrim', 'fusing', 'mesh',
            'tape', 'elastic', 'lace', 'rib', 'webbing', 'cord', 'drawcord', 'thread',
        ];

        foreach ($widthKeywords as $keyword) {
            if (str_contains($text, $keyword)) {
                return true;
            }
        }

        return false;
    }

    protected function bookingData(BookingPo $bookingPo): array
    {
        $bookingPo = $this->refreshBookingPoQtyFromSource($bookingPo);
        $defaults = $this->defaultBookingData($bookingPo->loadMissing('generatedBy'));
        $stored = $bookingPo->booking_data ?: [];

        $data = array_replace_recursive($defaults, $stored);

        if (empty($data['items'])) {
            $data['items'] = $defaults['items'];
        }

        if (empty($data['notes'])) {
            $data['notes'] = $defaults['notes'];
        }

        $defaultItems = $defaults['items'] ?? [];

        $data['items'] = collect($data['items'] ?? [])
            ->map(function ($item, $index) use ($defaultItems) {
                $item = is_array($item) ? $item : [];

                if (trim((string) ($item['pp_qty'] ?? '')) === '' && trim((string) ($defaultItems[$index]['pp_qty'] ?? '')) !== '') {
                    $item['pp_qty'] = $defaultItems[$index]['pp_qty'];
                }

                [$size, $width] = $this->sizeWidthPairForItem($item);
                $item['size'] = $size;
                $item['width'] = $width;

                return $item;
            })
            ->values()
            ->all();

        return $data;
    }


    protected function bookingDataWithLatestSource(BookingPo $bookingPo): array
    {
        $data = $this->bookingData($bookingPo);
        $bookingPo->loadMissing(['excelRow.cells.header']);

        if (! $bookingPo->excelRow) {
            return $data;
        }

        return $this->mergeLatestSourceIntoBookingData($data, $this->extractRowData($bookingPo->excelRow));
    }

    protected function mergeLatestSourceIntoBookingData(array $data, array $rowData): array
    {
        $value = fn (string $key): string => $this->normalizeSnapshotValue($rowData[$key] ?? null);
        $setIfPresent = function (string $dataKey, string $sourceKey) use (&$data, $value) {
            $sourceValue = $value($sourceKey);
            if ($sourceValue !== '') {
                $data[$dataKey] = $sourceValue;
            }
        };

        $setIfPresent('buyer', 'buyer_name');
        $setIfPresent('season', 'season_name');
        $setIfPresent('order_style_no', 'style_name');
        $setIfPresent('item_type', 'item_type');

        $vendorName = $value('vendor_name');
        if ($vendorName !== '') {
            $data['to'] = $vendorName;
            $data['supplier'] = $vendorName;
        }

        $items = array_values($data['items'] ?? []);
        $item = is_array($items[0] ?? null) ? $items[0] : [];
        [$size, $width] = $this->sizeWidthPairForItem($rowData);

        foreach ([
            'style_name' => 'style_order',
            'item_type' => 'item_type',
            'item_name' => 'item_name',
            'description' => 'description',
            'color' => 'color',
            'fabric_cw' => 'fabric_cw',
            'supplier_article' => 'supplier_article',
            'consumption' => 'bulk_cons',
            'qty' => 'booking_qty',
            'pp_qty' => 'pp_qty',
            'uom' => 'uom',
            'remarks' => 'remarks',
        ] as $sourceKey => $itemKey) {
            $sourceValue = $value($sourceKey);
            if ($sourceValue !== '') {
                $item[$itemKey] = $sourceValue;
            }
        }

        if ($size !== 'N/A') {
            $item['size'] = $size;
        }

        if ($width !== 'N/A') {
            $item['width'] = $width;
        }

        $sizeWidth = $value('size_width');
        if ($sizeWidth !== '') {
            $item['size_width'] = $sizeWidth;
        }

        $items[0] = $item;
        $data['items'] = $items;

        return $data;
    }

    protected function applyLatestSourceDataToBookingPo(BookingPo $bookingPo): BookingPo
    {
        $bookingPo = $bookingPo->fresh(['excelRow.cells.header', 'generatedBy']) ?: $bookingPo;

        if (! $bookingPo->excelRow) {
            return $this->refreshBookingPoQtyFromSource($bookingPo);
        }

        $data = $this->bookingDataWithLatestSource($bookingPo);
        $firstItem = $data['items'][0] ?? [];

        $bookingPo->update([
            'booking_data' => $data,
            'buyer_name' => $data['buyer'] ?? $bookingPo->buyer_name,
            'season_name' => $data['season'] ?? $bookingPo->season_name,
            'vendor_name' => $data['to'] ?? $bookingPo->vendor_name,
            'style_name' => $firstItem['style_order'] ?? $bookingPo->style_name,
            'item_name' => $firstItem['item_name'] ?? $bookingPo->item_name,
            'qty' => $this->numericValue($firstItem['booking_qty'] ?? $bookingPo->qty),
            'uom' => $firstItem['uom'] ?? $bookingPo->uom,
            'item_type' => $firstItem['item_type'] ?? $bookingPo->item_type,
            'description' => $firstItem['description'] ?? $bookingPo->description,
            'color' => $firstItem['color'] ?? $bookingPo->color,
            'size_width' => trim(((string) ($firstItem['size'] ?? '')) . ' ' . ((string) ($firstItem['width'] ?? ''))) ?: ($firstItem['size_width'] ?? $bookingPo->size_width),
            'supplier_article' => $firstItem['supplier_article'] ?? $bookingPo->supplier_article,
            'consumption' => $this->numericValue($firstItem['bulk_cons'] ?? $bookingPo->consumption),
            'remarks' => $firstItem['remarks'] ?? $bookingPo->remarks,
        ]);

        return $bookingPo->fresh(['excelRow.cells.header', 'generatedBy']) ?: $bookingPo;
    }

    protected function findSupplier(?string $vendorName): ?Supplier
    {
        $vendorName = trim((string) $vendorName);

        if ($vendorName === '') {
            return null;
        }

        return Supplier::query()
            ->where('is_active', true)
            ->where(function ($query) use ($vendorName) {
                $query->where('supplier_name', $vendorName)
                    ->orWhere('legal_name', $vendorName)
                    ->orWhere('supplier_code', $vendorName)
                    ->orWhere('supplier_name', 'like', '%' . $vendorName . '%')
                    ->orWhere('legal_name', 'like', '%' . $vendorName . '%');
            })
            ->first();
    }

    protected function bookingInstructionOptions()
    {
        if (! Schema::hasTable('booking_instructions')) {
            return collect();
        }

        return BookingInstruction::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('instruction')
            ->get();
    }

    protected function deliveryDestinationOptions()
    {
        if (! Schema::hasTable('booking_delivery_destinations')) {
            return collect();
        }

        return BookingDeliveryDestination::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('title')
            ->get();
    }

    protected function applyDeliveryDestinationDefaults(array $data): array
    {
        $destinationId = (int) ($data['delivery_destination_id'] ?? 0);

        if ($destinationId <= 0 || ! Schema::hasTable('booking_delivery_destinations')) {
            return $data;
        }

        $destination = BookingDeliveryDestination::query()
            ->where('is_active', true)
            ->whereKey($destinationId)
            ->first();

        if (! $destination) {
            return $data;
        }

        $data['delivery_destination_id'] = (string) $destination->id;
        $data['delivery_destination_name'] = $this->cleanText($data['delivery_destination_name'] ?? null) ?: $destination->title;
        $data['delivery_destination_details'] = $this->cleanText($data['delivery_destination_details'] ?? null) ?: $destination->details;

        return $data;
    }

    protected function defaultInstructionTexts(): array
    {
        if (Schema::hasTable('booking_instructions')) {
            $instructions = BookingInstruction::query()
                ->where('is_active', true)
                ->where('is_default', true)
                ->orderBy('sort_order')
                ->pluck('instruction')
                ->filter()
                ->values()
                ->all();

            if (! empty($instructions)) {
                return $instructions;
            }
        }

        return $this->fallbackInstructionTexts();
    }

    protected function fallbackInstructionTexts(): array
    {
        return [
            'Please make sure buyer-required quality and approval are completed before bulk production.',
            'Please mention style no., buyer name, PO number, ship mode and incoterm in PI, challan and shipping documents.',
            'Bulk booking consumption must match approved BOM / usage standard.',
            'Approved dye-lot, inspection report and test report should be shared before shipment.',
            'Supplier must maintain delivery schedule strictly and share final shipping document draft for checking.',
            'Add any buyer-specific special requirement only in this notes section.',
        ];
    }

    protected function instructionTextsByIds(array $ids): array
    {
        $ids = collect($ids)->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();

        if (empty($ids) || ! Schema::hasTable('booking_instructions')) {
            return [];
        }

        return BookingInstruction::query()
            ->whereIn('id', $ids)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->pluck('instruction')
            ->filter()
            ->values()
            ->all();
    }

    protected function saveCommonInstruction(string $instruction): BookingInstruction
    {
        return BookingInstruction::firstOrCreate(
            ['instruction' => $instruction],
            [
                'is_default' => false,
                'is_active' => true,
                'sort_order' => (int) (BookingInstruction::max('sort_order') ?? 0) + 10,
            ]
        );
    }

    protected function requestHasBookingEdits(Request $request): bool
    {
        foreach (['booking', 'items', 'notes', 'common_instruction_ids', 'new_instruction', 'save_new_instruction'] as $key) {
            if ($request->has($key)) {
                return true;
            }
        }

        return false;
    }

    protected function validateBookingEditRequest(Request $request): array
    {
        return $request->validate([
            'booking' => ['nullable', 'array'],
            'items' => ['nullable', 'array'],
            'items.*' => ['nullable', 'array'],
            'notes' => ['nullable', 'array'],
            'common_instruction_ids' => ['nullable', 'array'],
            'common_instruction_ids.*' => ['integer'],
            'new_instruction' => ['nullable', 'string', 'max:1000'],
            'save_new_instruction' => ['nullable', 'boolean'],
        ]);
    }

    protected function applyBookingEditDataToPo(BookingPo $bookingPo, array $validated, ?Request $request = null): BookingPo
    {
        $data = $this->bookingData($bookingPo);
        $bookingInput = $validated['booking'] ?? [];
        $itemsInput = array_values($validated['items'] ?? []);
        $notesInput = collect($validated['notes'] ?? [])
            ->map(fn ($note) => $this->cleanText($note))
            ->filter()
            ->values();

        foreach ($this->bookingScalarKeys() as $key) {
            if (array_key_exists($key, $bookingInput)) {
                $data[$key] = $this->cleanText($bookingInput[$key]);
            }
        }

        $data = $this->applyDeliveryDestinationDefaults($data);

        if (! empty($itemsInput)) {
            $cleanItems = [];
            foreach ($itemsInput as $item) {
                $cleanItems[] = $this->cleanItem($item);
            }
            $data['items'] = $cleanItems;
        }

        $selectedInstructionTexts = $this->instructionTextsByIds($validated['common_instruction_ids'] ?? []);
        $newInstructionText = $this->cleanText($validated['new_instruction'] ?? '');

        if ($newInstructionText && $request?->boolean('save_new_instruction')) {
            $this->saveCommonInstruction($newInstructionText);
        }

        if (array_key_exists('notes', $validated) || ! empty($selectedInstructionTexts) || $newInstructionText) {
            $data['notes'] = collect($selectedInstructionTexts)
                ->merge($notesInput)
                ->when($newInstructionText, fn ($notes) => $notes->push($newInstructionText))
                ->filter()
                ->unique(fn ($note) => $this->normalize($note))
                ->values()
                ->all();
        }

        $firstItem = $data['items'][0] ?? [];
        $previousVendorName = $bookingPo->vendor_name;

        $bookingPo->update([
            'booking_data' => $data,
            'buyer_name' => $data['buyer'] ?? $bookingPo->buyer_name,
            'season_name' => $data['season'] ?? $bookingPo->season_name,
            'vendor_name' => $data['to'] ?? $bookingPo->vendor_name,
            'style_name' => $firstItem['style_order'] ?? $bookingPo->style_name,
            'item_name' => $firstItem['item_name'] ?? $bookingPo->item_name,
            'qty' => $this->numericValue($firstItem['booking_qty'] ?? $bookingPo->qty),
            'uom' => $firstItem['uom'] ?? $bookingPo->uom,
            'item_type' => $firstItem['item_type'] ?? $bookingPo->item_type,
            'description' => $firstItem['description'] ?? $bookingPo->description,
            'color' => $firstItem['color'] ?? $bookingPo->color,
            'size_width' => trim(((string) ($firstItem['size'] ?? '')) . ' ' . ((string) ($firstItem['width'] ?? ''))) ?: ($firstItem['size_width'] ?? $bookingPo->size_width),
            'supplier_article' => $firstItem['supplier_article'] ?? $bookingPo->supplier_article,
            'consumption' => $this->numericValue($firstItem['bulk_cons'] ?? $bookingPo->consumption),
            'remarks' => $firstItem['remarks'] ?? $bookingPo->remarks,
        ]);

        $this->syncSupplierFromBookingData($bookingPo->fresh(), $data, $previousVendorName);

        return $bookingPo->fresh();
    }

    protected function syncSupplierFromBookingData(?BookingPo $bookingPo, array $data, ?string $previousVendorName = null): void
    {
        if (! $bookingPo) {
            return;
        }

        $lookupName = $this->cleanText($previousVendorName ?: $bookingPo->vendor_name);
        $toName = $this->cleanText($data['to'] ?? null);
        $supplierName = $this->cleanText($data['supplier'] ?? null);
        $lookupName = $lookupName ?: ($supplierName ?: $toName);

        if (! $lookupName && ! $toName) {
            return;
        }

        $supplier = $this->findSupplier($lookupName) ?: $this->findSupplier($toName) ?: new Supplier();
        $supplier->supplier_name = $supplier->supplier_name ?: ($lookupName ?: $toName);

        if ($toName) {
            $supplier->legal_name = $toName;
        }

        foreach ([
            'contact_person' => 'attn',
            'email' => 'email',
            'address' => 'address',
            'item_type' => 'item_type',
            'incoterm' => 'incoterm',
            'ship_mode' => 'ship_mode',
        ] as $supplierKey => $dataKey) {
            if (array_key_exists($dataKey, $data)) {
                $supplier->{$supplierKey} = $this->cleanText($data[$dataKey]);
            }
        }

        $tolerancePercent = $this->tolerancePercentFromText($data['tolerance'] ?? null);
        if ($tolerancePercent !== null) {
            $supplier->tolerance_percent = $tolerancePercent;
        }

        $supplier->is_active = true;
        $supplier->save();
    }

    protected function tolerancePercentFromText($value): ?float
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if (preg_match('/-?\d+(?:\.\d+)?/', $value, $matches)) {
            return (float) $matches[0];
        }

        return null;
    }

    protected function bookingScalarKeys(): array
    {
        return [
            'to', 'date', 'attn', 'buyer', 'email', 'address', 'season', 'from', 'po_number',
            'supplier', 'incoterm', 'item_type', 'ship_mode', 'order_style_no',
            'tolerance', 'consignee', 'delivery_destination_id', 'delivery_destination_name',
            'delivery_destination_details', 'best_regards',
        ];
    }

    protected function cleanItem(array $item): array
    {
        $keys = [
            'style_order', 'item_type', 'item_name', 'description', 'color',
            'size', 'width', 'fabric_cw', 'size_width', 'supplier_article', 'bulk_cons', 'booking_qty', 'tolerance_qty',
            'pp_qty', 'uom', 'remarks',
        ];

        $clean = [];
        foreach ($keys as $key) {
            $clean[$key] = $this->cleanText($item[$key] ?? '');
        }

        return $clean;
    }

    protected function cleanText($value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    protected function numericValue($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $isNegativeAccounting = preg_match('/^\(.*\)$/', $value) === 1;
        $value = str_replace(["\xC2\xA0", ',', ' ', '%', '(', ')'], '', $value);

        if (! is_numeric($value)) {
            return null;
        }

        $number = (float) $value;

        return $isNegativeAccounting ? -1 * $number : $number;
    }

    protected function normalize($value): string
    {
        $value = strtolower(trim((string) $value));
        $value = str_replace(['&', '+'], ' and ', $value);
        $value = str_replace(["'", '’'], '', $value);
        $value = preg_replace('/[^a-z0-9]+/', '_', $value);
        $value = preg_replace('/_+/', '_', $value);

        return trim($value, '_');
    }
}
