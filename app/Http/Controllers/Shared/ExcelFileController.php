<?php

namespace App\Http\Controllers\Shared;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\BookingPo;
use App\Models\ExcelCell;
use App\Models\ExcelFile;
use App\Models\ExcelHeader;
use App\Models\ExcelRow;
use App\Models\MaterialStockLedger;
use App\Services\HeaderAliasResolver;
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
    /**
     * Lets a user work the Store role's workspace columns without holding the
     * Store role. Granted per user, never to a role — see getRoleIds().
     */
    private const PERMISSION_STORE_COLUMNS = 'bom.store_columns.edit';

    public function show(ExcelFile $excelFile)
    {
        DB::disableQueryLog();
        @set_time_limit(300);

        $user = auth()->user();
        $isFileLockedForUser = $excelFile->isLockedForUser($user);
        $fileLockInfo = $this->fileLockInfo($excelFile);

        $roleIds = $this->getRoleIds();

        $minimumVisibleRows = ExcelRow::MINIMUM_VISIBLE_ROWS;
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

        // Buyer scoping is a second, independent reason a file can be read-only
        // for a merchant. Rendering it exactly as the admin lock does keeps the
        // screen honest: a user who cannot save must not be shown inputs.
        $isBuyerLockedForUser = $excelFile->isBuyerLockedForUser($user);

        $editableHeaderIds = ($isFileLockedForUser || $isBuyerLockedForUser)
            ? []
            : $headers
                ->filter(fn ($header) => $this->canEditHeader($header, $roleIds))
                ->reject(fn ($header) => $this->isCalculatedHeader($header, $calculatedHeaderKeys))
                ->pluck('id')
                ->all();

        $orderInfo = $this->buildOrderInfo($excelFile, $visibleHeaderIds);

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
                $query->whereLike('row_number', '%' . $globalSearch . '%')
                    ->orWhereHas('cells', function ($cellQuery) use ($globalSearch, $visibleHeaderIds) {
                        $cellQuery->whereIn('header_id', $visibleHeaderIds)
                            ->whereLike('value', '%' . $globalSearch . '%');
                    });
            });
        }

        foreach ($columnFilters as $filterKey => $filterValue) {
            if ($filterKey === '__row') {
                $rowsQuery->whereLike('row_number', '%' . $filterValue . '%');
                continue;
            }

            $headerId = (int) $filterKey;

            if (! in_array($headerId, $visibleHeaderIds, true)) {
                continue;
            }

            $rowsQuery->whereHas('cells', function ($cellQuery) use ($headerId, $filterValue) {
                $cellQuery->where('header_id', $headerId)
                    ->whereLike('value', '%' . $filterValue . '%');
            });
        }

        // Every row the current filter matches, not just the page. Rows off the
        // page have no checkbox to tick, so "select all matching" has to be fed
        // ids directly. Ids only — a few hundred integers.
        $allMatchingRowIds = (clone $rowsQuery)->orderBy('row_number')->pluck('id')->all();

        // The exact-match style group, computed INDEPENDENTLY of the substring
        // filter above. The screen keeps showing what the merchant filtered —
        // narrowing it under them would be a surprise — while the bulk action
        // acts only on the rows that match exactly. Where the two counts differ
        // the view says so, so the gap is never silent.
        $styleMatchValues = $this->styleMatchFilterValues($columnFilters);
        $styleMatchRowIds = $this->styleMatchRowIds($excelFile, $styleMatchValues);

        // header_key => header id, so the view can post the style values back
        // under the same filters[<header id>] keys the action reads. Resolved
        // here rather than hard-coded because ids differ between environments.
        $styleMatchHeaderIds = $styleMatchValues === []
            ? []
            : ExcelHeader::whereIn('header_key', array_keys($styleMatchValues))
                ->pluck('id', 'header_key')
                ->all();

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

        $canAddRow = ($user->hasRole('admin') || $user->hasRole('merchant'))
            && ! $isFileLockedForUser
            && ! $isBuyerLockedForUser;
        // Deletion follows the SAME two locks every other mutation does. This
        // was role-only, so a locked file rendered the read-only banner and an
        // active red Delete button on the same screen. destroy() now refuses
        // the request outright; this hides the button that should never have
        // offered it. The server check is the fix — this is the second layer.
        $canDeleteFile = ($user->hasRole('admin') || $user->hasRole('merchant'))
            && ! $isFileLockedForUser
            && ! $isBuyerLockedForUser;

        // Printing a file this user may not act on is not an action they have
        // any business taking, so the button goes with the Delete one.
        $canPrintFile = ! $isFileLockedForUser && ! $isBuyerLockedForUser;

        // Which rows on THIS PAGE may be deleted, worked out in one batch.
        // Asking per row would be eleven queries each — about 1,650 for a
        // 150-row page — for a button most rows will not show. The view does an
        // isset() against this and decides nothing itself, so it cannot drift
        // from what deleteRow() will actually allow.
        $deletableRowIds = ExcelRow::deletableIdsFor($rows->getCollection(), $user);

        // Taking the whole original workbook away is a wider act than reading a
        // sheet on screen, so it follows download()'s own gate exactly: the
        // admin lock excuses an admin, the buyer lock excuses nobody.
        $canDownloadFile = (! $isFileLockedForUser || $user->hasRole('admin'))
            && ! $isBuyerLockedForUser;

        // The change history names STAFF and shows another buyer's edit
        // timeline, neither of which the grid already exposes.
        $canViewHistory = $canPrintFile;

        // Which cells carry a "Needs update" marker on a duplicated row.
        $duplicateNeedsUpdateHeaderIds = $this->duplicateNeedsUpdateHeaderIds();

        // The focused form inside the Add New Order dialog — the same columns
        // the markers above flag, narrowed to the ones this user may edit.
        $addOrderHeaders = $canAddRow
            ? $this->addOrderFormHeaders($editableHeaderIds)
            : collect();

        // Latest PRA dates per PI No., pre-formatted for the live (client-side)
        // formulas. Keyed by normalised PI No. so every row sharing that PI No.
        // picks up the same dates.
        $piPraDates = collect($this->praDatesByPiNumber($excelFile))
            ->map(fn ($pra) => [
                'submission' => $this->fmtDate($pra['submission']),
                'reqd' => $pra['reqd'] ? $this->fmtDate($pra['reqd']) : null,
            ])
            ->all();

        // Every cell edit has been logged since the workspace was built, but
        // nothing ever displayed it. With six departments editing the same
        // sheet, "who changed this?" is the question that actually gets asked.
        /*
         * NOT LOADED AT ALL when this user may not see it.
         *
         * The history is a Bootstrap collapse rendered inline, so its rows —
         * staff names, old value → new value, timestamps — sit in the page
         * source whether the panel is open or not. Hiding the BUTTON would
         * leave every one of them one "view source" away. Withholding the data
         * is the fix; the hidden button is the cosmetic half.
         */
        $activityLog = $canViewHistory
            ? ActivityLog::with(['user', 'header'])
                ->where('excel_file_id', $excelFile->id)
                ->latest('id')
                ->limit(50)
                ->get()
            : collect();

        $activityTotal = ActivityLog::where('excel_file_id', $excelFile->id)->count();

        // This user's own department progress on THIS file. Null for admin and
        // management, who own no required columns and get the all-department
        // table on their own dashboard instead — a department user never
        // receives another department's figures from here.
        $activityService = app(\App\Services\DepartmentActivityService::class);
        $myDepartmentRole = $activityService->departmentRoleFor(auth()->user());
        $myDepartmentProgress = $myDepartmentRole
            ? $activityService->forRole($myDepartmentRole, $excelFile)
            : null;

        return view('shared.excel-files.show', compact(
            'excelFile',
            'headers',
            'rows',
            'myDepartmentProgress',
            'activityLog',
            'activityTotal',
            'editableHeaderIds',
            'calculatedHeaderKeys',
            'calculatedHeaderIds',
            'canAddRow',
            'deletableRowIds',
            'canDeleteFile',
            'canPrintFile',
            'canDownloadFile',
            'canViewHistory',
            'highlightBatchId',
            'highlightedCellKeys',
            'lockedRowIds',
            'lockedRowInfo',
            'isFileLockedForUser',
            'isBuyerLockedForUser',
            'fileLockInfo',
            'orderInfo',
            'perPage',
            'piPraDates',
            'duplicateNeedsUpdateHeaderIds',
            'allMatchingRowIds',
            'styleMatchValues',
            'styleMatchRowIds',
            'styleMatchHeaderIds',
            'addOrderHeaders'
        ));
    }

    /**
     * The boxes in the Order Information header, and the real spread of each.
     *
     * Each box used to read the FIRST ROW and present that single value as if
     * it described the whole file. For two of them it does not. On the FA-27
     * outerwear file (499 rows) the header announced style "Anetinna-1" and
     * contract "4800095665"; each is true of 31 rows — 6% of the file. Buyer
     * Name and Season Name genuinely are one value throughout, so the answer is
     * not to convert every box but to let each box describe its own field: one
     * distinct value renders exactly the plain box it always did, more than one
     * renders a count and a filter. A file whose shipment dates vary therefore
     * gets the filter without a code change, and this file — where that column
     * is entirely empty — still gets the plain box.
     *
     * The "Contract Number" box was also looking up a header called
     * "Contract Number", which does not exist: the real column is "Initial
     * Contract Number", so the box could never fill and always showed "-".
     * Both names are accepted here and the box keeps the shorter label.
     *
     * @param  array<int, int>  $visibleHeaderIds  Headers this user's role may
     *                                             see. The filter is applied by
     *                                             show() only for these, so a
     *                                             box for a hidden column must
     *                                             not offer a dropdown that
     *                                             would silently do nothing.
     * @return array<string, array{value: string, header_id: int|null, options: array<int, array{value: string, count: int}>, distinct: int, noun: string, filter_value: string}>
     */
    private function buildOrderInfo(ExcelFile $excelFile, array $visibleHeaderIds = []): array
    {
        // label => [candidate header names in preference order, plural noun].
        $fields = [
            'Buyer Name' => [['Buyer Name'], 'buyers'],
            'Season Name' => [['Season Name'], 'seasons'],
            'Style Name' => [['Style Name'], 'styles'],
            'Contract Number' => [['Initial Contract Number', 'Contract Number'], 'contract numbers'],
            'Contract Shipment Date' => [['Contract Shipment Date'], 'shipment dates'],
        ];

        $appliedFilters = collect((array) request()->query('filters', []))
            ->map(fn ($value) => is_string($value) ? trim($value) : '')
            ->all();

        $orderInfo = [];

        foreach ($fields as $label => [$candidates, $noun]) {
            $orderInfo[$label] = [
                'value' => '',
                'header_id' => null,
                'options' => [],
                'distinct' => 0,
                'noun' => $noun,
                'filter_value' => '',
            ];
        }

        $rowIds = $excelFile->rows()->orderBy('row_number')->pluck('id');

        if ($rowIds->isEmpty()) {
            return $orderInfo;
        }

        $firstRowId = $rowIds->first();

        $headerIdsByName = ExcelHeader::query()
            ->whereIn('header_name', collect($fields)->pluck(0)->flatten()->unique()->all())
            ->pluck('id', 'header_name');

        if ($headerIdsByName->isEmpty()) {
            return $orderInfo;
        }

        // Only the columns these boxes describe, and only three small fields of
        // them. The distinct values are then counted in PHP rather than with a
        // GROUP BY: MySQL's default collation groups case-insensitively and
        // PostgreSQL's does not, so the same file would report a different
        // number of styles on local and on live. A count shown to a merchant as
        // "6 styles" has to be the same number on both.
        $cells = ExcelCell::query()
            ->whereIn('row_id', $rowIds->all())
            ->whereIn('header_id', $headerIdsByName->values()->all())
            ->get(['row_id', 'header_id', 'value'])
            ->groupBy('header_id');

        foreach ($fields as $label => [$candidates, $noun]) {
            $headerId = null;

            foreach ($candidates as $candidate) {
                if ($headerIdsByName->has($candidate)) {
                    $headerId = (int) $headerIdsByName[$candidate];
                    break;
                }
            }

            if ($headerId === null) {
                continue;
            }

            $forHeader = $cells->get($headerId, collect());

            $orderInfo[$label]['value'] = trim((string) ($forHeader
                ->firstWhere('row_id', $firstRowId)->value ?? ''));

            // Blank cells are not a value. A file where two thirds of the rows
            // are unfilled has as many styles as it has styles, not one more.
            $counts = [];

            foreach ($forHeader as $cell) {
                $value = trim((string) ($cell->value ?? ''));

                if ($value === '') {
                    continue;
                }

                $counts[$value] = ($counts[$value] ?? 0) + 1;
            }

            arsort($counts);

            $orderInfo[$label]['distinct'] = count($counts);

            // A dropdown is only honest if show() will act on it, which it does
            // for visible headers only.
            if (count($counts) > 1 && in_array($headerId, $visibleHeaderIds, true)) {
                $orderInfo[$label]['header_id'] = $headerId;
                $orderInfo[$label]['filter_value'] = (string) ($appliedFilters[$headerId] ?? '');

                foreach ($counts as $value => $count) {
                    $orderInfo[$label]['options'][] = [
                        'value' => (string) $value,
                        'count' => $count,
                    ];
                }
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

        // In addition to the admin lock above and canEditHeader below — all
        // three must allow the save, and none of them replaces another.
        if ($excelFile->isBuyerLockedForUser($user)) {
            return redirect()
                ->route('uploaded-files.show', $excelFile->id)
                ->with('warning', 'This file belongs to another buyer. You can view it, but only its own buyer team can edit it.');
        }

        // PHP silently discards every POST variable past max_input_vars (1000 by
        // default) — no exception, nothing Laravel can see, just a truncated
        // $_POST. The sheet posts one variable per editable cell, so a large
        // save used to lose its tail and still be reported as successful.
        //
        // The form states up front how many cell inputs it sent. Fewer arrived
        // means the request was cut short, and the only safe thing to do is
        // refuse it rather than persist half an edit and call it a success.
        $declaredCellCount = $request->input('cells_count');

        if ($declaredCellCount !== null) {
            $receivedCellCount = collect((array) $request->input('cells', []))
                ->sum(fn ($rowCells) => count((array) $rowCells));

            if ($receivedCellCount < (int) $declaredCellCount) {
                return redirect()
                    ->route('uploaded-files.show', $excelFile->id)
                    ->with('error', 'Your changes were NOT saved. The browser sent more data than this server accepts in one request, so part of it was dropped. Save fewer rows at a time, or ask IT to raise max_input_vars in php.ini.');
            }
        }

        $roleIds = $this->getRoleIds();
        $calculatedHeaderKeys = $this->calculatedHeaderKeys();
        $batchId = (string) Str::uuid();
        $changedCells = [];

        // These Store cells are now auto-filled from the Material Stock Ledger and
        // are read-only. The workspace UI already renders them as read-only, so a
        // POST containing one means a bypassed frontend — reject it with a clear
        // message (the update loop below never persists non-editable cells anyway).
        $ledgerSyncedHeaderIds = $this->ledgerSyncedStoreHeaderIds();
        $attemptedSyncedWrite = false;
        if (! empty($ledgerSyncedHeaderIds) && $user->hasRole('store')) {
            foreach ((array) $request->input('cells', []) as $rowCells) {
                foreach ((array) $rowCells as $headerId => $value) {
                    if (isset($ledgerSyncedHeaderIds[(int) $headerId]) && trim((string) $value) !== '') {
                        $attemptedSyncedWrite = true;
                        break 2;
                    }
                }
            }
        }

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

        // Keep the standard tables (order_lines / bom_items) in step with the
        // edited rows. Never raises: the save has already succeeded.
        if (count($changedCells) > 0) {
            app(\App\Services\BomTableSync::class)->syncRows(
                $excelFile,
                collect($changedCells)->pluck('row_id')->unique()->values()->all()
            );
        }

        // Tell Supply Chain which POs the merchant's edit has made stale. A
        // failure here must never undo or hide a save that already succeeded.
        if (count($changedCells) > 0 && $user->hasRole('merchant')) {
            try {
                app(\App\Services\BookingPoChangeNotifier::class)->notify(
                    $excelFile,
                    collect($changedCells)->pluck('row_id')->unique()->values()->all(),
                    $user
                );
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('PO change notification failed: ' . $e->getMessage());
            }
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

        // Say what actually happened. "File updated successfully" on a save that
        // wrote nothing is what let the truncation bug above stay invisible for
        // so long: the user saw a green message and only found out on reload.
        $redirect = count($changedCells) > 0
            ? redirect($redirectUrl)->with('success', count($changedCells) . ' cell(s) updated successfully.')
            : redirect($redirectUrl)->with('info', 'No changes to save.');

        if ($lockedRowIds->isNotEmpty()) {
            $redirect->with('warning', $lockedRowIds->count() . ' locked PO row(s) were skipped. Unlock the PO in Admin PO Control or change the lock scope before editing.');
        }

        if ($attemptedSyncedWrite) {
            $redirect->with('warning', 'This field is calculated automatically from Store stock records (Material Stock Ledger) and cannot be edited here. Record the value as a Receiving or Issue in the Store module instead.');
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

        if ($excelFile->isBuyerLockedForUser($user)) {
            return redirect()
                ->route('uploaded-files.show', $excelFile->id)
                ->with('warning', 'This file belongs to another buyer. Only its own buyer team can add rows to it.');
        }

        DB::transaction(function () use ($excelFile, $user) {
            $nextRowNumber = ((int) $excelFile->rows()->max('row_number')) + 1;

            $row = ExcelRow::create([
                'excel_file_id' => $excelFile->id,
                'row_number' => $nextRowNumber,
                // The user asked for this row, so it is one they may take back.
                'origin' => ExcelRow::ORIGIN_ADDED,
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

    /**
     * Columns left BLANK on a duplicated row, by header_key.
     *
     * Empty on purpose. Nothing in the schema forces it: the worksheet is an
     * EAV store, so every business value lives in excel_cells.value — a
     * nullable string with no unique index and no validation behind it. The
     * only unique constraints in play are structural (excel_rows is unique on
     * excel_file_id + row_number, which the server assigns itself; excel_cells
     * is unique on row_id + header_id, one cell per column). So a duplicate
     * cannot violate anything, and which columns "should" be cleared is a
     * business decision rather than a technical one.
     *
     * The decision taken was to copy everything and let the merchant edit what
     * differs. This list is the single place to change that: add a header_key
     * here and that cell comes through blank and flagged for attention, with no
     * other code change. For example:
     *
     *     'initial_contract_number', 'sales_contract',
     *
     * @var list<string>
     */
    private const DUPLICATE_BLANK_HEADER_KEYS = [];

    /**
     * Cells marked "Needs update" on a freshly duplicated row, by header_key.
     *
     * These are NOT blanked — the row is a full copy. The marker is a visual
     * hint pointing at the handful of fields a new order usually changes, so a
     * merchant does not have to re-check 128 columns to find the three that
     * matter.
     *
     * HOW THIS LIST WAS CHOSEN, because no metadata could supply it. Every
     * candidate signal was checked and none discriminates: owner_role marks 35
     * columns as merchant's, value_mode only separates the 8 formula columns
     * from the rest, field_type is just text/number/date, and is_required is
     * YES on all 35. So the list is a business decision, not a derived one.
     *
     * What the data DOES say (file #4, 550 rows): initial_contract_number holds
     * 8 distinct values and contract_shipment_date 5, each shared across dozens
     * of rows. They are CONTRACT-level fields — they group rows into an order
     * rather than identify a line — which is exactly what a new order has to
     * change while line-level values (description, sap_code, bom_quantity,
     * colour) are correctly carried over.
     *
     * Deliberately NOT included yet:
     *   sales_contract  named by the business, but populated on 2 of 550 rows,
     *                   so the marker would usually sit on an empty cell.
     *   garments_po     also order-level, but nobody has confirmed merchants
     *                   change it per order.
     *
     * Add or remove a header_key here and the markers follow, with no other
     * code change. The surest way to settle the list: duplicated rows now carry
     * duplicated_from_row_id, so after a few weeks you can compare copies
     * against their sources and see which cells merchants actually edited.
     *
     * TWO TIERS, because "check this" and "this is wrong until you change it"
     * are not the same instruction and a single marker cannot say both:
     *
     *   always  a new order cannot reuse the old value. A copied contract
     *           number or PO number is not merely stale, it is wrong.
     *   likely  worth checking, but often correct as copied — a re-order in
     *           the same colour keeps its colours, and a vendor who has not
     *           re-quoted keeps its rate and payment term.
     *
     * BOTH DEPARTMENTS' COLUMNS SIT ON THIS ONE SHEET. excel_headers holds all
     * 133 columns for every role, distinguished only by owner_role_id, so a
     * duplicated row shows the merchant's markers and Supply Chain's together.
     * Each user can still only edit the columns their own role owns; the
     * markers are display-only and do not change that.
     *
     * Every key below was verified present, is_active, and value_mode=input —
     * a marker on a calculated cell would point at something the user cannot
     * edit. Note materials_ordered (Supply Chain, editable) is a DIFFERENT
     * column from materials_to_be_ordered (merchant, calculated); the two are
     * easy to confuse and only the former belongs here.
     *
     * @var array<string, list<string>>
     */
    private const DUPLICATE_NEEDS_UPDATE_HEADER_KEYS = [
        'always' => [
            // Merchant
            'initial_contract_number',
            'contract_shipment_date',
            // Supply Chain
            'materials_ordered',
            'material_po_number',
            'po_date',
            'material_pi_number',
        ],
        'likely' => [
            // Merchant — a re-order in the same colour keeps both of these.
            'gmts_color_name',
            'material_color',
            'bom_quantity',
            // Supply Chain — unchanged unless the vendor re-quoted.
            'pi_rate',
            'committed_ex_mill',
            'payment_term',
        ],
    ];

    /**
     * Header ids the worksheet should flag on a duplicated row, by tier.
     *
     * Resolved from header_key so the constant above stays readable and does
     * not break if ids differ between environments. A key that does not resolve
     * is silently skipped rather than fatal — an environment missing a column
     * should lose a hint, not the screen.
     *
     * @return array{always: list<int>, likely: list<int>}
     */
    private function duplicateNeedsUpdateHeaderIds(): array
    {
        $byKey = ExcelHeader::whereIn(
            'header_key',
            array_merge(
                self::DUPLICATE_NEEDS_UPDATE_HEADER_KEYS['always'],
                self::DUPLICATE_NEEDS_UPDATE_HEADER_KEYS['likely']
            )
        )->pluck('id', 'header_key');

        $resolve = fn (array $keys) => collect($keys)
            ->map(fn ($key) => $byKey->get($key))
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        return [
            'always' => $resolve(self::DUPLICATE_NEEDS_UPDATE_HEADER_KEYS['always']),
            'likely' => $resolve(self::DUPLICATE_NEEDS_UPDATE_HEADER_KEYS['likely']),
        ];
    }

    /**
     * The columns whose combined value defines "the same style".
     *
     * Buyer + Style + Season, and deliberately NOT GMTS Color: duplicating a
     * style is most often how a NEW COLOURWAY of it gets onto the sheet, so
     * matching on colour would exclude exactly the rows the merchant wants.
     *
     * All three must be filled before the bulk action is offered. Two of three
     * is how a style group silently grows to include another season's rows.
     *
     * @var list<string>
     */
    private const STYLE_MATCH_HEADER_KEYS = [
        'buyer_name',
        'style_name',
        'season_name',
    ];

    /**
     * The style-defining filter values the user currently has typed, if all
     * three are present.
     *
     * Read from the SAME filters[] input the ordinary column filter uses, so
     * the merchant does not learn a second control — they filter as always, and
     * once all three style columns are filled the bulk action becomes available.
     *
     * Returns [] unless every one of the three is non-empty. A partial style key
     * is precisely how a group quietly grows to include another season's rows,
     * so two-of-three offers nothing at all.
     *
     * @return array<string, string>  header_key => exact value
     */
    private function styleMatchFilterValues(array $columnFilters): array
    {
        $headerKeyById = ExcelHeader::whereIn('header_key', self::STYLE_MATCH_HEADER_KEYS)
            ->pluck('header_key', 'id');

        $values = [];

        foreach ($columnFilters as $filterKey => $filterValue) {
            $headerKey = $headerKeyById->get((int) $filterKey);

            if ($headerKey === null || ! is_string($filterValue)) {
                continue;
            }

            $trimmed = trim($filterValue);

            if ($trimmed !== '') {
                $values[$headerKey] = $trimmed;
            }
        }

        return count($values) === count(self::STYLE_MATCH_HEADER_KEYS) ? $values : [];
    }

    /**
     * Every row in this file whose style columns EXACTLY equal the given values.
     *
     * EXACT, not the substring match the ordinary filter uses. The filter is a
     * find-as-you-type convenience where over-matching costs a glance; this
     * feeds a bulk action over rows the merchant cannot all see, where
     * over-matching silently duplicates the wrong work. Typing "ABC" must not
     * sweep in "ABC-2".
     *
     * Uses `=` rather than whereLike deliberately. There is no wildcard to
     * escape and no LIKE/ILIKE divergence to reason about between MySQL and
     * PostgreSQL. It does mean the comparison is case-sensitive on PostgreSQL,
     * which is the safer direction for an action of this weight: it can only
     * ever match fewer rows than intended, never more.
     *
     * @param  array<string, string>  $values  header_key => exact value
     * @return list<int>
     */
    private function styleMatchRowIds(ExcelFile $excelFile, array $values): array
    {
        if ($values === []) {
            return [];
        }

        $headerIdByKey = ExcelHeader::whereIn('header_key', array_keys($values))
            ->pluck('id', 'header_key');

        $query = $excelFile->rows();

        foreach ($values as $headerKey => $value) {
            $headerId = $headerIdByKey->get($headerKey);

            // A style column missing from this environment would otherwise
            // widen the match by one whole dimension. Refuse instead.
            if ($headerId === null) {
                return [];
            }

            $query->whereHas('cells', function ($cellQuery) use ($headerId, $value) {
                $cellQuery->where('header_id', $headerId)->where('value', $value);
            });
        }

        return $query->orderBy('row_number')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Clear the "Duplicated" badge on one row.
     *
     * Clears the FLAG only — duplicated_from_row_id is left alone, so the row
     * stops being advertised as a duplicate but remains traceable to its
     * source. Chosen over auto-clearing on save because a merchant editing four
     * fields saves after the first, which is precisely when the marker is still
     * needed.
     */
    public function dismissDuplicateFlag(ExcelFile $excelFile, ExcelRow $row)
    {
        $user = auth()->user();

        // The SAME gate the duplicate actions use, rather than the role-only
        // check this method used to carry. Clearing a badge writes to the row,
        // so an admin-locked or another buyer's file must refuse it exactly as
        // it refuses the duplication that created the badge. It had the role
        // half and neither lock.
        if ($blocked = $this->duplicateGuard($excelFile, $user, 'change this row')) {
            return $blocked;
        }

        // Scoped to the file in the URL, so a row id from another workbook
        // cannot be cleared through this route.
        if ($row->excel_file_id !== $excelFile->id) {
            abort(404);
        }

        $row->forceFill(['duplicated_at' => null])->save();

        return redirect()
            ->route('uploaded-files.show', $excelFile->id)
            ->with('success', 'Duplicate marker cleared for row ' . $row->row_number . '.');
    }

    /**
     * Duplicate one or more existing rows, values included.
     *
     * Deliberately built on addRow()'s shape rather than beside it: identical
     * role gate, identical lock guards, one transaction, an ActivityLog entry
     * per new row, then a single recalculate + status refresh at the end. A
     * merchant pressing Duplicate should get exactly what Add New Row gives
     * them, only pre-filled — including saving immediately, which is what Add
     * New Row does today.
     *
     * WHERE THE COPIES LAND IS THE CALLER'S CHOICE, and the default is what
     * this action has always done:
     *
     *   below_source (default) — each copy directly after its own source, so a
     *     duplicate of row 41 becomes row 42 and stays with the work it belongs
     *     to. Everything below shifts down.
     *   end_of_file — every copy after the last row of the file, as one block.
     *     Nothing that already exists moves. This is for duplicating rows
     *     scattered through a long sheet in order to work on the copies: below
     *     each source, a dozen of them are a dozen things to find again.
     *
     * See the transaction body for how either is done without tripping the
     * unique index on (excel_file_id, row_number).
     *
     * Safe to renumber because row_number is ORDER ONLY: every real reference
     * — booking_pos.excel_row_id, activity_logs.row_id, excel_cells.row_id,
     * excel_file_change_logs.excel_row_id — points at the row's id, which never
     * changes here.
     */
    public function duplicateRows(Request $request, ExcelFile $excelFile)
    {
        $user = auth()->user();

        if ($blocked = $this->duplicateGuard($excelFile, $user)) {
            return $blocked;
        }

        $validated = $request->validate([
            'row_ids' => ['required', 'array', 'min:1'],
            'row_ids.*' => ['integer'],
            // Optional, and absent means what this action has always done. An
            // unrecognised value is refused rather than quietly falling back:
            // silently duplicating to the wrong end of a 499-row sheet is worse
            // than saying no.
            'placement' => ['nullable', 'string', 'in:below_source,end_of_file'],
        ]);

        $placement = $validated['placement'] ?? 'below_source';

        // Scoped to THIS file, so a hand-made request cannot copy rows out of
        // somebody else's workbook into this one. Ordered so a multi-row
        // duplicate lands in the same order it was displayed in.
        $sourceRows = ExcelRow::where('excel_file_id', $excelFile->id)
            ->whereIn('id', $validated['row_ids'])
            ->with('cells')
            ->orderBy('row_number')
            ->get();

        if ($sourceRows->isEmpty()) {
            return redirect()
                ->route('uploaded-files.show', $excelFile->id)
                ->with('warning', 'No matching row was found to duplicate.');
        }

        $this->performRowDuplication($excelFile, $user, $sourceRows, $placement);

        $count = $sourceRows->count();

        // Says WHERE the copies went, because the two placements put them in
        // very different parts of the sheet and the merchant is about to go
        // looking for them.
        $message = $placement === 'end_of_file'
            ? ($count === 1
                ? 'Row duplicated. The copy was added at the end of the file.'
                : $count . ' rows duplicated. The copies were added at the end of the file, in the order they appeared above.')
            : ($count === 1
                ? 'Row duplicated. The copy was inserted directly below its source row.'
                : $count . ' rows duplicated. Each copy was inserted directly below its own source row.');

        return redirect()
            ->route('uploaded-files.show', $excelFile->id)
            ->with('success', $message);
    }

    /**
     * Duplicate every row of one style group, as a block.
     *
     * THE ROW IDS ARE NOT ACCEPTED FROM THE CLIENT. The request carries the
     * style VALUES — buyer, style, season — and the server resolves the rows
     * itself, exactly as show() did when it drew the button. A tampered or
     * simply stale page therefore cannot name rows outside the group it is
     * looking at, and cannot act on a group that has since changed. This is the
     * whole reason the action takes filter values rather than a row_ids list
     * like duplicateRows() does.
     *
     * PLACEMENT DIFFERS FROM duplicateRows() ON PURPOSE. The copies land as one
     * contiguous block immediately after the LAST row of the matched group,
     * rather than each below its own source. Duplicating a whole style is an
     * action on the group, and interleaving copies through the originals would
     * leave the merchant reading alternate lines to tell old from new. Single
     * and manual multi-select duplication are unchanged.
     */
    public function duplicateMatchingRows(Request $request, ExcelFile $excelFile)
    {
        $user = auth()->user();

        if ($blocked = $this->duplicateGuard($excelFile, $user)) {
            return $blocked;
        }

        $columnFilters = collect((array) $request->input('filters', []))
            ->map(fn ($value) => is_string($value) ? trim($value) : $value)
            ->filter(fn ($value) => $value !== null && $value !== '')
            ->all();

        $styleMatchValues = $this->styleMatchFilterValues($columnFilters);

        if ($styleMatchValues === []) {
            return redirect()
                ->route('uploaded-files.show', $excelFile->id)
                ->with('warning', 'Set all three style filters — Buyer Name, Style Name and Season Name — before duplicating a whole style.');
        }

        $rowIds = $this->styleMatchRowIds($excelFile, $styleMatchValues);

        $sourceRows = ExcelRow::where('excel_file_id', $excelFile->id)
            ->whereIn('id', $rowIds)
            ->with('cells')
            ->orderBy('row_number')
            ->get();

        if ($sourceRows->isEmpty()) {
            return redirect()
                ->route('uploaded-files.show', $excelFile->id)
                ->with('warning', 'No row exactly matches that Buyer, Style and Season, so nothing was duplicated.');
        }

        $this->performRowDuplication($excelFile, $user, $sourceRows, 'block_after_last');

        $count = $sourceRows->count();
        $label = implode(' / ', $styleMatchValues);

        return redirect()
            ->route('uploaded-files.show', $excelFile->id)
            ->with('success', $count . ' ' . ($count === 1 ? 'row' : 'rows') . ' duplicated for ' . $label
                . '. The copies were inserted together, directly after the last row of that style.');
    }

    /**
     * Every header id the signed-in user may write, ignoring file locks.
     *
     * The authority behind the Add New Order dialog, stated once so the picker
     * that PRE-FILLS the form and the action that SAVES it cannot disagree
     * about which columns are in play. Calculated columns are excluded here as
     * they are everywhere else — recalculate would overwrite them moments
     * later anyway.
     *
     * File and buyer locks are not consulted: duplicateGuard() has already
     * refused the whole request by the time either caller reaches this.
     *
     * @return list<int>
     */
    private function editableHeaderIdsForCurrentUser(): array
    {
        $roleIds = $this->getRoleIds();
        $calculatedHeaderKeys = $this->calculatedHeaderKeys();

        return ExcelHeader::where('is_active', true)
            ->get()
            ->filter(fn ($header) => $this->canEditHeader($header, $roleIds))
            ->reject(fn ($header) => $this->isCalculatedHeader($header, $calculatedHeaderKeys))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * The columns the "Add New Order" dialog puts on its form, by tier.
     *
     * The SAME constant the grid markers read, so the dialog and the sheet
     * teach one rule about what a new order usually changes rather than two
     * lists that can drift apart.
     *
     * Narrowed to what THIS user may actually edit. The thirteen keys span
     * Merchandising and Supply Chain, so a merchant sees the merchant ones and
     * nothing else — the screen already holds that a user who cannot save a
     * column must not be shown an input for it. The columns left out are still
     * copied from the source; they are simply not editable here, exactly as
     * they are not editable in the grid.
     *
     * @param  list<int>  $editableHeaderIds
     * @return \Illuminate\Support\Collection<int, array{id:int,name:string,key:string,tier:string,field_type:string}>
     */
    private function addOrderFormHeaders(array $editableHeaderIds)
    {
        $tierByKey = [];

        foreach (self::DUPLICATE_NEEDS_UPDATE_HEADER_KEYS as $tier => $keys) {
            foreach ($keys as $key) {
                $tierByKey[$key] = $tier;
            }
        }

        $editable = array_flip($editableHeaderIds);

        return ExcelHeader::where('is_active', true)
            ->whereIn('header_key', array_keys($tierByKey))
            ->orderBy('position')
            ->get()
            ->filter(fn ($header) => isset($editable[$header->id]))
            ->map(fn ($header) => [
                'id' => (int) $header->id,
                'name' => $header->header_name,
                'key' => $header->header_key,
                'tier' => $tierByKey[$header->header_key],
                'field_type' => $header->field_type ?? 'text',
            ])
            // Amber before grey, so the form reads in the order the marker
            // colours already taught on the grid.
            ->sortBy(fn ($header) => $header['tier'] === 'always' ? 0 : 1)
            ->values();
    }

    /**
     * Rows a merchant can pick as the source for a new order, as JSON.
     *
     * Step one of the Add New Order dialog. Searching happens HERE rather than
     * over the rows the page happens to have rendered, because the grid is
     * paginated — a client-side search would quietly offer only the current
     * page and look like the older rows had been deleted.
     *
     * Substring matching, matching the column filter the merchant already
     * knows. This picks ONE row to copy and shows it back to them before
     * anything is written, so over-matching here costs a glance, not data —
     * unlike duplicateMatchingRows(), which acts on the whole match unseen and
     * is exact for that reason.
     */
    public function orderSourceOptions(Request $request, ExcelFile $excelFile)
    {
        $user = auth()->user();

        // Read-only lookup, but it feeds an action only these roles may take,
        // and it reports another team's rows — so it takes the same gate.
        if ($blocked = $this->duplicateGuard($excelFile, $user)) {
            return response()->json(['rows' => [], 'message' => 'This file is not available for editing.'], 403);
        }

        $headerIdByKey = ExcelHeader::whereIn('header_key', self::ADD_ORDER_SUMMARY_HEADER_KEYS)
            ->pluck('id', 'header_key');

        $terms = collect(self::STYLE_MATCH_HEADER_KEYS)
            ->mapWithKeys(fn ($key) => [$key => trim((string) $request->query($key, ''))])
            ->filter(fn ($value) => $value !== '');

        $freeText = trim((string) $request->query('q', ''));

        $rowsQuery = $excelFile->rows();

        foreach ($terms as $headerKey => $value) {
            $headerId = $headerIdByKey->get($headerKey);

            if ($headerId === null) {
                continue;
            }

            $rowsQuery->whereHas('cells', function ($cellQuery) use ($headerId, $value) {
                $cellQuery->where('header_id', $headerId)->whereLike('value', '%' . $value . '%');
            });
        }

        if ($freeText !== '') {
            $rowsQuery->where(function ($query) use ($freeText) {
                $query->whereLike('row_number', '%' . $freeText . '%')
                    ->orWhereHas('cells', function ($cellQuery) use ($freeText) {
                        $cellQuery->whereLike('value', '%' . $freeText . '%');
                    });
            });
        }

        $summaryHeaderIds = $headerIdByKey->values()->map(fn ($id) => (int) $id)->all();

        // The form's own columns come back with each candidate, so choosing a
        // row fills the edit step from data already in hand. A second request
        // per click would put a spinner between "that one" and seeing it.
        $formHeaderIds = $this->addOrderFormHeaders($this->editableHeaderIdsForCurrentUser())
            ->pluck('id')
            ->all();

        $wantedHeaderIds = array_values(array_unique(array_merge($summaryHeaderIds, $formHeaderIds)));

        // Capped. This is a picker, not a report — a merchant who gets 400 hits
        // needs to type more, not scroll further, and the footer says so.
        $limit = 25;

        $total = (clone $rowsQuery)->count();

        $rows = $rowsQuery
            ->with(['cells' => function ($query) use ($wantedHeaderIds) {
                $query->select('id', 'row_id', 'header_id', 'value')
                    ->whereIn('header_id', $wantedHeaderIds);
            }])
            ->orderBy('row_number')
            ->limit($limit)
            ->get();

        $keyById = $headerIdByKey->flip();
        $formHeaderIdSet = array_flip($formHeaderIds);

        return response()->json([
            'total' => $total,
            'shown' => $rows->count(),
            'limit' => $limit,
            'rows' => $rows->map(function ($row) use ($keyById, $formHeaderIdSet) {
                $values = [];
                $formValues = [];

                foreach ($row->cells as $cell) {
                    $key = $keyById->get($cell->header_id);

                    if ($key !== null) {
                        $values[$key] = $cell->value;
                    }

                    if (isset($formHeaderIdSet[(int) $cell->header_id])) {
                        $formValues[(int) $cell->header_id] = $cell->value;
                    }
                }

                return [
                    'id' => (int) $row->id,
                    'row_number' => (int) $row->row_number,
                    'values' => $values,
                    'form_values' => (object) $formValues,
                ];
            })->values(),
        ]);
    }

    /**
     * The columns the source picker shows for each candidate row.
     *
     * Enough to tell two orders apart at a glance and no more — the dialog is
     * for choosing a row, and a wide table inside a modal is how a merchant
     * loses the row they were looking at.
     *
     * @var list<string>
     */
    private const ADD_ORDER_SUMMARY_HEADER_KEYS = [
        'buyer_name',
        'style_name',
        'season_name',
        'gmts_color_name',
        'initial_contract_number',
    ];

    /**
     * Create one new order from a chosen source row, with the merchant's edits
     * already applied.
     *
     * Step three of the Add New Order dialog, and deliberately the SAME write
     * path as every other duplication: the same gate, the same lineage, the
     * same badge, the same below-source placement. The dialog changes where the
     * action is started from, not what it does — which is why this method is
     * short and performRowDuplication() is untouched apart from taking the
     * edits.
     *
     * The overrides are re-checked against what this user may edit. The form
     * only renders editable columns, but the form is not the control.
     */
    public function storeDuplicatedOrder(Request $request, ExcelFile $excelFile)
    {
        $user = auth()->user();

        if ($blocked = $this->duplicateGuard($excelFile, $user)) {
            return $blocked;
        }

        $validated = $request->validate([
            'source_row_id' => ['required', 'integer'],
            'overrides' => ['array'],
            // The dialog asks where the copy goes before it asks what to edit,
            // so the choice has to survive into this path too. Without it,
            // picking "end of file" and then "edit key fields first" would
            // quietly insert below the source instead.
            'placement' => ['nullable', 'string', 'in:below_source,end_of_file'],
        ]);

        $sourceRow = ExcelRow::where('excel_file_id', $excelFile->id)
            ->where('id', $validated['source_row_id'])
            ->with('cells')
            ->first();

        // Scoped to THIS file, so a hand-made request cannot pull a row out of
        // somebody else's workbook — the same rule duplicateRows() applies.
        if (! $sourceRow) {
            return redirect()
                ->route('uploaded-files.show', $excelFile->id)
                ->with('warning', 'That source row was not found in this file, so no order was created.');
        }

        $editableHeaderIds = collect($this->editableHeaderIdsForCurrentUser())->flip();

        $overrides = [];

        foreach ((array) ($validated['overrides'] ?? []) as $headerId => $value) {
            $headerId = (int) $headerId;

            if (! $editableHeaderIds->has($headerId)) {
                continue;
            }

            $value = is_string($value) ? trim($value) : $value;

            $overrides[$headerId] = ($value === '' || $value === null) ? null : $value;
        }

        $newRows = $this->performRowDuplication(
            $excelFile,
            $user,
            collect([$sourceRow]),
            $validated['placement'] ?? 'below_source',
            $overrides
        );

        $newRow = $newRows->first();

        return redirect()
            ->route('uploaded-files.show', $excelFile->id)
            ->with('success', 'New order added as row ' . ($newRow?->row_number ?? '')
                . ', copied from row ' . $sourceRow->row_number . '. Marked cells still need checking.');
    }

    /**
     * The role and lock gate shared by every duplication entry point.
     *
     * Returns a redirect to send back, or null to continue. Stated once so the
     * bulk action can never drift from the single-row one — a bulk action that
     * honoured a weaker gate would be the worst possible place for the two to
     * disagree.
     */
    /**
     * Delete one row, and close the gap it leaves.
     *
     * NARROW ON PURPOSE. This undoes a row somebody has just added and did not
     * want; it is not a general "remove any line". Whether a row qualifies is
     * ExcelRow::deletionBlockers(), which is the SAME method the worksheet will
     * ask before offering the button — one answer, so the screen and the server
     * cannot disagree about a destructive action.
     *
     * The refusal names the reason rather than returning a bare "cannot delete".
     * A merchant told only "no" on a row that carries a booking PO will try
     * again, or ask someone to force it; told that a PO was raised from it, they
     * stop.
     *
     * Renumbering reuses duplication's park-and-reassign for the same reason it
     * exists there: excel_rows is unique on (excel_file_id, row_number), and a
     * straight `row_number - 1` can collide mid-statement on the way past.
     */
    public function deleteRow(Request $request, ExcelFile $excelFile, ExcelRow $row)
    {
        $user = auth()->user();

        // Scoped to THIS file before anything else, so a row id from another
        // workbook is a 404 rather than something to reason about.
        if ((int) $row->excel_file_id !== (int) $excelFile->id) {
            abort(404);
        }

        $blockers = $row->deletionBlockers($user);

        if ($blockers !== []) {
            // 403 for the two that are genuinely permission, so a hand-made
            // request is refused the same way every other guarded action
            // refuses it; a redirect for the rest, which are states the screen
            // can explain.
            if (in_array('your role cannot delete rows', $blockers, true)) {
                abort(403, 'You are not allowed to delete a row.');
            }

            return redirect()
                ->route('uploaded-files.show', $excelFile->id)
                ->with('warning', 'Row '.$row->row_number.' cannot be deleted: '
                    .implode(', and ', $blockers).'.');
        }

        $deletedNumber = (int) $row->row_number;

        DB::transaction(function () use ($excelFile, $user, $row, $deletedNumber) {
            // Written BEFORE the delete: activity_logs.row_id is nullOnDelete,
            // so the entry survives the row and simply loses its anchor — which
            // is what an audit trail of a deletion should look like. Mirrors the
            // file_deleted entry destroy() writes.
            ActivityLog::create([
                'excel_file_id' => $excelFile->id,
                'row_id' => $row->id,
                'header_id' => null,
                'old_value' => 'Row '.$deletedNumber,
                'new_value' => null,
                'action' => 'row_deleted',
                'user_id' => $user->id,
            ]);

            // excel_cells cascades; nothing else points at this row, which is
            // precisely what deletionBlockers() has just established.
            $row->delete();

            $this->closeRowNumberGap($excelFile, $deletedNumber);

            $this->recalculateFile($excelFile, $user->id);
            $this->refreshFileStatus($excelFile);
        });

        return redirect()
            ->route('uploaded-files.show', $excelFile->id)
            ->with('success', 'Row '.$deletedNumber.' was deleted.');
    }

    /**
     * Delete the eligible rows of a selection, and say what happened to the
     * rest.
     *
     * A MIXED SELECTION IS NORMAL, because the checkboxes are the same ones
     * Duplicate Selected uses and a merchant ticking a run of rows has no
     * reason to know which of them a receiving references. Refusing the whole
     * batch over one such row would send them hunting for it; deleting
     * everything silently would be worse. So the eligible rows go, the rest are
     * named with their reasons, and the confirm the user already agreed to was
     * built from the same eligible count this recomputes.
     *
     * Eligibility is ExcelRow::deletionBlockersFor() — the same batched check
     * the screen drew its buttons from and single-row deleteRow() refuses on.
     * There is no second rule here.
     */
    public function deleteRows(Request $request, ExcelFile $excelFile)
    {
        $user = auth()->user();

        $validated = $request->validate([
            'row_ids' => ['required', 'array', 'min:1'],
            'row_ids.*' => ['integer'],
        ]);

        // Scoped to THIS file, so a hand-made request cannot reach into another
        // workbook — the same rule duplicateRows() applies.
        $rows = ExcelRow::where('excel_file_id', $excelFile->id)
            ->whereIn('id', $validated['row_ids'])
            ->orderBy('row_number')
            ->get();

        if ($rows->isEmpty()) {
            return redirect()
                ->route('uploaded-files.show', $excelFile->id)
                ->with('warning', 'No matching row was found to delete.');
        }

        $blockers = ExcelRow::deletionBlockersFor($rows, $user);

        // The role refusal is the one that is a permission rather than a state,
        // so it is answered the way every other guarded action answers it.
        if (in_array('your role cannot delete rows', $blockers[$rows->first()->id] ?? [], true)) {
            abort(403, 'You are not allowed to delete a row.');
        }

        $deletable = $rows->filter(fn (ExcelRow $row) => ($blockers[$row->id] ?? []) === []);
        $refused = $rows->reject(fn (ExcelRow $row) => ($blockers[$row->id] ?? []) === []);

        if ($deletable->isEmpty()) {
            return redirect()
                ->route('uploaded-files.show', $excelFile->id)
                ->with('warning', 'None of the '.$rows->count().' selected rows can be deleted: '
                    .$this->summariseRefusals($refused, $blockers).'.');
        }

        $deletedNumbers = $deletable->pluck('row_number')->map(fn ($n) => (int) $n)->sort()->values();

        DB::transaction(function () use ($excelFile, $user, $deletable, $deletedNumbers) {
            foreach ($deletable as $row) {
                ActivityLog::create([
                    'excel_file_id' => $excelFile->id,
                    'row_id' => $row->id,
                    'header_id' => null,
                    'old_value' => 'Row '.$row->row_number,
                    'new_value' => null,
                    'action' => 'row_deleted',
                    'user_id' => $user->id,
                ]);

                $row->delete();
            }

            // Once, from the lowest number removed: everything above it is
            // reassigned in order, so several holes close in a single pass
            // rather than one renumber per deleted row.
            $this->closeRowNumberGap($excelFile, $deletedNumbers->first());

            $this->recalculateFile($excelFile, $user->id);
            $this->refreshFileStatus($excelFile);
        });

        $message = $deletable->count() === 1
            ? 'Row '.$deletedNumbers->first().' was deleted.'
            : $deletable->count().' rows were deleted.';

        if ($refused->isNotEmpty()) {
            $message .= ' '.$refused->count().' of the '.$rows->count()
                .' selected could not be deleted: '.$this->summariseRefusals($refused, $blockers).'.';
        }

        return redirect()
            ->route('uploaded-files.show', $excelFile->id)
            ->with($refused->isEmpty() ? 'success' : 'warning', $message);
    }

    /**
     * "row 23 (it came from the uploaded file), row 41 (it is used by a booking
     * PO)" — named individually up to a point, then counted, because a listing
     * of thirty is not something anybody reads.
     *
     * @param  \Illuminate\Support\Collection<int, ExcelRow>  $refused
     * @param  array<int, list<string>>  $blockers
     */
    private function summariseRefusals($refused, array $blockers): string
    {
        $named = $refused->take(3)->map(function (ExcelRow $row) use ($blockers) {
            return 'row '.$row->row_number.' ('.implode(', and ', $blockers[$row->id] ?? []).')';
        })->all();

        if ($refused->count() > 3) {
            $named[] = 'and '.($refused->count() - 3).' more';
        }

        return implode('; ', $named);
    }

    /**
     * Pull every row after $deletedNumber up by one, without tripping the
     * unique index on (excel_file_id, row_number).
     *
     * Park, then reassign — the same two steps performRowDuplication() uses,
     * and for the same reason. A straight `SET row_number = row_number - 1`
     * can collide mid-statement (the row above has not moved yet), and the
     * workaround differs per engine since ORDER BY in UPDATE is MySQL-only.
     * Moving the affected rows into a disjoint number space first makes a
     * collision arithmetically impossible on MySQL, PostgreSQL and the SQLite
     * the tests run on alike.
     */
    private function closeRowNumberGap(ExcelFile $excelFile, int $deletedNumber): void
    {
        $tailRows = ExcelRow::where('excel_file_id', $excelFile->id)
            ->where('row_number', '>', $deletedNumber)
            ->orderBy('row_number')
            ->get(['id']);

        if ($tailRows->isEmpty()) {
            return;
        }

        $offset = (int) $excelFile->rows()->max('row_number') + $tailRows->count() + 1000;

        ExcelRow::where('excel_file_id', $excelFile->id)
            ->where('row_number', '>', $deletedNumber)
            ->update(['row_number' => DB::raw('row_number + '.$offset)]);

        $nextRowNumber = $deletedNumber;

        foreach ($tailRows as $tailRow) {
            ExcelRow::where('id', $tailRow->id)->update(['row_number' => $nextRowNumber]);
            $nextRowNumber++;
        }
    }

    private function duplicateGuard(ExcelFile $excelFile, $user, string $action = 'duplicate a row')
    {
        // The same gate addRow() applies, stated the same way on purpose.
        if (! $user->hasRole('admin') && ! $user->hasRole('merchant')) {
            abort(403, 'You are not allowed to ' . $action . '.');
        }

        if ($excelFile->isLockedForUser($user)) {
            return redirect()
                ->route('uploaded-files.show', $excelFile->id)
                ->with('warning', 'This file is locked by admin. Rows cannot be duplicated until it is unlocked for your user or role.');
        }

        if ($excelFile->isBuyerLockedForUser($user)) {
            return redirect()
                ->route('uploaded-files.show', $excelFile->id)
                ->with('warning', 'This file belongs to another buyer. Only its own buyer team can duplicate its rows.');
        }

        return null;
    }

    /**
     * Copy the given rows into the sheet, and renumber around them.
     *
     * $placement decides ONLY where the copies land:
     *
     *   below_source      each copy directly after its own source. The general
     *                     Duplicate and Duplicate Selected actions.
     *   block_after_last  all copies together, after the last source. The
     *                     "duplicate this whole style" action.
     *
     * Everything else — the cell copying, the lineage, the activity log, the
     * park-and-reassign that keeps row_number unique — is identical for both,
     * which is why they share this method rather than each owning a variant.
     *
     * $overrides is header_id => value, applied to the new row AFTER its cells
     * are copied and BEFORE the single recalculate at the end — which is the
     * whole reason it lives here rather than in the caller. Writing the edited
     * values afterwards would mean a second recalculate over the file, and
     * recalculating is the expensive part of this method. It is only meaningful
     * for a single source row, so it is ignored unless there is exactly one;
     * storeDuplicatedOrder() is the only caller that passes it.
     *
     * Returns the rows it created, in the order they were placed, so a caller
     * can redirect to one. Existing callers ignore the return.
     *
     * @param  array<int, string|null>  $overrides
     * @return \Illuminate\Support\Collection<int, ExcelRow>
     */
    private function performRowDuplication(ExcelFile $excelFile, $user, $sourceRows, string $placement, array $overrides = [])
    {
        $blankHeaderIds = ExcelHeader::whereIn('header_key', self::DUPLICATE_BLANK_HEADER_KEYS)
            ->pluck('id')
            ->all();

        // Ambiguous with more than one source: there would be no saying which
        // new row the edited values belonged to. Silently dropping them is the
        // safe direction — a copied value is always recoverable in the grid.
        if ($sourceRows->count() !== 1) {
            $overrides = [];
        }

        return DB::transaction(function () use ($excelFile, $user, $sourceRows, $blankHeaderIds, $placement, $overrides) {
            // Every ACTIVE header, so a duplicate has the same cell coverage as
            // a fresh row. A source row missing a cell for a newly added column
            // still gets that column here, blank.
            $headers = ExcelHeader::where('is_active', true)
                ->orderBy('position')
                ->get();

            $sourceIdSet = $sourceRows->keyBy('id');

            /*
             * THE TARGET ORDER, worked out in PHP before a single row moves.
             *
             * Building the whole final sequence in one pass is what makes this
             * safe. Shifting per insert instead would compound offsets — the
             * second insert would have to account for the first having moved
             * everything — which is exactly where this kind of code goes wrong.
             *
             * $anchor is the last row_number that does NOT move. Everything
             * after it is rewritten from the sequence below. The two placements
             * differ only in where that anchor sits and how the sequence is
             * ordered; the park-and-reassign that follows is identical.
             */
            $sequence = [];

            if ($placement === 'end_of_file') {
                /*
                 * Every copy goes after the LAST ROW OF THE FILE, in the order
                 * the sources were given.
                 *
                 * This is the only placement that moves nothing that already
                 * exists. The anchor is the highest row number there is, so the
                 * park update below matches no rows and there is no tail to
                 * rewrite — the copies simply take the numbers after it. That
                 * is the point of it: a merchant duplicating a dozen scattered
                 * rows to work on them gets one block at the bottom, rather
                 * than a dozen copies interleaved through the sheet they then
                 * have to hunt for.
                 */
                $anchor = (int) $excelFile->rows()->max('row_number');

                foreach ($sourceRows as $sourceRow) {
                    $sequence[] = ['new', $sourceRow->id];
                }
            } elseif ($placement === 'block_after_last') {
                // The whole group stays put and the copies follow it in one
                // run, so a style reads as originals-then-copies rather than
                // alternating lines.
                $anchor = (int) $sourceRows->max('row_number');

                foreach ($sourceRows as $sourceRow) {
                    $sequence[] = ['new', $sourceRow->id];
                }

                $tailRows = ExcelRow::where('excel_file_id', $excelFile->id)
                    ->where('row_number', '>', $anchor)
                    ->orderBy('row_number')
                    ->get(['id', 'row_number']);

                foreach ($tailRows as $existing) {
                    $sequence[] = ['existing', $existing->id];
                }
            } else {
                // Rows at or before $anchor never move, so the sequence starts
                // just after the earliest source.
                $anchor = (int) $sourceRows->min('row_number');

                $laterRows = ExcelRow::where('excel_file_id', $excelFile->id)
                    ->where('row_number', '>=', $anchor)
                    ->orderBy('row_number')
                    ->get(['id', 'row_number']);

                foreach ($laterRows as $existing) {
                    // The row sitting at $anchor keeps its number; only its copy
                    // and everything after it are renumbered.
                    if ((int) $existing->row_number !== $anchor) {
                        $sequence[] = ['existing', $existing->id];
                    }

                    if ($sourceIdSet->has($existing->id)) {
                        $sequence[] = ['new', $existing->id];
                    }
                }
            }

            /*
             * PARK, then reassign. excel_rows is unique on
             * (excel_file_id, row_number), and a straight
             * `SET row_number = row_number + 1` can trip that index mid-statement
             * — and the workaround differs per engine, since ORDER BY in UPDATE
             * is MySQL-only. Moving the affected rows into a disjoint number
             * space first makes a collision arithmetically impossible on MySQL,
             * PostgreSQL and the SQLite the tests run on alike.
             *
             * The offset clears the highest number any row could be assigned:
             * max + the number of rows being placed + 1000 of headroom.
             */
            $maxRowNumber = (int) $excelFile->rows()->max('row_number');
            $offset = $maxRowNumber + count($sequence) + 1000;

            ExcelRow::where('excel_file_id', $excelFile->id)
                ->where('row_number', '>', $anchor)
                ->update(['row_number' => DB::raw('row_number + ' . $offset)]);

            $nextRowNumber = $anchor + 1;
            $createdRows = collect();

            foreach ($sequence as [$kind, $refId]) {
                if ($kind === 'existing') {
                    ExcelRow::where('id', $refId)->update(['row_number' => $nextRowNumber]);
                    $nextRowNumber++;

                    continue;
                }

                $sourceRow = $sourceIdSet->get($refId);
                $sourceValues = $sourceRow->cells->keyBy('header_id');

                $newRow = ExcelRow::create([
                    'excel_file_id' => $excelFile->id,
                    'row_number' => $nextRowNumber,
                    // Lineage is permanent; the timestamp is the badge state and
                    // is what "Dismiss" clears. See the migration for why both.
                    'duplicated_from_row_id' => $sourceRow->id,
                    'duplicated_at' => now(),
                    // A copy the user asked for, so it is one they may take back
                    // — until they start working on it.
                    'origin' => ExcelRow::ORIGIN_DUPLICATED,
                ]);

                foreach ($headers as $header) {
                    $value = in_array($header->id, $blankHeaderIds, true)
                        ? null
                        : ($sourceValues->get($header->id)->value ?? null);

                    // The merchant's edit wins over the copied value. Only for
                    // headers actually present here, so an override naming a
                    // column this file does not have is simply not written.
                    if (array_key_exists($header->id, $overrides)) {
                        $value = $overrides[$header->id];
                    }

                    ExcelCell::create([
                        'row_id' => $newRow->id,
                        'header_id' => $header->id,
                        'value' => $value,
                        'updated_by' => $user->id,
                    ]);
                }

                $createdRows->push($newRow);

                ActivityLog::create([
                    'excel_file_id' => $excelFile->id,
                    'row_id' => $newRow->id,
                    'header_id' => null,
                    'old_value' => null,
                    // The source's ORIGINAL number, captured before the shift.
                    'new_value' => 'Row duplicated from row ' . $sourceRow->row_number,
                    'action' => 'row_duplicated',
                    'user_id' => $user->id,
                ]);

                $nextRowNumber++;
            }

            // Once for the whole batch, not per row — these are expensive and
            // the result is the same.
            $this->recalculateFile($excelFile, $user->id);
            $this->refreshFileStatus($excelFile);

            return $createdRows;
        });
    }

    /**
     * Download the file exactly as it was uploaded.
     *
     * The original has always been kept on disk but there was no way to get it
     * back — useful when someone needs to check what was actually submitted
     * against what the workspace now holds.
     */
    /**
     * Download the file exactly as it was uploaded.
     *
     * THE BUYER CHECK IS NOT OPTIONAL HERE. This method used to test the admin
     * lock alone, on the reasoning that "if you can open the file you can take
     * a copy of what it came from" — which quietly made it the widest hole in
     * the module: a merchant confined to one buyer could pull another buyer's
     * COMPLETE original workbook off the server as a file. Viewing a sheet in a
     * browser and exporting the whole book are not the same act, and the buyer
     * boundary has to hold for the second one too.
     */
    public function download(ExcelFile $excelFile)
    {
        $user = auth()->user();

        if ($excelFile->isLockedForUser($user) && ! $user->hasRole('admin')) {
            abort(403, 'This file is locked for you.');
        }

        if ($excelFile->isBuyerLockedForUser($user)) {
            abort(403, 'This file belongs to another buyer. Only its own buyer team can download it.');
        }

        if (empty($excelFile->file_path) || ! Storage::exists($excelFile->file_path)) {
            return back()->with('warning', 'The original file is no longer stored on the server.');
        }

        return Storage::download(
            $excelFile->file_path,
            $excelFile->original_file_name ?: $excelFile->file_name
        );
    }

    /**
     * Delete the file, its rows and its stored original.
     *
     * THE LOCK CHECKS BELOW ARE THE POINT. This method used to gate on ROLE
     * ALONE, which meant any merchant could delete any file — including one
     * belonging to a buyer they do not hold, and one an admin had explicitly
     * locked. The screen showed the read-only "belongs to another buyer"
     * banner and an active red Delete button on the same page, and the button
     * worked. Every other mutating action on this file already refused both
     * cases; deletion, the one irreversible action, refused neither.
     *
     * 403 rather than the redirect-with-warning the editing actions use. A
     * warning is right where the user was legitimately trying to work and
     * needs to be told why they cannot; there is no longer any UI path to this
     * route for a locked file, so reaching it means the request did not come
     * from the screen.
     */
    public function destroy(ExcelFile $excelFile)
    {
        $user = auth()->user();

        if (! $user->hasRole('admin') && ! $user->hasRole('merchant')) {
            abort(403, 'You are not allowed to delete this file.');
        }

        if ($excelFile->isLockedForUser($user)) {
            abort(403, 'This file is locked by admin and cannot be deleted.');
        }

        if ($excelFile->isBuyerLockedForUser($user)) {
            abort(403, 'This file belongs to another buyer. Only its own buyer team can delete it.');
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

    /**
     * Tell the other departments a merchant edited a file.
     *
     * SCOPED for the same reason the upload notification is: the message names
     * the file, and the navbar prints it in full, so an unfiltered fan-out hands
     * a confined user the file name of a buyer they have no access to. The
     * companion is ExcelUploadController::notifyOtherRoles() — the two are
     * separate fan-outs over the same recipient list and must not drift.
     *
     * isBuyerLockedForUser() is the same predicate the file screen applies, so a
     * recipient is dropped on exactly the rule that would have withheld the file
     * itself, and an untagged file still notifies everybody.
     */
    private function notifyOtherRolesAboutFileUpdate(ExcelFile $excelFile, array $changedCells, string $batchId, $actor): void
    {
        $users = User::where('id', '!=', $actor->id)
            ->whereDoesntHave('roles', function ($query) {
                $query->where('name', 'merchant');
            })
            ->get()
            ->reject(fn (User $user) => $excelFile->isBuyerLockedForUser($user));

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
     * Latest Payment Request Approval (PRA) dates per PI No. for this file.
     *
     * A booking/PI spans many worksheet rows (one per style/spec). Because the
     * PRA's Payment Require Date and submission date apply to the whole PI, the
     * dates are keyed by PI number (normalised) so every row sharing that PI No.
     * receives them. Preview PRAs are ignored and the most recently created PRA
     * wins when a PI appears in more than one PRA.
     *
     * @return array<string, array{submission: ?Carbon, reqd: ?Carbon}>
     */
    private function praDatesByPiNumber(ExcelFile $excelFile): array
    {
        $items = \App\Models\PaymentRequestItem::query()
            ->where('excel_file_id', $excelFile->id)
            ->whereNotNull('pi_number')
            ->whereHas('paymentRequest', fn ($q) => $q->where('status', '!=', 'preview'))
            ->with(['paymentRequest:id,created_at,status'])
            ->get(['id', 'pi_number', 'payment_required_date', 'payment_request_id']);

        $byPi = [];

        foreach ($items as $item) {
            $pr = $item->paymentRequest;
            if (! $pr) {
                continue;
            }

            $piKey = $this->normalizePiNumber($item->pi_number);
            if ($piKey === '') {
                continue;
            }

            $existing = $byPi[$piKey] ?? null;

            // Latest PRA wins (by created_at, tie-break on id).
            if ($existing) {
                $isNewer = $pr->created_at && $existing['created_at']
                    && ($pr->created_at->gt($existing['created_at'])
                        || ($pr->created_at->eq($existing['created_at']) && $pr->id > $existing['pr_id']));
                if (! $isNewer) {
                    continue;
                }
            }

            $byPi[$piKey] = [
                'created_at' => $pr->created_at,
                'pr_id' => $pr->id,
                'submission' => $pr->created_at,
                'reqd' => $item->payment_required_date, // Carbon (date cast) or null
            ];
        }

        return $byPi;
    }

    /**
     * Normalise a PI number for matching (trim + lowercase). Returns '' when blank.
     */
    private function normalizePiNumber($value): string
    {
        return strtolower(trim((string) $value));
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

        // Rows that have a Material Stock Ledger entry yield their overlapping
        // Store cells to the ledger (single source of truth): recalc stops
        // writing its Excel-formula value for those cells so the auto-synced
        // ledger figure survives. Disabled -> legacy Excel-formula behaviour.
        $ledgerSyncEnabled = (bool) config('stock.sync_workspace_cells', true);
        $ledgerRowIds = $ledgerSyncEnabled
            ? MaterialStockLedger::whereIn('excel_row_id', $rows->pluck('id')->all())
                ->pluck('excel_row_id')
                ->map(fn ($id) => (int) $id)
                ->flip()
            : collect();

        $praByPi = $this->praDatesByPiNumber($excelFile);
        $previousFormulaKey = null;

        foreach ($rows as $row) {
            if ($exceptRowIds->has((int) $row->id)) {
                continue;
            }

            // When true, the ledger owns Liability / Dead Stock / Liability Value
            // / Invoiced Amount (Store) for this row — do not overwrite them here.
            $yieldsToLedger = $ledgerSyncEnabled && $ledgerRowIds->has((int) $row->id);

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
            // PRA's creation date. Matching is by PI No., so every row sharing the
            // same PI No. as the PRA is updated together. Rows whose PI No. has no
            // PRA keep the formula above.
            $pra = $praByPi[$this->normalizePiNumber($materialPiNumber)] ?? null;
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
            if (! $yieldsToLedger) {
                $this->setCalcAny($row->id, $cellMap, $headerIdByKey, ['invoiced_amount_store'], $this->fmtNum($invoicedAmountStore), $userId);
            }
            $this->setCalcAny($row->id, $cellMap, $headerIdByKey, ['gmnts_po_number', 'gmts_po_number'], $gmntsPoNumber, $userId);
            $this->setCalcAny($row->id, $cellMap, $headerIdByKey, ['gmts_order_qty', 'gmts_order_quantity', 'gmts_order_qty_store'], $this->fmtNum($gmtsOrderQty), $userId);
            $this->setCalcAny($row->id, $cellMap, $headerIdByKey, ['production_cons_incl_wastage', 'production_consumption_including_wastage', 'prod_yy_wastage'], $this->fmtNum($productionConsInclWastage), $userId);
            $this->setCalcAny($row->id, $cellMap, $headerIdByKey, ['requirement'], $this->fmtNum($requirement), $userId);
            $this->setCalcAny($row->id, $cellMap, $headerIdByKey, ['excess_shortage', 'excess_shortage_qty'], $this->fmtNum($excessShortage), $userId);
            if (! $yieldsToLedger) {
                $this->setCalcAny($row->id, $cellMap, $headerIdByKey, ['liability', 'liability_qty'], $this->fmtNum($liabilityQty), $userId);
            }
            $this->setCalcAny($row->id, $cellMap, $headerIdByKey, ['buyer_liability'], $this->fmtNum($buyerLiability), $userId);
            $this->setCalcAny($row->id, $cellMap, $headerIdByKey, ['buyer_liability_value'], $this->fmtNum($buyerLiabilityValue), $userId);
            $this->setCalcAny($row->id, $cellMap, $headerIdByKey, ['liability_based_on_receiving'], $this->fmtNum($liabilityBasedOnReceiving), $userId);
            $this->setCalcAny($row->id, $cellMap, $headerIdByKey, ['short_excess_issued'], $this->fmtNum($shortExcessIssued), $userId);
            $this->setCalcAny($row->id, $cellMap, $headerIdByKey, ['return_back_to_stores'], $this->fmtNum($returnBackToStores), $userId);
            if (! $yieldsToLedger) {
                $this->setCalcAny($row->id, $cellMap, $headerIdByKey, ['dead_stock_quantity'], $this->fmtNum($deadStockQuantity), $userId);
            }
            $this->setCalcAny($row->id, $cellMap, $headerIdByKey, ['material_cost_value', 'material_issue_value'], $this->fmtNum($materialCostValue), $userId);
            $this->setCalcAny($row->id, $cellMap, $headerIdByKey, ['dead_stock_value'], $this->fmtNum($deadStockValue), $userId);
            if (! $yieldsToLedger) {
                $this->setCalcAny($row->id, $cellMap, $headerIdByKey, ['liability_stock_value'], $this->fmtNum($liabilityStockValue), $userId);
            }
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

    private ?HeaderAliasResolver $aliasResolver = null;

    /**
     * Shared header alias/normalisation service (single source of the alias map,
     * used by this controller and the ledger→cell sync).
     */
    private function aliasResolver(): HeaderAliasResolver
    {
        return $this->aliasResolver ??= app(HeaderAliasResolver::class);
    }

    private function normalizeHeaderKey($value): ?string
    {
        return $this->aliasResolver()->normalize($value);
    }

    private function headerAliases(): array
    {
        return $this->aliasResolver()->aliases();
    }

    /**
     * Store-owned header ids that the Material Stock Ledger now auto-fills. These
     * are read-only for Store; a manual write attempt is reported clearly. Empty
     * when the sync is disabled via config.
     *
     * @return array<int, true>
     */
    private function ledgerSyncedStoreHeaderIds(): array
    {
        if (! (bool) config('stock.sync_workspace_cells', true)) {
            return [];
        }

        $storeRoleId = Role::where('name', 'store')->value('id');
        $resolver = $this->aliasResolver();

        $ids = [];
        foreach ((array) config('stock.ledger_owned_store_header_keys', []) as $canonical) {
            foreach ($resolver->headerIdsForCanonical($canonical, $storeRoleId ? (int) $storeRoleId : null) as $id) {
                $ids[(int) $id] = true;
            }
        }

        return $ids;
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
                // Padding the sheet draws so it never opens on a stub. Nobody
                // asked for these, and deleting one would achieve nothing —
                // the next page load puts it back — so they are not user-added.
                'origin' => ExcelRow::ORIGIN_IMPORTED,
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

    /**
     * Role ids whose workspace columns this user may work with.
     *
     * Column ownership is recorded on the header as a ROLE id, so a user only
     * reaches a column by holding the role that owns it. That leaves no way to
     * give one person the Store columns without giving them the whole Store
     * role — which is the gap the narrow Store roles exposed: a user on
     * Store — General Stock owns no columns at all.
     *
     * PERMISSION_STORE_COLUMNS closes it. Granted directly to a user, it adds
     * the Store role's id here and nothing else: they work the Store columns
     * without being a Store user anywhere else in the system. It is deliberately
     * granted to no role, so it can only ever arrive as a per-user decision.
     */
    private function getRoleIds()
    {
        $user = auth()->user();
        $names = $user->getRoleNames()->all();

        if ($user->can(self::PERMISSION_STORE_COLUMNS) && ! in_array('store', $names, true)) {
            $names[] = 'store';
        }

        return Role::whereIn('name', $names)->pluck('id');
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

        // keyBy() rather than firstWhere() inside the loop. firstWhere() rescans
        // the row's whole cell collection for every target header, which on a
        // real file (499 rows x ~100 target headers x ~128 cells) is millions of
        // PHP comparisons — it measured 23 seconds on one save, with only five
        // queries. Hashing each row's cells once makes the lookup O(1). Same
        // counts, same resulting status.
        $targetHeaderIds = $targetHeaderIds->all();

        foreach ($rows as $row) {
            $cellsByHeader = $row->cells->keyBy('header_id');

            foreach ($targetHeaderIds as $headerId) {
                $total++;

                $value = trim((string) ($cellsByHeader->get($headerId)?->value ?? ''));

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
