<?php

namespace App\Console\Commands;

use App\Models\BookingPo;
use App\Models\ExcelCell;
use App\Models\MaterialBulkIssue;
use App\Models\MaterialDeadMovement;
use App\Models\MaterialLiabilityMovement;
use App\Models\MaterialReceiving;
use App\Models\MaterialRequisition;
use App\Models\MaterialStockLedger;
use App\Services\BookingPoSourceService;
use App\Services\HeaderAliasResolver;
use Illuminate\Console\Command;

/**
 * One-off backfill: populate sap_code on Store stock rows created before
 * BookingPo::toStockPayload() started copying it. Uses the exact same resolution
 * as toStockPayload() (BookingPoSourceService: BOM sap_code cell, fallback to
 * booking_pos.supplier_article), so backfilled values match new records.
 *
 * Idempotent: only touches rows whose sap_code is currently NULL/blank, and only
 * writes a resolved, non-blank value. Run with --dry-run to preview counts.
 */
class BackfillStockSapCode extends Command
{
    protected $signature = 'stock:backfill-sap-code {--dry-run : Show what would change without writing}';

    protected $description = 'Backfill sap_code on existing Store stock rows from the linked BOM row';

    /** @var array<string, class-string> */
    private array $models = [
        'material_receivings' => MaterialReceiving::class,
        'material_bulk_issues' => MaterialBulkIssue::class,
        'material_liability_movements' => MaterialLiabilityMovement::class,
        'material_dead_movements' => MaterialDeadMovement::class,
        'material_requisitions' => MaterialRequisition::class,
        'material_stock_ledgers' => MaterialStockLedger::class,
    ];

    public function handle(BookingPoSourceService $source, HeaderAliasResolver $resolver): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $this->info($dryRun ? 'DRY RUN — no changes will be written.' : 'Backfilling sap_code…');

        // Rows needing backfill: sap_code blank + a BOM row to resolve from.
        $needy = collect($this->models)->mapWithKeys(function (string $model, string $table) {
            return [$table => $model::query()
                ->whereNotNull('excel_row_id')
                ->where(fn ($q) => $q->whereNull('sap_code')->orWhere('sap_code', ''))
                ->get(['id', 'excel_row_id', 'booking_po_id'])];
        });

        $bookingPoIds = $needy->flatten(1)->pluck('booking_po_id')->filter()->unique()->values();
        $rowIds = $needy->flatten(1)->pluck('excel_row_id')->filter()->unique()->values();

        if ($rowIds->isEmpty()) {
            $this->info('Nothing to backfill — all rows already have sap_code (or no BOM link).');
            return self::SUCCESS;
        }

        // sap_code by booking_po_id — exact parity with toStockPayload().
        $sapByBookingPo = BookingPo::with('excelRow.cells.header')
            ->whereIn('id', $bookingPoIds->all())
            ->get()
            ->mapWithKeys(fn (BookingPo $po) => [
                $po->id => $this->clean($source->sourceValueForBookingPo($po, 'sap_code')),
            ])
            ->filter();

        // Fallback for rows with no booking_po_id: read the BOM row's sap_code cell.
        $sapHeaderId = $resolver->resolveHeaderId('sap_code');
        $sapByRow = $sapHeaderId
            ? ExcelCell::whereIn('row_id', $rowIds->all())
                ->where('header_id', $sapHeaderId)
                ->get(['row_id', 'value'])
                ->mapWithKeys(fn ($c) => [(int) $c->row_id => $this->clean($c->value)])
                ->filter()
            : collect();

        $grandTotal = 0;

        foreach ($needy as $table => $rows) {
            $model = $this->models[$table];
            $updated = 0;
            $unresolved = 0;

            foreach ($rows as $row) {
                $sap = ($row->booking_po_id ? $sapByBookingPo->get($row->booking_po_id) : null)
                    ?? $sapByRow->get((int) $row->excel_row_id);

                if (! $sap) {
                    $unresolved++;
                    continue;
                }

                if (! $dryRun) {
                    $model::whereKey($row->id)->update(['sap_code' => $sap]);
                }
                $updated++;
            }

            $grandTotal += $updated;
            $this->line(sprintf(
                '  %-32s %s%d %s%s',
                $table,
                $dryRun ? 'would update ' : 'updated ',
                $updated,
                'of ' . $rows->count() . ' blank',
                $unresolved ? " ({$unresolved} unresolved — no sap on BOM row)" : ''
            ));
        }

        $this->newline();
        $this->info(($dryRun ? 'Would backfill ' : 'Backfilled ') . $grandTotal . ' row(s).');

        if ($dryRun && $grandTotal > 0) {
            $this->comment('Re-run without --dry-run to apply.');
        }

        return self::SUCCESS;
    }

    private function clean($value): ?string
    {
        $value = trim((string) $value);

        // Treat common placeholder tokens as blank, matching source-service intent.
        if ($value === '' || in_array(strtolower($value), ['-', '--', 'n/a', 'na', 'none', 'nil'], true)) {
            return null;
        }

        return $value;
    }
}
