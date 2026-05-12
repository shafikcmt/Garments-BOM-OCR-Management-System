<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\SupplyChain\BookingController as SupplyChainBookingController;
use App\Models\BookingPo;
use App\Models\ExcelCell;
use App\Models\ExcelHeader;
use App\Models\ExcelRow;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role as SpatieRole;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class PoGenerateControlController extends SupplyChainBookingController
{
    private ?Collection $supplyChainPoUsersCache = null;

    protected function bookingRoutePrefix(): string
    {
        return 'admin.po-generate-control';
    }

    protected function canControlPo(): bool
    {
        return true;
    }

    public function index(Request $request)
    {
        $routeName = optional($request->route())->getName();
        $activePoPage = match ($routeName) {
            'admin.po-generate-control.pending' => 'pending',
            'admin.po-generate-control.generated' => 'generated',
            default => in_array($request->input('po_page'), ['pending', 'generated'], true)
                ? $request->input('po_page')
                : 'generated',
        };

        $allPos = BookingPo::query()
            ->with(['excelFile', 'excelRow.cells.header', 'generatedBy', 'completedBy'])
            ->latest('id')
            ->get()
            ->map(function (BookingPo $bookingPo) {
                return $this->syncBookingPoSourceControl($bookingPo);
            });

        $pendingRows = $this->pendingRowsForControl($request, 8);
        $stats = $this->poControlStats($allPos);
        $stats['pending'] = $this->pendingRowsForControl($request, 1, false)->total();
        $filtered = $this->applyControlFilters($allPos, $request);
        $bookingPos = $this->paginateControlCollection($filtered, $request, 20);
        $poControlUsers = $this->poControlUsers();
        $poLockUsers = $this->poLockUsers();
        $poControlRoles = $this->poControlRoles();
        $filterOptions = $this->poFilterOptions($allPos);

        return view('admin.po-generate-control.index', compact('bookingPos', 'pendingRows', 'stats', 'poControlUsers', 'poLockUsers', 'poControlRoles', 'filterOptions', 'activePoPage'));
    }

    public function pending(Request $request)
    {
        return $this->index($request);
    }

    public function generated(Request $request)
    {
        return $this->index($request);
    }

    public function show(BookingPo $bookingPo)
    {
        $this->authorizeBookingPo($bookingPo);

        $bookingPo->loadMissing(['excelFile', 'excelRow.cells.header', 'generatedBy', 'completedBy']);
        $bookingPo = $this->refreshBookingPoQtyFromSource($bookingPo);
        $bookingPo = $this->syncBookingPoSourceControl($bookingPo);

        $bookingData = $this->bookingData($bookingPo);
        $instructionOptions = $this->bookingInstructionOptions();
        $deliveryDestinationOptions = $this->deliveryDestinationOptions();
        $bookingRoutePrefix = $this->bookingRoutePrefix();
        $canControlPo = $this->canControlPo();
        $poControlUsers = $this->poControlUsers();
        $poLockUsers = $this->poLockUsers();
        $poControlRoles = $this->poControlRoles();
        $poAdminControl = $this->poAdminControl($bookingPo);

        return view('admin.po-generate-control.show', compact(
            'bookingPo',
            'bookingData',
            'instructionOptions',
            'deliveryDestinationOptions',
            'bookingRoutePrefix',
            'canControlPo',
            'poControlUsers',
            'poLockUsers',
            'poControlRoles',
            'poAdminControl'
        ));
    }

    public function editPreview(Request $request, BookingPo $bookingPo)
    {
        $this->authorizeBookingPo($bookingPo);
        abort_if(! $this->canControlPo(), 403);

        if ($this->isPoLocked($bookingPo)) {
            return response()->json([
                'success' => false,
                'message' => 'This PO is locked. Unlock it from Admin Control first, then edit.',
            ], 423);
        }

        $bookingPo->loadMissing(['excelFile', 'excelRow.cells.header', 'generatedBy', 'completedBy']);
        $bookingPo = $this->refreshBookingPoQtyFromSource($bookingPo);
        $bookingPo = $this->syncBookingPoSourceControl($bookingPo);
        $bookingData = $this->bookingData($bookingPo);

        return response()->json([
            'success' => true,
            'message' => 'PO edit panel ready. Update the fields, then click Save PO Edit.',
            'preview_html' => view('supply-chain.bookings.partials.preview', [
                'bookingPo' => $bookingPo,
                'bookingData' => $bookingData,
                'previewMode' => true,
                'regenerateMode' => false,
                'adminEditMode' => true,
                'editPanelOpen' => true,
                'generateUrl' => $this->bookingRoute('update', $bookingPo),
                'instructionOptions' => $this->bookingInstructionOptions(),
                'deliveryDestinationOptions' => $this->deliveryDestinationOptions(),
                'bookingRoutePrefix' => $this->bookingRoutePrefix(),
                'canControlPo' => $this->canControlPo(),
            ])->render(),
        ]);
    }

    public function update(Request $request, BookingPo $bookingPo)
    {
        $this->authorizeBookingPo($bookingPo);
        abort_if(! $this->canControlPo(), 403);

        if ($this->isPoLocked($bookingPo)) {
            if ($this->isAjaxRequest($request)) {
                return response()->json([
                    'success' => false,
                    'message' => 'This PO is locked. Unlock it from Admin Control first, then edit.',
                ], 423);
            }

            return back()->with('error', 'This PO is locked. Unlock it from Admin Control first, then edit.');
        }

        $validated = $this->validateBookingEditRequest($request);
        $beforeAudit = $this->bookingAuditSnapshot($bookingPo->booking_data ?: []);
        $bookingPo = $this->applyBookingEditDataToPo($bookingPo, $validated, $request);
        $bookingPo = $this->appendBookingGenerationHistory($bookingPo, 'admin_updated', $beforeAudit);
        $this->appendPoControlHistory($bookingPo, 'edited', ['note' => 'PO edited from admin control panel.']);
        $bookingData = $this->bookingData($bookingPo);
        $message = 'PO edited successfully: ' . $bookingPo->po_no . '.';

        if ($this->isAjaxRequest($request)) {
            return response()->json([
                'success' => true,
                'message' => $message,
                'preview_html' => view('supply-chain.bookings.partials.preview', [
                    'bookingPo' => $bookingPo,
                    'bookingData' => $bookingData,
                    'previewMode' => false,
                    'regenerateMode' => false,
                    'adminEditMode' => false,
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

    public function regeneratePreview(Request $request, BookingPo $bookingPo)
    {
        if ($this->isPoLocked($bookingPo)) {
            return response()->json([
                'success' => false,
                'message' => 'This PO is locked. Unlock it from Admin Control first, then re-generate.',
            ], 423);
        }

        return parent::regeneratePreview($request, $bookingPo);
    }

    public function regenerate(Request $request, BookingPo $bookingPo)
    {
        if ($this->isPoLocked($bookingPo)) {
            if ($this->isAjaxRequest($request)) {
                return response()->json([
                    'success' => false,
                    'message' => 'This PO is locked. Unlock it from Admin Control first, then re-generate.',
                ], 423);
            }

            return back()->with('error', 'This PO is locked. Unlock it from Admin Control first, then re-generate.');
        }

        return parent::regenerate($request, $bookingPo);
    }

    public function saveAccess(Request $request, BookingPo $bookingPo)
    {
        $this->authorizeBookingPo($bookingPo);

        $validated = $request->validate([
            'locked' => ['nullable', 'boolean'],
            'lock_reason' => ['nullable', 'string', 'max:500'],
            'lock_scope' => ['required', 'in:all_users,specific_users,specific_roles'],
            'locked_user_ids' => ['nullable', 'array'],
            'locked_user_ids.*' => ['integer', 'exists:users,id'],
            'locked_role_ids' => ['nullable', 'array'],
            'locked_role_ids.*' => ['integer', 'exists:roles,id'],
            'edit_permission' => ['required', 'in:admin_only,authorized_users,all_users'],
            'authorized_user_ids' => ['nullable', 'array'],
            'authorized_user_ids.*' => ['integer', 'exists:users,id'],
            'control_note' => ['nullable', 'string', 'max:700'],
        ]);

        $control = $this->poAdminControl($bookingPo);
        $wasLocked = (bool) ($control['locked'] ?? false);
        $isLocked = (bool) ($validated['locked'] ?? false);
        $authorizedIds = collect($validated['authorized_user_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();
        $authorizedUsers = User::query()
            ->whereIn('id', $authorizedIds->all())
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        $lockedUserIds = collect($validated['locked_user_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();
        $lockedUsers = User::query()
            ->whereIn('id', $lockedUserIds->all())
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        $lockedRoleIds = collect($validated['locked_role_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();
        $lockedRoles = SpatieRole::query()
            ->whereIn('id', $lockedRoleIds->all())
            ->orderBy('name')
            ->get(['id', 'name']);

        $control['locked'] = $isLocked;
        $control['lock_reason'] = trim((string) ($validated['lock_reason'] ?? ''));
        $control['lock_scope'] = $validated['lock_scope'] ?? 'all_users';
        $control['locked_user_ids'] = $lockedUsers->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        $control['locked_users_snapshot'] = $lockedUsers->map(fn (User $user) => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
        ])->values()->all();
        $control['locked_role_ids'] = $lockedRoles->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        $control['locked_roles_snapshot'] = $lockedRoles->map(fn (SpatieRole $role) => [
            'id' => $role->id,
            'name' => $role->name,
        ])->values()->all();
        if ($isLocked && ! $wasLocked) {
            $control['locked_by'] = auth()->id();
            $control['locked_by_name'] = optional(auth()->user())->name;
            $control['locked_at'] = now()->format('Y-m-d H:i:s');
        }
        if (! $isLocked) {
            $control['locked_by'] = null;
            $control['locked_by_name'] = null;
            $control['locked_at'] = null;
        }

        $control['edit_permission'] = $validated['edit_permission'];
        $control['authorized_user_ids'] = $authorizedUsers->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        $control['authorized_users_snapshot'] = $authorizedUsers->map(fn (User $user) => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
        ])->values()->all();
        $control['generate_permission'] = 'supply_chain_only';
        $control['control_note'] = trim((string) ($validated['control_note'] ?? ''));
        $control['updated_by'] = auth()->id();
        $control['updated_by_name'] = optional(auth()->user())->name;
        $control['updated_at'] = now()->format('Y-m-d H:i:s');

        $bookingPo = $this->savePoAdminControl($bookingPo, $control);
        $this->appendPoControlHistory($bookingPo, $isLocked ? 'locked_or_permissions_updated' : 'unlocked_or_permissions_updated', [
            'edit_permission' => $control['edit_permission'],
            'authorized_users' => collect($control['authorized_users_snapshot'])->pluck('name')->values()->all(),
            'locked' => $isLocked,
            'lock_scope' => $control['lock_scope'] ?? 'all_users',
            'locked_users' => collect($control['locked_users_snapshot'] ?? [])->pluck('name')->values()->all(),
            'locked_roles' => collect($control['locked_roles_snapshot'] ?? [])->pluck('name')->values()->all(),
            'note' => $control['control_note'],
        ]);

        return back()->with('success', 'PO admin control updated successfully.');
    }

    public function destroy(BookingPo $bookingPo)
    {
        $this->authorizeBookingPo($bookingPo);

        $poNo = $bookingPo->po_no;
        $clearedRows = DB::transaction(function () use ($bookingPo) {
            $clearedRows = $this->clearPoFromWorkspace($bookingPo);
            $bookingPo->delete();

            return $clearedRows;
        });

        return redirect()
            ->route($this->bookingRouteName('pending'))
            ->with('success', 'PO number ' . $poNo . ' removed. ' . $clearedRows . ' source row(s) moved back to the Pending PO list. No source rows were deleted.');
    }

    protected function authorizeBookingPo(BookingPo $bookingPo): void
    {
        abort_if(! auth()->user()?->hasRole('admin'), 403);
    }

    private function poControlUsers(): Collection
    {
        return $this->supplyChainPoUsers();
    }

    private function poLockUsers(): Collection
    {
        return User::query()
            ->where('status', 1)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);
    }

    private function poControlRoles(): Collection
    {
        return SpatieRole::query()
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    private function supplyChainPoUsers(): Collection
    {
        if ($this->supplyChainPoUsersCache instanceof Collection) {
            return $this->supplyChainPoUsersCache;
        }

        return $this->supplyChainPoUsersCache = User::query()
            ->where('status', 1)
            ->whereHas('roles', fn ($query) => $query->where('name', 'supply_chain'))
            ->orderBy('name')
            ->get(['id', 'name', 'email']);
    }

    private function defaultPoAdminControl(): array
    {
        $supplyChainUsers = $this->supplyChainPoUsers();

        return [
            'locked' => false,
            'lock_reason' => '',
            'lock_scope' => 'all_users',
            'locked_user_ids' => [],
            'locked_users_snapshot' => [],
            'locked_role_ids' => [],
            'locked_roles_snapshot' => [],
            'locked_by' => null,
            'locked_by_name' => null,
            'locked_at' => null,
            'edit_permission' => 'authorized_users',
            'authorized_user_ids' => $supplyChainUsers->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            'authorized_users_snapshot' => $supplyChainUsers->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ])->values()->all(),
            'generate_permission' => 'supply_chain_only',
            'control_note' => 'Default PO generate and re-generate owner: Supply Chain users only.',
            'updated_by' => null,
            'updated_by_name' => null,
            'updated_at' => null,
        ];
    }

    private function poAdminControl(BookingPo $bookingPo): array
    {
        $data = $bookingPo->booking_data ?: [];
        $control = is_array($data['admin_control'] ?? null) ? $data['admin_control'] : [];
        $merged = array_replace($this->defaultPoAdminControl(), $control);
        $merged['generate_permission'] = 'supply_chain_only';

        return $merged;
    }

    private function isPoLocked(BookingPo $bookingPo): bool
    {
        return (bool) ($this->poAdminControl($bookingPo)['locked'] ?? false);
    }

    private function savePoAdminControl(BookingPo $bookingPo, array $control): BookingPo
    {
        $bookingPo = $bookingPo->fresh() ?: $bookingPo;
        $data = $bookingPo->booking_data ?: [];
        $data['admin_control'] = $control;
        $bookingPo->booking_data = $data;
        $bookingPo->save();

        return $bookingPo->fresh(['excelFile', 'excelRow.cells.header', 'generatedBy', 'completedBy']) ?: $bookingPo;
    }

    private function appendPoControlHistory(BookingPo $bookingPo, string $action, array $details = []): BookingPo
    {
        $bookingPo = $bookingPo->fresh() ?: $bookingPo;
        $data = $bookingPo->booking_data ?: [];
        $history = collect($data['admin_control_history'] ?? []);

        $history->push([
            'action' => $action,
            'details' => $details,
            'changed_by' => auth()->id(),
            'changed_by_name' => optional(auth()->user())->name,
            'changed_at' => now()->format('Y-m-d H:i:s'),
        ]);

        $data['admin_control_history'] = $history->take(-30)->values()->all();
        $bookingPo->booking_data = $data;
        $bookingPo->save();

        return $bookingPo->fresh(['excelFile', 'excelRow.cells.header', 'generatedBy', 'completedBy']) ?: $bookingPo;
    }

    private function clearPoFromWorkspace(BookingPo $bookingPo): int
    {
        $poNo = trim((string) $bookingPo->po_no);
        $poHeaderIds = $this->headerIdsForGroup('po_no');
        $dateHeaderIds = $this->headerIdsForGroup('po_date');

        $rowIds = collect([$bookingPo->excel_row_id])->filter()->map(fn ($id) => (int) $id);

        if ($poNo !== '' && ! empty($poHeaderIds)) {
            $rowIds = $rowIds->merge(
                ExcelCell::query()
                    ->whereIn('header_id', $poHeaderIds)
                    ->whereRaw('TRIM(value) = ?', [$poNo])
                    ->pluck('row_id')
                    ->map(fn ($id) => (int) $id)
            );
        }

        $rowIds = $rowIds->unique()->values();

        if ($rowIds->isEmpty()) {
            return 0;
        }

        $headers = ExcelHeader::query()
            ->whereIn('id', collect($poHeaderIds)->merge($dateHeaderIds)->unique()->values()->all())
            ->get();

        if ($headers->isEmpty()) {
            return $rowIds->count();
        }

        $batchId = (string) Str::uuid();
        ExcelRow::query()
            ->whereIn('id', $rowIds->all())
            ->with('excelFile')
            ->get()
            ->each(function (ExcelRow $row) use ($headers, $batchId) {
                foreach ($headers as $header) {
                    $this->writeWorkspaceCell($row, $header, '', $batchId);
                }
            });

        return $rowIds->count();
    }

    private function poControlStats(Collection $bookingPos): array
    {
        return [
            'total' => $bookingPos->count(),
            'generated' => $bookingPos->filter(fn (BookingPo $po) => $this->hasHistoryAction($po, 'generated'))->count(),
            'regenerated' => $bookingPos->filter(fn (BookingPo $po) => $po->revision_no > 0 || $this->hasHistoryAction($po, 'regenerated'))->count(),
            'changed' => $bookingPos->filter(fn (BookingPo $po) => $po->needs_regenerate || count($po->booking_data['source_change_log'] ?? []) > 0)->count(),
            'completed' => $bookingPos->filter(fn (BookingPo $po) => ($po->status ?? null) === 'completed')->count(),
            'locked' => $bookingPos->filter(fn (BookingPo $po) => (bool) ($this->poAdminControl($po)['locked'] ?? false))->count(),
            'authorized' => $bookingPos->filter(fn (BookingPo $po) => count($this->poAdminControl($po)['authorized_user_ids'] ?? []) > 0)->count(),
        ];
    }

    private function applyControlFilters(Collection $bookingPos, Request $request): Collection
    {
        $keyword = mb_strtolower(trim((string) $request->input('q', '')));
        $state = trim((string) $request->input('state', 'all'));
        $buyer = mb_strtolower(trim((string) $request->input('buyer', '')));
        $vendor = mb_strtolower(trim((string) $request->input('vendor', '')));

        return $bookingPos
            ->filter(function (BookingPo $po) use ($keyword, $state, $buyer, $vendor) {
                $data = $po->booking_data ?: [];
                $history = collect($data['generation_history'] ?? []);
                $sourceChanges = collect($data['source_change_log'] ?? []);

                if ($keyword !== '') {
                    $haystack = mb_strtolower(implode(' ', array_filter([
                        $po->po_no,
                        $po->buyer_name,
                        $po->season_name,
                        $po->ihod,
                        $po->vendor_name,
                        $po->style_name,
                        $po->item_name,
                        $data['to'] ?? null,
                        $data['supplier'] ?? null,
                    ])));

                    if (! str_contains($haystack, $keyword)) {
                        return false;
                    }
                }

                if ($buyer !== '' && ! str_contains(mb_strtolower((string) $po->buyer_name), $buyer)) {
                    return false;
                }

                if ($vendor !== '' && ! str_contains(mb_strtolower((string) $po->vendor_name), $vendor)) {
                    return false;
                }

                return match ($state) {
                    'generated' => $this->hasHistoryAction($po, 'generated'),
                    'regenerated' => $po->revision_no > 0 || $this->hasHistoryAction($po, 'regenerated'),
                    'changed' => $po->needs_regenerate || $sourceChanges->isNotEmpty(),
                    'completed' => ($po->status ?? null) === 'completed',
                    'locked' => (bool) ($this->poAdminControl($po)['locked'] ?? false),
                    'authorized' => count($this->poAdminControl($po)['authorized_user_ids'] ?? []) > 0,
                    'admin_only' => ($this->poAdminControl($po)['edit_permission'] ?? 'authorized_users') === 'admin_only',
                    'pending' => false,
                    default => true,
                };
            })
            ->values();
    }

    private function pendingRowsForControl(Request $request, int $perPage, bool $respectState = true): LengthAwarePaginator
    {
        $state = trim((string) $request->input('state', 'all'));
        $page = max(1, (int) $request->input('pending_page', 1));
        $options = [
            'path' => $request->url(),
            'query' => $request->query(),
            'pageName' => 'pending_page',
        ];

        if ($respectState && ! in_array($state, ['all', 'pending'], true)) {
            return new LengthAwarePaginator(collect(), 0, $perPage, 1, $options);
        }

        $pendingRequest = Request::create($request->url(), 'GET', [
            'keyword' => $request->input('q'),
            'buyer' => $request->input('buyer'),
            'vendor' => $request->input('vendor'),
        ]);

        $pendingRows = $this->buildPendingRowsQuery($pendingRequest)
            ->latest('id')
            ->paginate($perPage, ['*'], 'pending_page', $page)
            ->withQueryString();

        $this->decoratePendingRows($pendingRows);

        return $pendingRows;
    }

    private function poFilterOptions(Collection $bookingPos): array
    {
        return [
            'buyers' => $this->mergeControlFilterOptions($this->dropdownOptions('buyer'), $bookingPos->pluck('buyer_name')),
            'vendors' => $this->mergeControlFilterOptions($this->dropdownOptions('vendor'), $bookingPos->pluck('vendor_name')),
        ];
    }

    private function mergeControlFilterOptions($sourceOptions, $generatedOptions): Collection
    {
        return collect($sourceOptions)
            ->merge($generatedOptions)
            ->map(fn ($value) => trim((string) $value))
            ->filter(fn ($value) => $value !== '')
            ->unique(fn ($value) => $this->normalize($value))
            ->sortBy(fn ($value) => mb_strtolower($value))
            ->values();
    }

    private function paginateControlCollection(Collection $items, Request $request, int $perPage): LengthAwarePaginator
    {
        $page = max(1, (int) $request->input('page', 1));

        return new LengthAwarePaginator(
            $items->forPage($page, $perPage)->values(),
            $items->count(),
            $perPage,
            $page,
            [
                'path' => $request->url(),
                'query' => $request->query(),
            ]
        );
    }

    private function hasHistoryAction(BookingPo $po, string $action): bool
    {
        return collect($po->booking_data['generation_history'] ?? [])
            ->contains(fn ($entry) => ($entry['action'] ?? null) === $action);
    }
}
