<?php

namespace App\Http\Controllers\Shared;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\BookingPo;
use App\Models\ExcelCell;
use App\Models\ExcelFile;
use App\Models\ExcelHeader;
use App\Models\ExcelRow;
use Carbon\Carbon;
use DateTimeInterface;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use App\Models\AppNotification;
use App\Models\ExcelFileChangeLog;
use App\Models\User;
use Illuminate\Support\Str;

class ExcelFileController extends Controller
{
    public function show(ExcelFile $excelFile)
    {
        DB::disableQueryLog();
        @set_time_limit(300);

        $user = auth()->user();
        $isFileLockedForUser = $excelFile->isLockedForUser($user);
        $fileLockInfo = $this->fileLockInfo($excelFile);

        $roleIds = $this->getRoleIds();

        $minimumVisibleRows = 20;
        $this->ensureMinimumRows($excelFile, $minimumVisibleRows);

        $headers = ExcelHeader::with('ownerRole')
            ->where('is_active', true)
            ->orderBy('position')
            ->get()
            ->filter(fn ($header) => $this->canViewHeader($header, $roleIds))
            ->values();

        $visibleHeaderIds = $headers->pluck('id')->all();

        $calculatedHeaderKeys = $this->calculatedHeaderKeys();
        $calculatedHeaderIds = $headers
            ->filter(fn ($header) => $this->isCalculatedHeader($header, $calculatedHeaderKeys))
            ->pluck('id')
            ->all();

        $editableHeaderIds = $isFileLockedForUser
            ? []
            : $headers
                ->filter(fn ($header) => $this->canEditHeader($header, $roleIds))
                ->reject(fn ($header) => $this->isCalculatedHeader($header, $calculatedHeaderKeys))
                ->pluck('id')
                ->all();

        $orderInfo = $this->buildOrderInfo($excelFile);

        $perPage = (int) request()->query('per_page', 50);
        $perPage = in_array($perPage, [20, 50, 100], true) ? $perPage : 50;

        // Server-side search/filter so pagination searches the whole uploaded document,
        // not only the rows currently rendered on the first page.
        $globalSearch = trim((string) request()->query('search', ''));
        $columnFilters = collect((array) request()->query('filters', []))
            ->map(fn ($value) => is_string($value) ? trim($value) : $value)
            ->filter(fn ($value) => $value !== null && $value !== '')
            ->all();

        $rowsQuery = $excelFile->rows();

        if ($globalSearch !== '') {
            $rowsQuery->where(function ($query) use ($globalSearch, $visibleHeaderIds) {
                $query->where('row_number', 'like', '%' . $globalSearch . '%')
                    ->orWhereHas('cells', function ($cellQuery) use ($globalSearch, $visibleHeaderIds) {
                        $cellQuery->whereIn('header_id', $visibleHeaderIds)
                            ->where('value', 'like', '%' . $globalSearch . '%');
                    });
            });
        }

        foreach ($columnFilters as $filterKey => $filterValue) {
            if ($filterKey === '__row') {
                $rowsQuery->where('row_number', 'like', '%' . $filterValue . '%');
                continue;
            }

            $headerId = (int) $filterKey;

            if (! in_array($headerId, $visibleHeaderIds, true)) {
                continue;
            }

            $rowsQuery->whereHas('cells', function ($cellQuery) use ($headerId, $filterValue) {
                $cellQuery->where('header_id', $headerId)
                    ->where('value', 'like', '%' . $filterValue . '%');
            });
        }

        $rows = $rowsQuery
            ->with(['cells' => function ($query) use ($visibleHeaderIds) {
                $query->select('id', 'row_id', 'header_id', 'value')
                    ->whereIn('header_id', $visibleHeaderIds);
            }])
            ->orderBy('row_number')
            ->paginate($perPage)
            ->withQueryString();

        $highlightBatchId = request('batch');
        $highlightedCellKeys = collect();

        if ($highlightBatchId) {
            $highlightedCellKeys = ExcelFileChangeLog::where('excel_file_id', $excelFile->id)
                ->where('batch_id', $highlightBatchId)
                ->whereNotNull('excel_row_id')
                ->whereNotNull('excel_header_id')
                ->get(['excel_row_id', 'excel_header_id'])
                ->map(function ($log) {
                    return $log->excel_row_id . '-' . $log->excel_header_id;
                })
                ->values();
        }

        $lockedRowInfo = $this->poLockedRowInfoForUser($rows->getCollection(), $user);
        $lockedRowIds = $lockedRowInfo->keys()->flip();

        if (request('notification')) {
            AppNotification::where('id', request('notification'))
                ->where('user_id', auth()->id())
                ->whereNull('read_at')
                ->update(['read_at' => now()]);
        }

        $canAddRow = ($user->hasRole('admin') || $user->hasRole('merchant')) && ! $isFileLockedForUser;
        $canDeleteFile = $user->hasRole('admin') || $user->hasRole('merchant');

        // Latest PRA dates per row, pre-formatted for the live (client-side) formulas.
        $rowPraDates = collect($this->praDatesByRow($excelFile))
            ->map(fn ($pra) => [
                'submission' => $this->fmtDate($pra['submission']),
                'reqd' => $pra['reqd'] ? $this->fmtDate($pra['reqd']) : null,
            ])
            ->all();

        return view('shared.excel-files.show', compact(
            'excelFile',
            'headers',
            'rows',
            'editableHeaderIds',
            'calculatedHeaderKeys',
            'calculatedHeaderIds',
            'canAddRow',
            'canDeleteFile',
            'highlightBatchId',
            'highlightedCellKeys',
            'lockedRowIds',
            'lockedRowInfo',
            'isFileLockedForUser',
            'fileLockInfo',
            'orderInfo',
            'perPage',
            'rowPraDates'
        ));
    }

    private function buildOrderInfo(ExcelFile $excelFile): array
    {
        $orderInfo = [
            'Buyer Name' => '-',
            'Season Name' => '-',
            'Style Name' => '-',
            'Contract Number' => '-',
            'Contract Shipment Date' => '-',
        ];

        $firstRow = $excelFile->rows()
            ->with(['cells.header'])
            ->orderBy('row_number')
            ->first();

        if (! $firstRow) {
            return $orderInfo;
        }

        foreach ($firstRow->cells as $cell) {
            $headerName = optional($cell->header)->header_name;

            if (array_key_exists($headerName, $orderInfo) && filled($cell->value)) {
                $orderInfo[$headerName] = $cell->value;
            }
        }

        return $orderInfo;
    }
   public function update(Request $request, ExcelFile $excelFile)
    {
        $user = auth()->user();

        if ($excelFile->isLockedForUser($user)) {
            return redirect()
                ->route('uploaded-files.show', $excelFile->id)
                ->with('warning', 'This file is locked by admin. You can view it, but you cannot edit or update records.');
        }

        $roleIds = $this->getRoleIds();
        $calculatedHeaderKeys = $this->calculatedHeaderKeys();
        $batchId = (string) Str::uuid();
        $changedCells = [];

        $editableHeaderIds = ExcelHeader::where('is_active', true)
            ->get()
            ->filter(fn ($header) => $this->canEditHeader($header, $roleIds))
            ->reject(fn ($header) => $this->isCalculatedHeader($header, $calculatedHeaderKeys))
            ->pluck('id')
            ->flip();

        $validRowIds = $excelFile->rows()->pluck('id')->flip();
        $rowLookup = $excelFile->rows()->pluck('row_number', 'id');

        $headerLookup = ExcelHeader::where('is_active', true)
            ->get(['id', 'header_name'])
            ->keyBy('id');

        $submittedRowIds = collect(array_keys((array) $request->input('cells', [])))
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $validRowIds->has($id))
            ->values();
        $lockedRowInfo = $this->poLockedRowInfoForUser(
            $excelFile->rows()->whereIn('id', $submittedRowIds->all())->with('cells')->get(),
            $user
        );
        $lockedRowIds = $lockedRowInfo->keys()->flip();

        DB::transaction(function () use ($request, $excelFile, $user, $editableHeaderIds, $validRowIds,$rowLookup,$headerLookup,$batchId,&$changedCells,$lockedRowIds) {
            foreach ((array) $request->input('cells', []) as $rowId => $rowCells) {
                $rowId = (int) $rowId;

                if (! $validRowIds->has($rowId) || $lockedRowIds->has($rowId)) {
                    continue;
                }

                foreach ((array) $rowCells as $headerId => $newValue) {
                    if (! $editableHeaderIds->has((int) $headerId)) {
                        continue;
                    }

                    $cell = ExcelCell::firstOrNew([
                        'row_id' => $rowId,
                        'header_id' => $headerId,
                    ]);

                    $oldValue = $cell->exists ? $cell->value : null;

                    $newValue = is_string($newValue) ? trim($newValue) : $newValue;
                    $newValue = $newValue === '' ? null : $newValue;

                    if ((string) $oldValue === (string) $newValue) {
                        continue;
                    }

                    $action = $cell->exists ? 'updated' : 'created';

                    $cell->value = $newValue;
                    $cell->updated_by = $user->id;
                    $cell->save();

                    ActivityLog::create([
                        'excel_file_id' => $excelFile->id,
                        'row_id' => $rowId,
                        'header_id' => $headerId,
                        'old_value' => $oldValue,
                        'new_value' => $newValue,
                        'action' => $action,
                        'user_id' => $user->id,
                    ]);

                    $rowNumber = $rowLookup[$rowId] ?? null;
                    $header = $headerLookup->get((int) $headerId);

                    ExcelFileChangeLog::create([
                        'excel_file_id' => $excelFile->id,
                        'excel_row_id' => $rowId,
                        'excel_header_id' => $headerId,
                        'row_number' => $rowNumber,
                        'header_name' => $header?->header_name,
                        'old_value' => $oldValue,
                        'new_value' => $newValue,
                        'changed_by' => $user->id,
                        'batch_id' => $batchId,
                    ]);

                    $changedCells[] = [
                        'row_id' => $rowId,
                        'row_number' => $rowNumber,
                        'header_id' => $headerId,
                        'header_name' => $header?->header_name,
                    ];
                }
            }

            $this->recalculateFile($excelFile, $user->id, $lockedRowIds->keys()->all());
            $this->refreshFileStatus($excelFile);
        });

            if (count($changedCells) > 0 && $user->hasRole('merchant')) {
            $this->notifyOtherRolesAboutFileUpdate($excelFile, $changedCells, $batchId, $user);
        }

        $queryParams = array_filter([
            'page' => $request->query('page'),
            'per_page' => $request->query('per_page'),
        ], fn ($value) => filled($value));

        if (count($changedCells) > 0) {
            $queryParams['batch'] = $batchId;
        }

        $redirectUrl = route('uploaded-files.show', $excelFile->id);

        if (count($queryParams) > 0) {
            $redirectUrl .= '?' . http_build_query($queryParams);
        }

        $redirect = redirect($redirectUrl)
            ->with('success', 'File updated successfully.');

        if ($lockedRowIds->isNotEmpty()) {
            $redirect->with('warning', $lockedRowIds->count() . ' locked PO row(s) were skipped. Unlock the PO in Admin PO Control or change the lock scope before editing.');
        }

        return $redirect;

}

    public function addRow(ExcelFile $excelFile)
    {
        $user = auth()->user();

        if (! $user->hasRole('admin') && ! $user->hasRole('merchant')) {
            abort(403, 'You are not allowed to add a new row.');
        }

        if ($excelFile->isLockedForUser($user)) {
            return redirect()
                ->route('uploaded-files.show', $excelFile->id)
                ->with('warning', 'This file is locked by admin. New rows cannot be added until it is unlocked for your user or role.');
        }

        DB::transaction(function () use ($excelFile, $user) {
            $nextRowNumber = ((int) $excelFile->rows()->max('row_number')) + 1;

            $row = ExcelRow::create([
                'excel_file_id' => $excelFile->id,
                'row_number' => $nextRowNumber,
            ]);

            $headers = ExcelHeader::where('is_active', true)
                ->orderBy('position')
                ->get();

            foreach ($headers as $header) {
                ExcelCell::create([
                    'row_id' => $row->id,
                    'header_id' => $header->id,
                    'value' => null,
                    'updated_by' => $user->id,
                ]);
            }

            ActivityLog::create([
                'excel_file_id' => $excelFile->id,
                'row_id' => $row->id,
                'header_id' => null,
                'old_value' => null,
                'new_value' => 'New row added',
                'action' => 'row_created',
                'user_id' => $user->id,
            ]);

            $this->recalculateFile($excelFile, $user->id);
            $this->refreshFileStatus($excelFile);
        });

        return redirect()
            ->route('uploaded-files.show', $excelFile->id)
            ->with('success', 'New row added successfully.');
    }

    public function destroy(ExcelFile $excelFile)
    {
        $user = auth()->user();

        if (! $user->hasRole('admin') && ! $user->hasRole('merchant')) {
            abort(403, 'You are not allowed to delete this file.');
        }

        DB::transaction(function () use ($excelFile, $user) {
            ActivityLog::create([
                'excel_file_id' => $excelFile->id,
                'row_id' => null,
                'header_id' => null,
                'old_value' => $excelFile->original_file_name ?? $excelFile->file_name ?? 'file',
                'new_value' => null,
                'action' => 'file_deleted',
                'user_id' => $user->id,
            ]);

            $rowIds = $excelFile->rows()->pluck('id');

            if ($rowIds->isNotEmpty()) {
                ExcelCell::whereIn('row_id', $rowIds)->delete();
            }

            $excelFile->rows()->delete();

            if (! empty($excelFile->file_path) && Storage::exists($excelFile->file_path)) {
                Storage::delete($excelFile->file_path);
            }

            $excelFile->delete();
        });

        return redirect()
            ->route($user->hasRole('admin') ? 'admin.workspace' : 'merchant.workspace', $user->hasRole('admin') ? [] : ['tab' => 'files'])
            ->with('success', 'File deleted successfully.');
    }

    public function updateLock(Request $request, ExcelFile $excelFile)
    {
        $user = auth()->user();

        if (! $user->hasRole('admin')) {
            abort(403, 'Only admin users can lock or unlock workspace files.');
        }

        $validated = $request->validate([
            'locked' => ['nullable', 'boolean'],
            'lock_scope' => ['required', 'in:all_users,specific_roles,specific_users'],
            'locked_user_ids' => ['nullable', 'array'],
            'locked_user_ids.*' => ['integer', 'exists:users,id'],
            'locked_role_ids' => ['nullable', 'array'],
            'locked_role_ids.*' => ['integer', 'exists:roles,id'],
            'lock_reason' => ['nullable', 'string', 'max:500'],
        ]);

        $isLocked = (bool) ($validated['locked'] ?? false);
        $scope = $validated['lock_scope'] ?? 'all_users';

        $lockedUserIds = collect($validated['locked_user_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        $lockedRoleIds = collect($validated['locked_role_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($isLocked && $scope === 'specific_users' && $lockedUserIds->isEmpty()) {
            return back()->with('warning', 'Please select at least one user for specific user lock.');
        }

        if ($isLocked && $scope === 'specific_roles' && $lockedRoleIds->isEmpty()) {
            return back()->with('warning', 'Please select at least one role for specific role lock.');
        }

        $oldLockText = $this->fileLockInfo($excelFile)['summary'];

        DB::transaction(function () use ($excelFile, $user, $isLocked, $scope, $lockedUserIds, $lockedRoleIds, $validated, $oldLockText) {
            $excelFile->forceFill([
                'is_locked' => $isLocked,
                'lock_scope' => $isLocked ? $scope : 'all_users',
                'locked_user_ids' => $isLocked && $scope === 'specific_users' ? $lockedUserIds->all() : [],
                'locked_role_ids' => $isLocked && $scope === 'specific_roles' ? $lockedRoleIds->all() : [],
                'lock_reason' => $isLocked ? trim((string) ($validated['lock_reason'] ?? '')) : null,
                'locked_by' => $isLocked ? $user->id : null,
                'locked_at' => $isLocked ? now() : null,
            ])->save();

            ActivityLog::create([
                'excel_file_id' => $excelFile->id,
                'row_id' => null,
                'header_id' => null,
                'old_value' => $oldLockText,
                'new_value' => $this->fileLockInfo($excelFile->fresh())['summary'],
                'action' => $isLocked ? 'file_locked' : 'file_unlocked',
                'user_id' => $user->id,
            ]);
        });

        return back()->with('success', $isLocked
            ? 'File lock saved. Matching users can view this file but cannot edit or update records.'
            : 'File unlocked successfully.');
    }

    private function notifyOtherRolesAboutFileUpdate(ExcelFile $excelFile, array $changedCells, string $batchId, $actor): void
    {
        $users = User::where('id', '!=', $actor->id)
            ->whereDoesntHave('roles', function ($query) {
                $query->where('name', 'merchant');
            })
            ->get();

        foreach ($users as $user) {
            $notification = AppNotification::create([
                'user_id' => $user->id,
                'actor_id' => $actor->id,
                'excel_file_id' => $excelFile->id,
                'type' => 'excel_updated',
                'title' => 'File updated by merchant',
                'message' => $actor->name . ' updated ' . count($changedCells) . ' cell(s) in ' . ($excelFile->original_file_name ?? $excelFile->file_name ?? 'Excel file'),
                'data' => [
                    'batch_id' => $batchId,
                    'changed_cells' => $changedCells,
                ],
            ]);

            $notification->update([
                'url' => route('uploaded-files.show', $excelFile->id) . '?' . http_build_query([
                    'notification' => $notification->id,
                    'batch' => $batchId,
                ]),
            ]);
        }
    }

    /**
     * Latest Payment Request Approval (PRA) date per worksheet row for this file.
     * Preview PRAs are ignored. When a row appears in more than one PRA the most
     * recently created PRA wins.
     *
     * @return array<int, array{submission: ?Carbon, reqd: ?Carbon}>
     */
    private function praDatesByRow(ExcelFile $excelFile): array
    {
        $items = \App\Models\PaymentRequestItem::query()
            ->where('excel_file_id', $excelFile->id)
            ->whereNotNull('excel_row_id')
            ->whereHas('paymentRequest', fn ($q) => $q->where('status', '!=', 'preview'))
            ->with(['paymentRequest:id,created_at,status'])
            ->get(['id', 'excel_row_id', 'payment_required_date', 'payment_request_id']);

        $byRow = [];

        foreach ($items as $item) {
            $pr = $item->paymentRequest;
            if (! $pr) {
                continue;
            }

            $rowId = (int) $item->excel_row_id;
            $existing = $byRow[$rowId] ?? null;

            // Latest PRA wins (by created_at, tie-break on id).
            if ($existing) {
                $isNewer = $pr->created_at && $existing['created_at']
                    && ($pr->created_at->gt($existing['created_at'])
                        || ($pr->created_at->eq($existing['created_at']) && $pr->id > $existing['pr_id']));
                if (! $isNewer) {
                    continue;
                }
            }

            $byRow[$rowId] = [
                'created_at' => $pr->created_at,
                'pr_id' => $pr->id,
                'submission' => $pr->created_at,
                'reqd' => $item->payment_required_date, // Carbon (date cast) or null
            ];
        }

        return $byRow;
    }

    public function recalculateFile(ExcelFile $excelFile, ?int $userId = null, array $exceptRowIds = []): void
    {
        $userId = $userId ?: auth()->id();
        $exceptRowIds = collect($exceptRowIds)->map(fn ($id) => (int) $id)->flip();

        $headers = ExcelHeader::where('is_active', true)
            ->orderBy('position')
            ->get();

        $headerIdByKey = $this->buildHeaderIdLookup($headers);
        DB::disableQueryLog();
        @set_time_limit(300);

        $rows = $excelFile->rows()
            ->with(['cells'])
            ->orderBy('row_number')
            ->get();

        $praDates = $this->praDatesByRow($excelFile);
        $previousFormulaKey = null;

        foreach ($rows as $row) {
            if ($exceptRowIds->has((int) $row->id)) {
                continue;
            }

            $cellMap = [];
            foreach ($row->cells as $cell) {
                $cellMap[$cell->header_id] = $cell;
            }

            $styleOrBuyer = $this->valAny($cellMap, $headerIdByKey, [
                'style_name', 'style', 'buyer_name',
            ]);
            $gmtsColorName = $this->valAny($cellMap, $headerIdByKey, [
                'gmts_color_name', 'gmts_colour_name', 'gmts_color',
            ]);
            $contractNumber = $this->valAny($cellMap, $headerIdByKey, [
                'initial_contract_number', 'contract_number', 'customer_contract', 'gmnts_po_number', 'gmts_po_number', 'po_number',
            ]);

            $formulaParts = array_filter([
                trim((string) $gmtsColorName),
                trim((string) $contractNumber),
                trim((string) $styleOrBuyer),
            ], fn ($value) => $value !== '');
            $formulaKey = implode('|', $formulaParts);

            $contractShipmentDate = $this->asDate($this->valAny($cellMap, $headerIdByKey, [
                'contract_shipment_date', 'initial_contract_shipment_date', 'po_shipment_date',
            ]));
            $bomQuantity = $this->num($this->valAny($cellMap, $headerIdByKey, [
                'bom_quantity', 'bom_qty', 'bom_qnty',
            ]));
            $customerContractQtySource = $this->num($this->valAny($cellMap, $headerIdByKey, [
                'bom_quantity', 'customer_contract_quantity', 'customer_po_quantity', 'order_qty', 'gmts_order_qty', 'gmts_order_quantity',
            ]));
            $bookingConsumption = $this->num($this->valAny($cellMap, $headerIdByKey, [
                'booking_consumption_from_cad', 'initial_consumption', 'booking_yy', 'consumption',
            ]));
            $costingYyInSms = $this->num($this->valAny($cellMap, $headerIdByKey, [
                'costing_yy_in_sms', 'costing_yy', 'yy_in_sms',
            ]));
            $orderingWastage = $this->percent($this->valAny($cellMap, $headerIdByKey, [
                'wastage_for_ordering_percent', 'waste_percent', 'wastage_percent', 'waste',
            ]));
            // If this value already exists in the uploaded/saved row, use it for dependent formulas.
            // Otherwise calculate it from Booking Consumption from CAD and % Wastage for ordering.
            $existingConsumptionInclYy = $this->num($this->valAny($cellMap, $headerIdByKey, [
                'consumption_based_on_which_materials_order_including_yy',
                'consumption_incl_yy',
                'consumption_including_yy',
                'yy_waste',
            ]));
            $materialsOrderedRaw = $this->valAny($cellMap, $headerIdByKey, [
                'materials_ordered', 'material_ordered',
            ]);
            $materialsOrdered = $this->num($materialsOrderedRaw);
            $materialPiNumber = $this->valAny($cellMap, $headerIdByKey, [
                'material_pi_number', 'pi_number', 'vendor_pi_number',
            ]);
            $piRate = $this->num($this->valAny($cellMap, $headerIdByKey, [
                'pi_rate', 'invoiced_rate_scm', 'invoiced_rate',
            ]));
            $pmtDocNo = $this->valAny($cellMap, $headerIdByKey, [
                'pmt_doc_no', 'payment_doc_no', 'payment_reference_number', 'payment_ref_no',
            ]);
            $blAwbNo = $this->valAny($cellMap, $headerIdByKey, [
                'bl_awb_no', 'bl_no', 'awb_no',
            ]);
            $committedExMill = $this->asDate($this->valAny($cellMap, $headerIdByKey, [
                'committed_ex_mill', 'committed_x_fty_date', 'committed_ex_fty_date', 'committed_ex_fty', 'committed_x_fty',
            ]));
            $committedEtd = $this->asDate($this->valAny($cellMap, $headerIdByKey, [
                'committed_etd', 'commited_etd',
            ]));
            $committedEta = $this->asDate($this->valAny($cellMap, $headerIdByKey, [
                'committed_eta',
            ]));
            $existingCommittedInhouse = $this->asDate($this->valAny($cellMap, $headerIdByKey, [
                'committed_inhouse', 'committed_in_house',
            ]));
            $ata = $this->asDate($this->valAny($cellMap, $headerIdByKey, [
                'ata',
            ]));
            $actualInhouse = $this->asDate($this->valAny($cellMap, $headerIdByKey, [
                'actual_inhouse', 'actual_in_house',
            ]));

            $invoicedQtyScm = $this->num($this->valAny($cellMap, $headerIdByKey, [
                'invoiced_qty_scm', 'invoiced_qty',
            ]));
            $invoicedRateScm = $this->num($this->valAny($cellMap, $headerIdByKey, [
                'invoiced_rate_scm', 'invoiced_rate', 'pi_rate',
            ]));
            $invoicedQtyStore = $this->num($this->valAny($cellMap, $headerIdByKey, [
                'invoiced_qty_store', 'in_house_receipt_qty', 'receipt_qty',
            ]));
            $invoicedRateStore = $this->num($this->valAny($cellMap, $headerIdByKey, [
                'invoiced_rate_store', 'invoiced_rate_scm', 'invoiced_rate', 'pi_rate',
            ]));
            $receiptQty = $this->num($this->valAny($cellMap, $headerIdByKey, [
                'receipt_qty', 'in_house_receipt_qty', 'receiving', 'inhouse_qty',
            ]));
            $productionConsumption = $this->num($this->valAny($cellMap, $headerIdByKey, [
                'production_consumption', 'prod_yy', 'production_yy',
            ]));
            $productionWastage = $this->percent($this->valAny($cellMap, $headerIdByKey, [
                'production_wastage_percent', 'prod_wastage_percent', 'prod_wastage',
            ]));
            $issuedQty = $this->num($this->valAny($cellMap, $headerIdByKey, [
                'issued_qty', 'issued',
            ]));

            $shipmentMonth = $contractShipmentDate ? $contractShipmentDate->format('M') : null;
            $customerContractQty = ($formulaKey !== '' && $formulaKey === $previousFormulaKey) ? 0 : $customerContractQtySource;
            $pcdRequired = $contractShipmentDate ? $contractShipmentDate->copy()->subDays(45) : null;
            $orderToBePlacedBy = $pcdRequired ? $pcdRequired->copy()->subDays(70) : null;

            $calculatedConsumptionInclYy = $bookingConsumption * (1 + $orderingWastage);
            $consumptionInclYy = $existingConsumptionInclYy != 0 ? $existingConsumptionInclYy : $calculatedConsumptionInclYy;
            $materialsToBeOrdered = round($consumptionInclYy * $customerContractQtySource, 0);
            $shortExcessOrdered = round($materialsOrdered - $materialsToBeOrdered, 0);

            $materialOrderStatus = 'PO Pending';
            if (! blank($materialsOrderedRaw)) {
                if ($shortExcessOrdered <= ($materialsToBeOrdered * -1)) {
                    $materialOrderStatus = 'PO Pending';
                } elseif ($shortExcessOrdered < 0) {
                    $materialOrderStatus = 'Short PO Qty';
                } elseif ($shortExcessOrdered == 0) {
                    $materialOrderStatus = 'PO Raised';
                } else {
                    $materialOrderStatus = 'Excess Qty PO';
                }
            }

            $piStatus = $materialOrderStatus === 'PO Pending'
                ? 'Waiting for PO'
                : (blank($materialPiNumber) ? 'PI Pending' : 'PI Received');

            $piAmount = $piRate * $materialsOrdered;

            $paymentReqdDate = $committedExMill ? $committedExMill->copy()->subDays(7) : null;

            // A created PRA overrides the formula: Payment Req'd Date follows the
            // date confirmed on the PRA, and PI Summary Submission Date follows the
            // PRA's creation date. Rows without a PRA keep the formula above.
            $pra = $praDates[(int) $row->id] ?? null;
            $piSummarySubmissionDate = $pra['submission'] ?? null;
            if ($pra && $pra['reqd']) {
                $paymentReqdDate = $pra['reqd'];
            }

            $paymentStatus = $piStatus !== 'PI Received'
                ? $piStatus
                : (blank($pmtDocNo) ? 'Pmt Pending' : 'Pmt Done');

            $blStatus = $paymentStatus !== 'Pmt Done'
                ? $paymentStatus
                : (blank($blAwbNo) ? 'BL Pending' : 'BL raised');

            $arrivalStatus = $blStatus;
            if ($ata) {
                $arrivalStatus = $ata->gt(now()) ? 'Sailed but not arrived' : 'Arrived';
            } elseif ($committedEta) {
                if ($committedEtd && $committedEtd->gt(today())) {
                    $arrivalStatus = 'Not Sailed';
                } elseif ($committedEta->lt(today())) {
                    $arrivalStatus = 'Late';
                } else {
                    $arrivalStatus = 'Sailed but not arrived';
                }
            }

            $committedInhouse = $committedEta ? $committedEta->copy()->addDays(7) : $existingCommittedInhouse;
            $finalStatus = $actualInhouse ? 'Inhouse' : $arrivalStatus;
            $pcdAsPerCommittedInhouse = $committedInhouse ? $committedInhouse->copy()->addDays(2) : null;

            $isStorePayment = strtoupper(trim((string) $pmtDocNo)) === 'STORES';
            $invoicedAmountScm = $isStorePayment ? 0 : ($invoicedQtyScm * $invoicedRateScm);
            $invoicedAmountStore = $invoicedQtyStore * $invoicedRateStore;

            $gmntsPoNumber = $contractNumber;
            $gmtsOrderQtySource = $this->num($this->valAny($cellMap, $headerIdByKey, [
                'gmts_order_qty', 'gmts_order_quantity', 'gmts_order_qty_store',
            ]));
            $gmtsOrderQty = $gmtsOrderQtySource != 0 ? $gmtsOrderQtySource : $customerContractQtySource;

            $productionConsInclWastage = $productionConsumption * (1 + $productionWastage);
            $requirement = $productionConsInclWastage * $gmtsOrderQty;
            $excessShortage = $receiptQty - $requirement;
            $liabilityQty = $excessShortage > 0 ? $excessShortage : 0;

            // Excel formula: Buyer Liability = (BOM Quantity * Consumption incl. YY) - (GMTS Order Qty * Costing YY in SMS)
            // Important: do not clamp negative result to 0. Excel can return negative buyer liability.
            $buyerFormulaConsumptionInclYy = $this->num($this->valAny($cellMap, $headerIdByKey, [
                'consumption_based_on_which_materials_order_including_yy',
                'consumption_incl_yy',
                'consumption_including_yy',
                'yy_waste',
            ]));
            $buyerFormulaConsumptionInclYy = $buyerFormulaConsumptionInclYy != 0
                ? $buyerFormulaConsumptionInclYy
                : $consumptionInclYy;

            $buyerFormulaGmtsOrderQty = $this->num($this->valAny($cellMap, $headerIdByKey, [
                'gmts_order_qty',
                'gmts_order_quantity',
                'gmt_order_qty',
                'customer_contract_quantity',
                'customer_contract_qty',
            ]));
            $buyerFormulaGmtsOrderQty = $buyerFormulaGmtsOrderQty != 0
                ? $buyerFormulaGmtsOrderQty
                : $gmtsOrderQty;

            $buyerLiability = ($bomQuantity * $buyerFormulaConsumptionInclYy) - ($buyerFormulaGmtsOrderQty * $costingYyInSms);
            $buyerLiabilityValue = $buyerLiability * $piRate;
            $liabilityBasedOnReceiving = $receiptQty - $materialsToBeOrdered;

            $shortExcessIssued = $requirement - $issuedQty;
            $returnBackToStores = $shortExcessIssued < 0 ? 0 : $shortExcessIssued;
            $deadStockQuantity = ($receiptQty - $issuedQty) - $returnBackToStores;

            $materialCostValue = $issuedQty * $invoicedRateStore;
            $deadStockValue = $deadStockQuantity * $invoicedRateStore;
            $liabilityStockValue = $liabilityQty * $invoicedRateStore;
            $shortExcessValue = $excessShortage * $invoicedRateStore;

            $this->setCalcAny($row->id, $cellMap, $headerIdByKey, ['shipment_month'], $shipmentMonth, $userId);
            $this->setCalcAny($row->id, $cellMap, $headerIdByKey, ['customer_contract_quantity', 'customer_po_quantity'], $this->fmtNum($customerContractQty), $userId);
            $this->setCalcAny($row->id, $cellMap, $headerIdByKey, ['pcd_required'], $this->fmtDate($pcdRequired), $userId);
            $this->setCalcAny($row->id, $cellMap, $headerIdByKey, ['order_to_be_placed_by', 'order_to_be_placed'], $this->fmtDate($orderToBePlacedBy), $userId);
            $this->setCalcAny($row->id, $cellMap, $headerIdByKey, ['consumption_incl_yy', 'consumption_based_on_which_materials_order_including_yy', 'yy_waste'], $this->fmtNum($consumptionInclYy), $userId);
            $this->setCalcAny($row->id, $cellMap, $headerIdByKey, ['materials_to_be_ordered', 'material_to_be_ordered'], $this->fmtNum($materialsToBeOrdered), $userId);
            $this->setCalcAny($row->id, $cellMap, $headerIdByKey, ['short_excess_ordered', 'short_excess_ordered_qty'], $this->fmtNum($shortExcessOrdered), $userId);
            $this->setCalcAny($row->id, $cellMap, $headerIdByKey, ['material_order_status'], $materialOrderStatus, $userId);
            $this->setCalcAny($row->id, $cellMap, $headerIdByKey, ['pi_status'], $piStatus, $userId);
            $this->setCalcAny($row->id, $cellMap, $headerIdByKey, ['pi_amount'], $this->fmtNum($piAmount), $userId);
            $this->setCalcAny($row->id, $cellMap, $headerIdByKey, ['payment_reqd_date', 'payment_req_d_date', 'payment_required_date'], $this->fmtDate($paymentReqdDate), $userId);
            if ($pra) {
                $this->setCalcAny($row->id, $cellMap, $headerIdByKey, ['pi_summary_submission_date', 'pi_summary_submission'], $this->fmtDate($piSummarySubmissionDate), $userId);
            }
            $this->setCalcAny($row->id, $cellMap, $headerIdByKey, ['payment_status'], $paymentStatus, $userId);
            $this->setCalcAny($row->id, $cellMap, $headerIdByKey, ['bl_status'], $blStatus, $userId);
            $this->setCalcAny($row->id, $cellMap, $headerIdByKey, ['arrival_status'], $arrivalStatus, $userId);
            $this->setCalcAny($row->id, $cellMap, $headerIdByKey, ['committed_inhouse', 'committed_in_house'], $this->fmtDate($committedInhouse), $userId);
            $this->setCalcAny($row->id, $cellMap, $headerIdByKey, ['final_status'], $finalStatus, $userId);
            $this->setCalcAny($row->id, $cellMap, $headerIdByKey, ['pcd_as_per_committed_inhouse', 'rm_inh_as_per_committed_inhouse'], $this->fmtDate($pcdAsPerCommittedInhouse), $userId);
            $this->setCalcAny($row->id, $cellMap, $headerIdByKey, ['invoiced_amount_scm', 'invoiced_amount'], $this->fmtNum($invoicedAmountScm), $userId);
            $this->setCalcAny($row->id, $cellMap, $headerIdByKey, ['invoiced_amount_store'], $this->fmtNum($invoicedAmountStore), $userId);
            $this->setCalcAny($row->id, $cellMap, $headerIdByKey, ['gmnts_po_number', 'gmts_po_number'], $gmntsPoNumber, $userId);
            $this->setCalcAny($row->id, $cellMap, $headerIdByKey, ['gmts_order_qty', 'gmts_order_quantity', 'gmts_order_qty_store'], $this->fmtNum($gmtsOrderQty), $userId);
            $this->setCalcAny($row->id, $cellMap, $headerIdByKey, ['production_cons_incl_wastage', 'production_consumption_including_wastage', 'prod_yy_wastage'], $this->fmtNum($productionConsInclWastage), $userId);
            $this->setCalcAny($row->id, $cellMap, $headerIdByKey, ['requirement'], $this->fmtNum($requirement), $userId);
            $this->setCalcAny($row->id, $cellMap, $headerIdByKey, ['excess_shortage', 'excess_shortage_qty'], $this->fmtNum($excessShortage), $userId);
            $this->setCalcAny($row->id, $cellMap, $headerIdByKey, ['liability', 'liability_qty'], $this->fmtNum($liabilityQty), $userId);
            $this->setCalcAny($row->id, $cellMap, $headerIdByKey, ['buyer_liability'], $this->fmtNum($buyerLiability), $userId);
            $this->setCalcAny($row->id, $cellMap, $headerIdByKey, ['buyer_liability_value'], $this->fmtNum($buyerLiabilityValue), $userId);
            $this->setCalcAny($row->id, $cellMap, $headerIdByKey, ['liability_based_on_receiving'], $this->fmtNum($liabilityBasedOnReceiving), $userId);
            $this->setCalcAny($row->id, $cellMap, $headerIdByKey, ['short_excess_issued'], $this->fmtNum($shortExcessIssued), $userId);
            $this->setCalcAny($row->id, $cellMap, $headerIdByKey, ['return_back_to_stores'], $this->fmtNum($returnBackToStores), $userId);
            $this->setCalcAny($row->id, $cellMap, $headerIdByKey, ['dead_stock_quantity'], $this->fmtNum($deadStockQuantity), $userId);
            $this->setCalcAny($row->id, $cellMap, $headerIdByKey, ['material_cost_value', 'material_issue_value'], $this->fmtNum($materialCostValue), $userId);
            $this->setCalcAny($row->id, $cellMap, $headerIdByKey, ['dead_stock_value'], $this->fmtNum($deadStockValue), $userId);
            $this->setCalcAny($row->id, $cellMap, $headerIdByKey, ['liability_stock_value'], $this->fmtNum($liabilityStockValue), $userId);
            $this->setCalcAny($row->id, $cellMap, $headerIdByKey, ['short_excess_value', 'short_and_excess_value'], $this->fmtNum($shortExcessValue), $userId);

            $previousFormulaKey = $formulaKey;
        }
    }

    private function buildHeaderIdLookup($headers): array
    {
        $lookup = [];

        foreach ($headers as $header) {
            $keys = [
                $header->header_key ?? null,
                $header->header_name ?? null,
                $header->formula_key ?? null,
                $this->normalizeHeaderKey($header->header_key ?? null),
                $this->normalizeHeaderKey($header->header_name ?? null),
                $this->normalizeHeaderKey($header->formula_key ?? null),
            ];

            foreach ($keys as $key) {
                if ($key !== null && $key !== '') {
                    $lookup[$key] = $header->id;
                }
            }
        }

        foreach ($this->headerAliases() as $canonical => $aliases) {
            if (isset($lookup[$canonical])) {
                continue;
            }

            foreach ($aliases as $alias) {
                $aliasKey = $this->normalizeHeaderKey($alias);
                if (isset($lookup[$aliasKey])) {
                    $lookup[$canonical] = $lookup[$aliasKey];
                    break;
                }
            }
        }

        return $lookup;
    }

    private function normalizeHeaderKey($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = strtolower(trim((string) $value));
        $value = str_replace(['&', '+'], ' and ', $value);
        $value = str_replace(["'", '’'], '', $value);
        $value = preg_replace('/[^a-z0-9]+/', '_', $value);
        $value = preg_replace('/_+/', '_', $value);

        return trim($value, '_');
    }

    private function headerAliases(): array
    {
        return [
            'po_no' => ['material po number', 'material po no', 'material purchase order', 'material purchase order number'],
            'po_date' => ['po date', 'material po date', 'material purchase order date'],
            'style_name' => ['style', 'buyer name'],
            'contract_number' => ['initial contract number', 'contract number', 'gmnts po number', 'gmts po number', 'po number'],
            'contract_shipment_date' => ['contract shipment date', 'initial contract shipment date', 'po shipment date'],
            'bom_quantity' => ['bom quantity', 'bom qty', 'bom qnty'],
            'customer_contract_quantity' => ['customer contract quantity', 'customer contract qty', 'customer po quantity', 'order qty', 'gmts order qty', 'gmts order quantity'],
            'booking_consumption_from_cad' => ['booking consumption from cad', 'booking consumption', 'cad consumption', 'booking cons from cad'],
            'initial_consumption' => ['booking consumption from cad', 'initial consumption', 'booking yy', 'consumption'],
            'costing_yy_in_sms' => ['costing yy in sms', 'costing yy', 'yy in sms', 'costing yy sms'],
            'wastage_for_ordering_percent' => ['% wastage for ordering', 'waste %', 'wastage %'],
            'consumption_incl_yy' => ['consumption based on which materials order including yy', 'consumption including yy', 'consumption incl yy', 'yy + waste %'],
            'short_excess_ordered' => ['(short)/excess ordered', '(short) / excess ordered', 'short excess ordered'],
            'payment_reqd_date' => ["payment req'd date", 'payment reqd date', 'payment required date'],
            'pi_summary_submission_date' => ['pi summary submission date', 'pi summary submission'],
            'pmt_doc_no' => ['pmt doc no', 'payment doc no', 'payment reference number', 'payment ref no'],
            'committed_ex_mill' => ['committed ex mill', 'committed x-fty date', 'committed x fty date', 'committed ex-fty date', 'committed ex fty date'],
            'bl_awb_no' => ['bl / awb no', 'bl awb no', 'bl no', 'awb no'],
            'committed_etd' => ['committed etd', 'commited etd'],
            'committed_eta' => ['committed eta', 'committed e.t.a', 'committed arrival date'],
            'committed_inhouse' => ['committed inhouse', 'committed in house', 'committed in-house'],
            'actual_inhouse' => ['actual inhouse', 'actual in-house'],
            'pcd_as_per_committed_inhouse' => ['pcd as per committed inhouse', 'rm inh as per committed inhouse'],
            'invoiced_qty_scm' => ['invoiced qty(scm)', 'invoiced qty scm', 'invoiced qty'],
            'invoiced_rate_scm' => ['invoiced rate(scm)', 'invoiced rate scm', 'invoiced rate'],
            'invoiced_amount_scm' => ['invoiced amount(scm)', 'invoiced amount scm', 'invoiced amount'],
            'invoiced_qty_store' => ['invoiced qty(store)', 'invoiced qty store'],
            'invoiced_rate_store' => ['invoiced rate(store)', 'invoiced rate store'],
            'invoiced_amount_store' => ['invoiced amount(store)', 'invoiced amount store'],
            'receipt_qty' => ['receipt qty', 'in-house / receipt qty', 'in house receipt qty'],
            'gmnts_po_number' => ['gmnts po number', 'gmts po number', 'gmt po number'],
            'gmts_order_qty' => ['gmts order qty', 'gmts order quantity', 'gmt order qty', 'customer contract quantity', 'customer contract qty'],
            'production_wastage_percent' => ['production wastage %', 'prod. wastage %', 'prod wastage %'],
            'production_cons_incl_wastage' => ['production consumption including wastage', 'production cons including wastage', 'prod. yy + wastage', 'prod yy waste'],
            'excess_shortage' => ['excess / (shortage)', 'excess shortage', '(short) / excess in-house qty'],
            'buyer_liability' => ['buyer liability'],
            'buyer_liability_value' => ['buyer liability value'],
            'liability_based_on_receiving' => ['liability based on receiving'],
            'short_excess_issued' => ['(short)/ excess issued', '(short) / excess issued', 'short excess issued'],
            'material_cost_value' => ['material cost value'],
            'dead_stock_value' => ['dead stock value'],
            'short_excess_value' => ['short & excess value', 'short and excess value'],
        ];
    }

    private function setCalcAny(int $rowId, array &$cellMap, array $headerIdByKey, array $headerKeys, $value, int $userId): void
    {
        foreach ($headerKeys as $headerKey) {
            $normalizedKey = $this->normalizeHeaderKey($headerKey);
            if ($normalizedKey !== null && isset($headerIdByKey[$normalizedKey])) {
                $this->setCalc($rowId, $cellMap, $headerIdByKey, $normalizedKey, $value, $userId);
                return;
            }
        }
    }

    private function setCalc(int $rowId, array &$cellMap, array $headerIdByKey, string $headerKey, $value, int $userId): void
    {
        if (! isset($headerIdByKey[$headerKey])) {
            return;
        }

        $headerId = $headerIdByKey[$headerKey];

        $cell = $cellMap[$headerId] ?? ExcelCell::firstOrNew([
            'row_id' => $rowId,
            'header_id' => $headerId,
        ]);

        $newValue = is_string($value) ? trim($value) : $value;
        $newValue = $newValue === '' ? null : $newValue;

        if ((string) ($cell->value ?? null) === (string) $newValue) {
            return;
        }

        $cell->value = $newValue;
        $cell->updated_by = $userId;
        $cell->save();

        $cellMap[$headerId] = $cell;
    }

    private function valAny(array $cellMap, array $headerIdByKey, array $keys): ?string
    {
        foreach ($keys as $key) {
            $normalizedKey = $this->normalizeHeaderKey($key);
            if ($normalizedKey === null || ! isset($headerIdByKey[$normalizedKey])) {
                continue;
            }

            $value = $this->val($cellMap, $headerIdByKey, $normalizedKey);
            if (! blank($value)) {
                return $value;
            }
        }

        return null;
    }

    private function val(array $cellMap, array $headerIdByKey, string $key): ?string
    {
        $normalizedKey = $this->normalizeHeaderKey($key);
        if ($normalizedKey === null || ! isset($headerIdByKey[$normalizedKey])) {
            return null;
        }

        $headerId = $headerIdByKey[$normalizedKey];
        return isset($cellMap[$headerId]) ? trim((string) $cellMap[$headerId]->value) : null;
    }

    private function num($value): float
    {
        if ($value === null || $value === '') {
            return 0;
        }

        $value = trim((string) $value);
        $isNegativeAccounting = preg_match('/^\(.*\)$/', $value) === 1;
        $value = str_replace(["\xC2\xA0", ',', ' ', '%', '(', ')'], '', $value);

        if (! is_numeric($value)) {
            return 0;
        }

        $number = (float) $value;
        return $isNegativeAccounting ? -1 * $number : $number;
    }

    private function percent($value): float
    {
        $n = $this->num($value);
        return $n > 1 ? ($n / 100) : $n;
    }

    private function asDate($value): ?Carbon
    {
        if (blank($value)) {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value);
        }

        if (is_numeric($value)) {
            try {
                return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $value));
            } catch (\Throwable $e) {
                try {
                    return Carbon::create(1899, 12, 30)->addDays((int) $value);
                } catch (\Throwable $e) {
                    return null;
                }
            }
        }

        $value = trim((string) $value);

        if ($value === '' || $value === '-' || preg_match('/^[mdy\/-]+$/i', $value)) {
            return null;
        }

        $formats = [
            'Y-m-d', 'Y/m/d', 'd-m-Y', 'd/m/Y', 'm-d-Y', 'm/d/Y',
            'd-M-Y', 'd M Y', 'M d, Y', 'm/d/y', 'd/m/y',
        ];

        foreach ($formats as $format) {
            try {
                $date = Carbon::createFromFormat($format, $value);
                if ($date !== false) {
                    return $date;
                }
            } catch (\Throwable $e) {
                // Try next format.
            }
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable $e) {
            return null;
        }
    }
    private function fmtDate(?Carbon $date): ?string
    {
        return $date ? $date->format('Y-m-d') : null;
    }

    private function fmtNum($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (abs($value - round($value)) < 0.000001) {
            return (string) round($value);
        }

        return rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.');
    }

    private function ensureMinimumRows(ExcelFile $excelFile, int $minimumRows = 20): void
    {
        $currentMaxRow = (int) $excelFile->rows()->max('row_number');

        if ($currentMaxRow >= $minimumRows) {
            return;
        }

        $headers = ExcelHeader::where('is_active', true)
            ->orderBy('position')
            ->get();

        for ($rowNumber = $currentMaxRow + 1; $rowNumber <= $minimumRows; $rowNumber++) {
            $row = ExcelRow::create([
                'excel_file_id' => $excelFile->id,
                'row_number' => $rowNumber,
            ]);

            foreach ($headers as $header) {
                ExcelCell::create([
                    'row_id' => $row->id,
                    'header_id' => $header->id,
                    'value' => null,
                    'updated_by' => auth()->id(),
                ]);
            }
        }

        $excelFile->update([
            'total_rows' => max((int) $excelFile->total_rows, $minimumRows),
        ]);
    }

    private function getRoleIds()
    {
        return Role::whereIn('name', auth()->user()->getRoleNames())->pluck('id');
    }

    private function canViewHeader($header, $roleIds): bool
    {
        if (auth()->user()->hasRole('admin')) {
            return true;
        }

        return $header->can_view_all || $roleIds->contains($header->owner_role_id);
    }

    private function canEditHeader($header, $roleIds): bool
    {
        if (auth()->user()->hasRole('admin')) {
            return true;
        }

        return $roleIds->contains($header->owner_role_id);
    }

    private function calculatedHeaderKeys(): array
    {
        $staticKeys = [
            'shipment_month',
            'customer_contract_quantity',
            'pcd_required',
            'order_to_be_placed_by',
            'order_to_be_placed',
            'consumption_incl_yy',
            'consumption_based_on_which_materials_order_including_yy',
            'materials_to_be_ordered',
            'short_excess_ordered',
            'material_order_status',
            'pi_status',
            'pi_amount',
            'payment_reqd_date',
            'pi_summary_submission_date',
            'pi_summary_submission',
            'payment_status',
            'bl_status',
            'arrival_status',
            'committed_inhouse',
            'committed_in_house',
            'final_status',
            'pcd_as_per_committed_inhouse',
            'invoiced_amount',
            'invoiced_amount_scm',
            'invoiced_amount_store',
            'gmnts_po_number',
            'gmts_po_number',
            'gmts_order_qty',
            'gmts_order_qty_store',
            'production_cons_incl_wastage',
            'production_consumption_including_wastage',
            'requirement',
            'excess_shortage',
            'liability',
            'liability_qty',
            'buyer_liability',
            'buyer_liability_value',
            'liability_based_on_receiving',
            'short_excess_issued',
            'return_back_to_stores',
            'dead_stock_quantity',
            'material_cost_value',
            'material_issue_value',
            'dead_stock_value',
            'liability_stock_value',
            'short_excess_value',
        ];

        $dynamicKeys = ExcelHeader::where('is_active', true)
            ->whereIn('value_mode', ['formula', 'conditional', 'auto', 'calculated'])
            ->get(['header_name', 'header_key', 'formula_key'])
            ->flatMap(function ($header) {
                return array_filter([
                    $this->normalizeHeaderKey($header->header_name ?? null),
                    $this->normalizeHeaderKey($header->header_key ?? null),
                    $this->normalizeHeaderKey($header->formula_key ?? null),
                ]);
            })
            ->all();

        return collect(array_merge($staticKeys, $dynamicKeys))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function isCalculatedHeader($header, array $calculatedHeaderKeys): bool
    {
        $valueMode = strtolower((string) ($header->value_mode ?? ''));

        if (in_array($valueMode, ['formula', 'conditional', 'auto', 'calculated'], true)) {
            return true;
        }

        $keys = [
            $this->normalizeHeaderKey($header->header_key ?? null),
            $this->normalizeHeaderKey($header->header_name ?? null),
            $this->normalizeHeaderKey($header->formula_key ?? null),
        ];

        foreach ($keys as $key) {
            if ($key !== null && in_array($key, $calculatedHeaderKeys, true)) {
                return true;
            }
        }

        foreach ($this->headerAliases() as $canonical => $aliases) {
            if (! in_array($canonical, $calculatedHeaderKeys, true)) {
                continue;
            }

            foreach ($aliases as $alias) {
                if ($this->normalizeHeaderKey($header->header_name ?? null) === $this->normalizeHeaderKey($alias)) {
                    return true;
                }
            }
        }

        return false;
    }
    private function fileLockInfo(ExcelFile $excelFile): array
    {
        if (! $excelFile->is_locked) {
            return [
                'locked' => false,
                'scope' => 'open',
                'summary' => 'Open',
                'reason' => '',
            ];
        }

        $scope = $excelFile->lock_scope ?: 'all_users';
        $summary = match ($scope) {
            'specific_users' => 'Locked for selected users',
            'specific_roles' => 'Locked for selected roles',
            default => 'Locked for all users',
        };

        return [
            'locked' => true,
            'scope' => $scope,
            'summary' => $summary,
            'reason' => $excelFile->lock_reason ?? '',
            'locked_by' => optional($excelFile->lockedBy)->name,
            'locked_at' => optional($excelFile->locked_at)->format('Y-m-d H:i'),
        ];
    }

    private function poLockedRowInfoForUser($rows, User $user)
    {
        $rows = collect($rows);

        if ($rows->isEmpty()) {
            return collect();
        }

        $poHeaderIds = $this->headerIdsForCanonical('po_no');

        if (empty($poHeaderIds)) {
            return collect();
        }

        $rowIds = $rows->pluck('id')->map(fn ($id) => (int) $id)->values();
        $rowPoNumbers = ExcelCell::query()
            ->whereIn('row_id', $rowIds->all())
            ->whereIn('header_id', $poHeaderIds)
            ->whereNotNull('value')
            ->whereRaw("TRIM(value) <> ''")
            ->get(['row_id', 'value'])
            ->mapWithKeys(fn (ExcelCell $cell) => [(int) $cell->row_id => trim((string) $cell->value)])
            ->filter();

        if ($rowPoNumbers->isEmpty()) {
            return collect();
        }

        $bookingPos = BookingPo::query()
            ->whereIn('po_no', $rowPoNumbers->unique()->values()->all())
            ->get()
            ->keyBy('po_no');

        return $rowPoNumbers->map(function (string $poNo) use ($bookingPos, $user) {
            $bookingPo = $bookingPos->get($poNo);

            if (! $bookingPo || ! $this->poLockAppliesToUser($bookingPo, $user)) {
                return null;
            }

            $control = $bookingPo->booking_data['admin_control'] ?? [];

            return [
                'po_no' => $bookingPo->po_no,
                'reason' => $control['lock_reason'] ?? '',
                'scope' => $control['lock_scope'] ?? 'all_users',
            ];
        })->filter();
    }

    private function poLockAppliesToUser(BookingPo $bookingPo, User $user): bool
    {
        $control = $bookingPo->booking_data['admin_control'] ?? [];

        if (! is_array($control) || ! (bool) ($control['locked'] ?? false)) {
            return false;
        }

        $scope = $control['lock_scope'] ?? 'all_users';

        if ($scope === 'specific_users') {
            return in_array((int) $user->id, collect($control['locked_user_ids'] ?? [])->map(fn ($id) => (int) $id)->all(), true);
        }

        if ($scope === 'specific_roles') {
            $lockedRoleIds = collect($control['locked_role_ids'] ?? [])->map(fn ($id) => (int) $id);

            if ($lockedRoleIds->isEmpty()) {
                return false;
            }

            $userRoleIds = $user->roles()->pluck('id')->map(fn ($id) => (int) $id);

            return $userRoleIds->intersect($lockedRoleIds)->isNotEmpty();
        }

        return true;
    }

    private function headerIdsForCanonical(string $canonical): array
    {
        $canonicalKey = $this->normalizeHeaderKey($canonical);
        $aliasKeys = collect($this->headerAliases()[$canonical] ?? [])
            ->map(fn ($alias) => $this->normalizeHeaderKey($alias))
            ->push($canonicalKey)
            ->filter()
            ->unique()
            ->values();

        if ($aliasKeys->isEmpty()) {
            return [];
        }

        return ExcelHeader::query()
            ->where('is_active', true)
            ->get(['id', 'header_name', 'header_key', 'formula_key'])
            ->filter(function ($header) use ($aliasKeys) {
                $keys = collect([
                    $this->normalizeHeaderKey($header->header_name ?? null),
                    $this->normalizeHeaderKey($header->header_key ?? null),
                    $this->normalizeHeaderKey($header->formula_key ?? null),
                ])->filter();

                return $keys->intersect($aliasKeys)->isNotEmpty();
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    private function refreshFileStatus(ExcelFile $excelFile): void
    {
        if ($excelFile->status === 'locked') {
            return;
        }

        $merchantRoleId = Role::where('name', 'merchant')->value('id');

        $targetHeaderIds = ExcelHeader::where('is_active', true)
            ->where('owner_role_id', '!=', $merchantRoleId)
            ->pluck('id');

        if ($targetHeaderIds->isEmpty()) {
            $excelFile->update(['status' => 'completed']);
            return;
        }

        $rows = $excelFile->rows()->with('cells')->get();

        if ($rows->isEmpty()) {
            $excelFile->update(['status' => 'pending']);
            return;
        }

        $filled = 0;
        $total = 0;

        foreach ($rows as $row) {
            foreach ($targetHeaderIds as $headerId) {
                $total++;

                $cell = $row->cells->firstWhere('header_id', $headerId);
                $value = trim((string) ($cell->value ?? ''));

                if ($value !== '') {
                    $filled++;
                }
            }
        }

        if ($filled === 0) {
            $status = 'pending';
        } elseif ($filled < $total) {
            $status = 'processing';
        } else {
            $status = 'completed';
        }

        $excelFile->update(['status' => $status]);
    }
}
