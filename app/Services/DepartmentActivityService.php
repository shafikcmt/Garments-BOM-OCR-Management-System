<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\ExcelCell;
use App\Models\ExcelFile;
use App\Models\ExcelHeader;
use App\Models\ExcelRow;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Department progress on the workspace columns each one owns.
 *
 * Built entirely from data the application already records — no new tracking:
 *   - which columns a department owns  -> excel_headers.owner_role_id
 *   - which of those it MUST fill      -> excel_headers.is_required / is_active
 *   - what has actually been entered   -> excel_cells.value
 *   - when it last worked on them      -> activity_logs (written on every save)
 *   - when its people last signed in   -> users.last_login_at
 *
 * Read-only. Nothing here defines or changes which headers are required; it only
 * reads that configuration to report on it.
 */
class DepartmentActivityService
{
    public const NOT_STARTED = 'not_started';
    public const IN_PROGRESS = 'in_progress';
    public const COMPLETED = 'completed';
    public const NO_COLUMNS = 'no_columns';

    /**
     * Per-department progress, across every workspace or scoped to one.
     *
     * Deliberately a handful of grouped queries rather than a loop per role:
     * the column count is small but the cell count is not.
     *
     * @return array<int, array<string, mixed>>
     */
    public function summary(?ExcelFile $file = null, ?string $onlyRole = null): array
    {
        // --- Which required columns does each department own? ---
        $headers = ExcelHeader::query()
            ->where('is_required', true)
            ->where('is_active', true)
            ->join('roles', 'excel_headers.owner_role_id', '=', 'roles.id')
            // Scoping here rather than filtering the result keeps a single-role
            // caller from ever loading another department's columns.
            ->when($onlyRole, fn ($q) => $q->where('roles.name', $onlyRole))
            ->get(['excel_headers.id', 'excel_headers.owner_role_id', 'roles.name as role_name']);

        if ($headers->isEmpty()) {
            return [];
        }

        $rowCount = $this->rowCount($file);
        $filledByHeader = $this->filledCellsByHeader($headers->pluck('id'), $file);
        $lastActivity = $this->lastActivityByRole($file);
        $lastSignIn = $this->lastSignInByRole();

        return $headers
            ->groupBy('owner_role_id')
            ->map(function ($group, $roleId) use ($rowCount, $filledByHeader, $lastActivity, $lastSignIn) {
                $headerIds = $group->pluck('id');

                $requiredColumns = $headerIds->count();
                // A column counts as started once it holds a value anywhere in
                // scope — that is the "12/20 filled" figure.
                $columnsStarted = $headerIds->filter(fn ($id) => ($filledByHeader[$id] ?? 0) > 0)->count();

                $cellsExpected = $requiredColumns * $rowCount;
                $cellsFilled = (int) $headerIds->sum(fn ($id) => $filledByHeader[$id] ?? 0);

                // Guard against a stale count exceeding the expectation (rows
                // deleted after cells were written) so a bar can never pass 100%.
                $cellsFilled = min($cellsFilled, $cellsExpected);

                $percent = $cellsExpected > 0
                    ? round(($cellsFilled / $cellsExpected) * 100, 1)
                    : 0.0;

                return [
                    'role' => $group->first()->role_name,
                    'label' => $this->label($group->first()->role_name),
                    'required_columns' => $requiredColumns,
                    'columns_started' => $columnsStarted,
                    'cells_expected' => $cellsExpected,
                    'cells_filled' => $cellsFilled,
                    'percent' => $percent,
                    'status' => $this->status($cellsExpected, $cellsFilled, $percent),
                    'last_activity' => $lastActivity[(int) $roleId] ?? null,
                    'last_sign_in' => $lastSignIn[(int) $roleId] ?? null,

                    // Aliases matching DashboardMetricsService::workspaceCompletionFor(),
                    // so the existing <x-workspace-progress> card renders from
                    // this service without its markup changing.
                    'fields' => $requiredColumns,
                    'rows' => $rowCount,
                    'expected' => $cellsExpected,
                    'filled' => $cellsFilled,
                    'pending' => max(0, $cellsExpected - $cellsFilled),
                ];
            })
            ->sortByDesc('percent')
            ->values()
            ->all();
    }

    /**
     * One department's own progress — the same calculation as summary(), scoped
     * to a single role so a department user's screen can never carry another
     * department's figures.
     *
     * Null when the role owns no required columns, which the caller renders as
     * "nothing to track" rather than a misleading 0%.
     *
     * @return array<string, mixed>|null
     */
    public function forRole(string $role, ?ExcelFile $file = null): ?array
    {
        return $this->summary($file, $role)[0] ?? null;
    }

    /**
     * Same shape as forRole(), all zeroed, for a role that owns no required
     * columns yet.
     *
     * Callers that render a progress card unconditionally need every key to
     * exist — a bare [] would leave the card reading undefined values on a fresh
     * install where no headers are configured.
     *
     * @return array<string, mixed>
     */
    public function emptyProgressFor(string $role): array
    {
        return [
            'role' => $role,
            'label' => $this->label($role),
            'required_columns' => 0,
            'columns_started' => 0,
            'cells_expected' => 0,
            'cells_filled' => 0,
            'percent' => 0.0,
            'status' => self::NO_COLUMNS,
            'last_activity' => null,
            'last_sign_in' => null,
            'fields' => 0,
            'rows' => 0,
            'expected' => 0,
            'filled' => 0,
            'pending' => 0,
        ];
    }

    /**
     * The department a user reports progress for.
     *
     * Only roles that actually own required columns qualify, so admin and
     * management — who own none and oversee everyone — resolve to null and see
     * the all-department view instead of an empty personal one. A user holding
     * several roles takes the first that owns columns.
     */
    public function departmentRoleFor(?User $user): ?string
    {
        if (! $user) {
            return null;
        }

        $owning = ExcelHeader::query()
            ->where('is_required', true)
            ->where('is_active', true)
            ->join('roles', 'excel_headers.owner_role_id', '=', 'roles.id')
            ->distinct()
            ->pluck('roles.name');

        return $user->getRoleNames()->first(fn ($role) => $owning->contains($role));
    }

    /** Screen labels for the status keys. */
    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::COMPLETED => 'Completed',
            self::IN_PROGRESS => 'In Progress',
            self::NO_COLUMNS => 'No Required Columns',
            default => 'Not Started',
        };
    }

    /** Bootstrap subtle-badge tone per status. */
    public static function statusTone(string $status): string
    {
        return match ($status) {
            self::COMPLETED => 'bg-success-subtle text-success-emphasis',
            self::IN_PROGRESS => 'bg-warning-subtle text-warning-emphasis',
            self::NO_COLUMNS => 'bg-secondary-subtle text-secondary-emphasis',
            default => 'bg-danger-subtle text-danger-emphasis',
        };
    }

    private function status(int $expected, int $filled, float $percent): string
    {
        if ($expected === 0) {
            return self::NO_COLUMNS;
        }

        if ($filled === 0) {
            return self::NOT_STARTED;
        }

        return $percent >= 100 ? self::COMPLETED : self::IN_PROGRESS;
    }

    /** Management-friendly department names for the raw role keys. */
    private function label(string $role): string
    {
        return match ($role) {
            'merchant' => 'Merchandising',
            'supply_chain' => 'Supply Chain',
            'commercial' => 'Commercial',
            'store' => 'Store',
            'account' => 'Accounts',
            'management' => 'Management',
            'admin' => 'Admin',
            default => ucwords(str_replace('_', ' ', $role)),
        };
    }

    private function rowCount(?ExcelFile $file): int
    {
        return ExcelRow::query()
            ->when($file, fn ($q) => $q->where('excel_file_id', $file->id))
            ->count();
    }

    /**
     * Non-blank cell count per header, in scope.
     *
     * @param  \Illuminate\Support\Collection<int, int>  $headerIds
     * @return array<int, int>
     */
    private function filledCellsByHeader($headerIds, ?ExcelFile $file): array
    {
        if ($headerIds->isEmpty()) {
            return [];
        }

        return ExcelCell::query()
            ->whereIn('excel_cells.header_id', $headerIds->all())
            ->whereNotNull('excel_cells.value')
            ->where('excel_cells.value', '!=', '')
            ->when($file, fn ($q) => $q
                ->join('excel_rows', 'excel_cells.row_id', '=', 'excel_rows.id')
                ->where('excel_rows.excel_file_id', $file->id))
            ->groupBy('excel_cells.header_id')
            ->selectRaw('excel_cells.header_id as header_id, COUNT(*) as filled')
            ->pluck('filled', 'header_id')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    /**
     * When each department's own columns were last edited, in scope.
     *
     * Attributed by the COLUMN's owner role rather than the editing user's role:
     * a user can hold several roles, but a column belongs to exactly one
     * department, so this answers "when was Store's work last touched" without
     * ambiguity.
     *
     * @return array<int, string>
     */
    private function lastActivityByRole(?ExcelFile $file): array
    {
        return ActivityLog::query()
            ->join('excel_headers', 'activity_logs.header_id', '=', 'excel_headers.id')
            ->when($file, fn ($q) => $q->where('activity_logs.excel_file_id', $file->id))
            ->groupBy('excel_headers.owner_role_id')
            ->selectRaw('excel_headers.owner_role_id as role_id, MAX(activity_logs.created_at) as last_at')
            ->pluck('last_at', 'role_id')
            ->all();
    }

    /**
     * Most recent sign-in among each role's users. Always global — signing in is
     * not something that happens "to a workspace".
     *
     * @return array<int, string>
     */
    private function lastSignInByRole(): array
    {
        return User::query()
            ->join('model_has_roles', function ($join) {
                $join->on('users.id', '=', 'model_has_roles.model_id')
                    ->where('model_has_roles.model_type', '=', User::class);
            })
            ->whereNotNull('users.last_login_at')
            ->groupBy('model_has_roles.role_id')
            ->selectRaw('model_has_roles.role_id as role_id, MAX(users.last_login_at) as last_at')
            ->pluck('last_at', 'role_id')
            ->all();
    }
}
