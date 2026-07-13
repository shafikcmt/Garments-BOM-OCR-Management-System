<?php

namespace App\Services;

use App\Models\ExcelCell;
use App\Models\ExcelRow;
use App\Models\MaterialStockLedger;
use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Writes ledger-derived values into the matching BOM Workspace cells for a
 * given excel_row_id, so the overlapping Store fields (Liability, Dead Stock,
 * Liability Value, Receipt Qty, Invoiced Qty/Rate/Amount) are auto-filled from
 * the Material Stock Ledger — the single source of truth.
 *
 * Rules:
 *  - Only writes when a material_stock_ledgers row exists for the key; never
 *    blanks/zeroes a cell that has no ledger data yet.
 *  - Never writes into a locked BOM file.
 *  - Aggregates across sizes (a BOM row = one excel_row_id, many ledger sizes).
 *  - Attributes writes to the system user, not the human who triggered it.
 *  - Fully disabled by config('stock.sync_workspace_cells').
 */
class MaterialStockLedgerCellSyncService
{
    /** @var array<string, int|null>|null */
    private ?array $storeHeaderIds = null;

    private ?int $systemUserId = null;

    private bool $systemUserResolved = false;

    public function __construct(private HeaderAliasResolver $aliases)
    {
    }

    public function enabled(): bool
    {
        return (bool) config('stock.sync_workspace_cells', true);
    }

    /**
     * Sync all ledger-owned Store cells for one BOM row.
     */
    public function syncRow(?int $excelRowId): void
    {
        if (! $this->enabled() || ! $excelRowId) {
            return;
        }

        $ledgers = MaterialStockLedger::where('excel_row_id', $excelRowId)->get();

        // Never blank a cell that has no ledger data.
        if ($ledgers->isEmpty()) {
            return;
        }

        $row = ExcelRow::with('excelFile')->find($excelRowId);
        if (! $row || ! $row->excelFile) {
            return;
        }

        // Respect the file lock: never write into a locked/closed file.
        if ($row->excelFile->is_locked) {
            return;
        }

        // --- Aggregate ledger rows across sizes ---
        $liability = 0.0;
        $dead = 0.0;
        $liabilityValue = 0.0;
        $totalQty = 0.0;
        $totalAmount = 0.0;

        foreach ($ledgers as $l) {
            $avg = $l->avg_unit_price !== null ? (float) $l->avg_unit_price : 0.0;
            $liability += (float) $l->liability_closing_qty;
            $dead += (float) $l->dead_closing_qty;
            $liabilityValue += (float) $l->liability_closing_qty * $avg;
            $totalQty += (float) $l->total_receive_qty;
            $totalAmount += (float) $l->total_receive_qty * $avg;
        }

        // Invoiced = SUM/weighted across all receivings (confirmed with owner).
        // Invoiced Qty mirrors Receipt Qty; Rate is the weighted average.
        $invoicedRate = $totalQty > 0 ? $totalAmount / $totalQty : null;

        $values = [
            'liability' => $liability,
            'dead_stock_quantity' => $dead,
            'liability_stock_value' => $liabilityValue,
            'receipt_qty' => $totalQty,
            'invoiced_qty_store' => $totalQty,
            'invoiced_rate_store' => $invoicedRate,
            'invoiced_amount_store' => $totalAmount,
        ];

        $headerIds = $this->storeHeaderIds();
        $userId = $this->systemUserId();

        foreach ($values as $canonical => $value) {
            $headerId = $headerIds[$canonical] ?? null;
            if ($headerId === null) {
                continue; // header not present in this install — skip silently.
            }

            $this->writeCell($excelRowId, $headerId, $this->fmt($value), $userId);
        }
    }

    /**
     * Resolve the store-owned header id for each ledger-owned canonical key once.
     *
     * @return array<string, int|null>
     */
    private function storeHeaderIds(): array
    {
        if ($this->storeHeaderIds !== null) {
            return $this->storeHeaderIds;
        }

        $storeRoleId = Role::where('name', 'store')->value('id');

        $ids = [];
        foreach ((array) config('stock.ledger_owned_store_header_keys', []) as $canonical) {
            $ids[$canonical] = $this->aliases->resolveHeaderId($canonical, $storeRoleId ? (int) $storeRoleId : null);
        }

        return $this->storeHeaderIds = $ids;
    }

    private function systemUserId(): ?int
    {
        if ($this->systemUserResolved) {
            return $this->systemUserId;
        }

        $this->systemUserResolved = true;
        $email = config('stock.system_user_email');

        return $this->systemUserId = $email
            ? User::where('email', $email)->value('id')
            : null;
    }

    private function writeCell(int $rowId, int $headerId, ?string $value, ?int $userId): void
    {
        $cell = ExcelCell::firstOrNew([
            'row_id' => $rowId,
            'header_id' => $headerId,
        ]);

        if ((string) ($cell->value ?? '') === (string) ($value ?? '')) {
            return; // no change — avoid a pointless write.
        }

        $cell->value = $value;
        $cell->updated_by = $userId;
        $cell->save();
    }

    /**
     * Match ExcelFileController::fmtNum formatting so synced values render like
     * every other calculated cell (integer when whole, else trimmed 4-dp).
     */
    private function fmt($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = (float) $value;

        if (abs($value - round($value)) < 0.000001) {
            return (string) round($value);
        }

        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
    }
}
