<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\ExcelCell;
use App\Models\ExcelFileChangeLog;
use App\Models\ExcelHeader;
use App\Models\ExcelRow;
use App\Models\InboundShipment;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Inbound shipments: numbering, the clearance summary, and carrying the shipment
 * columns into the BOM workspace (the same way a payment reference is).
 */
class InboundShipments
{
    /** Arrived but not cleared for this many days is flagged. */
    public const CLEARANCE_WAIT_DAYS = 3;

    public function __construct(private HeaderAliasResolver $headers)
    {
    }

    public function assignNumber(InboundShipment $shipment): void
    {
        if (! $shipment->shipment_no) {
            $shipment->forceFill(['shipment_no' => 'SHP-' . ($shipment->created_at ?? now())->format('y') . '-' . str_pad((string) $shipment->id, 4, '0', STR_PAD_LEFT)])->save();
        }
    }

    /**
     * The clearance picture of the shipments still on their way or not yet in-house.
     *
     * @param  Collection<int, InboundShipment>  $shipments
     * @return array{counts: array<string, int>, ports: array<string, int>, weeks: array<string, int>, late: Collection, waiting: Collection, to_deliver: Collection}
     */
    public function clearance(Collection $shipments, ?Carbon $today = null): array
    {
        $today ??= Carbon::today();
        $open = $shipments->filter(fn (InboundShipment $s) => $s->status() !== 'in_house');

        return [
            'counts' => collect(array_keys(InboundShipment::STATUSES))->mapWithKeys(fn ($k) => [$k => $shipments->filter(fn ($s) => $s->status() === $k)->count()])->all(),
            'ports' => $open->groupBy(fn ($s) => trim((string) $s->port_of_discharge) ?: 'No port')->map->count()->sortDesc()->all(),
            // Expected arrival week (ISO), for what has not arrived yet.
            'weeks' => $open->filter(fn ($s) => $s->ata === null && $s->eta !== null)
                ->groupBy(fn ($s) => $s->eta->format('o') . '-W' . $s->eta->format('W'))->map->count()->sortKeys()->all(),
            'late' => $open->filter(fn (InboundShipment $s) => $s->isLate($today))->sortBy('eta')->values(),
            'waiting' => $open->filter(fn (InboundShipment $s) => $s->status() === 'arrived' && ($s->waitingDays($today) ?? 0) >= self::CLEARANCE_WAIT_DAYS)->sortBy('ata')->values(),
            'to_deliver' => $open->filter(fn (InboundShipment $s) => $s->status() === 'cleared')->sortBy('customs_cleared_date')->values(),
        ];
    }

    /**
     * Write the shipment's BL / AWB no, ETD, ETA and in-house dates into the
     * workspace row of every PO it carries, for the columns that exist. A column
     * the workspace does not have is skipped; a cell that already holds the value
     * is left alone. Each change is logged like a manual edit. Never raises.
     *
     * @return int cells written
     */
    public function writeToWorkspace(InboundShipment $shipment, User $actor): int
    {
        try {
            $values = [
                'bl_awb_no' => $shipment->bl_awb_no,
                'committed_etd' => $shipment->etd?->toDateString(),
                'committed_eta' => $shipment->eta?->toDateString(),
                'committed_inhouse' => $shipment->committed_inhouse_date?->toDateString(),
                'actual_inhouse' => $shipment->actual_inhouse_date?->toDateString(),
            ];
            $rowIds = $shipment->links()->with('bookingPo:id,excel_row_id')->get()->map(fn ($l) => $l->bookingPo?->excel_row_id)->filter()->unique()->values();
            if ($rowIds->isEmpty()) {
                return 0;
            }

            $batch = (string) Str::uuid();
            $written = 0;
            $files = [];
            foreach ($values as $canonical => $value) {
                $headerId = $this->headers->resolveHeaderId($canonical);
                if (! $headerId || $value === null || trim((string) $value) === '') {
                    continue;
                }
                $header = ExcelHeader::find($headerId);
                foreach (ExcelRow::whereIn('id', $rowIds)->get() as $row) {
                    $cell = ExcelCell::firstOrNew(['row_id' => $row->id, 'header_id' => $headerId]);
                    $old = $cell->exists ? $cell->value : null;
                    if ((string) $old === (string) $value) {
                        continue;
                    }
                    $cell->value = $value;
                    $cell->updated_by = $actor->id;
                    $cell->save();

                    ActivityLog::create(['excel_file_id' => $row->excel_file_id, 'row_id' => $row->id, 'header_id' => $headerId, 'old_value' => $old,
                        'new_value' => $value, 'action' => $old === null ? 'created' : 'updated', 'user_id' => $actor->id]);
                    ExcelFileChangeLog::create(['excel_file_id' => $row->excel_file_id, 'excel_row_id' => $row->id, 'excel_header_id' => $headerId,
                        'row_number' => $row->row_number, 'header_name' => $header?->header_name, 'old_value' => $old, 'new_value' => $value,
                        'changed_by' => $actor->id, 'batch_id' => $batch]);
                    $files[$row->excel_file_id] = true;
                    $written++;
                }
            }
            foreach (array_keys($files) as $fileId) {
                app(SupplyChainWorkspaceSync::class)->queueFile((int) $fileId, $actor->id);
            }

            return $written;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Inbound shipment workspace write failed: ' . $e->getMessage());

            return 0;
        }
    }
}
