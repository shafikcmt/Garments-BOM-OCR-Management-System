<?php

namespace App\Services;

use App\Http\Controllers\Shared\ExcelFileController;
use App\Models\ActivityLog;
use App\Models\ExcelFile;
use App\Models\ExcelHeader;
use App\Models\ExcelRow;
use App\Models\MotherStyle;
use App\Models\OrderLine;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Creates a style's BOM from a screen instead of an Excel file.
 *
 * The BOM workspace stays the source every other department reads (PO,
 * approval, status), so a BOM made here is written AS workspace rows: one row per
 * order line x material, exactly like an imported one. The standard tables, the
 * calculated columns and the approval flow then work on it unchanged.
 *
 * The rows go into one "created in the system" file per buyer and season, so a
 * style's BOM can be built up over several visits. A material already on an order
 * line is never added twice.
 */
class BomBuilder
{
    public const MANUAL_PATH = 'manual-bom';

    public const MAX_LINES = 30;

    public const MAX_MATERIALS = 100;

    public const MAX_ROWS = 500;

    /**
     * Field => workspace column names it may be written to, best first. These
     * are the INPUT columns: calculated ones (Customer Contract Quantity, GMNTS
     * PO Number) are rewritten from them by the workspace and are never targets.
     */
    private const FIELDS = [
        'season' => ['Season', ['season_name', 'season']],
        'style' => ['Style', ['style_name', 'style', 'style_no']],
        'gmts_color' => ['GMTS color', ['gmts_color_name', 'gmts_colour_name', 'gmts_color']],
        'customer_po' => ['Customer PO', ['initial_contract_number', 'contract_number', 'customer_contract', 'po_number']],
        'ship_date' => ['Ship date', ['contract_shipment_date', 'initial_contract_shipment_date', 'po_shipment_date']],
        'material_type' => ['Material type', ['material_type', 'item_type']],
        'material_description' => ['Material description', ['material_description', 'description']],
        'sap_code' => ['SAP code', ['sap_code']],
        'material_color' => ['Material color', ['material_color']],
        'uom' => ['Unit', ['uom', 'unit']],
        'bom_qty' => ['BOM quantity', ['bom_quantity', 'bom_qty']],
        'consumption' => ['Consumption', ['booking_consumption_from_cad', 'initial_consumption', 'booking_yy', 'consumption']],
        'wastage' => ['Wastage', ['wastage_for_ordering_percent', 'wastage_for_ordering', 'waste_percent', 'wastage_percent', 'wastage']],
    ];

    /** Without these there is nowhere to put a BOM line. */
    private const REQUIRED = ['style', 'material_description'];

    public function __construct(private HeaderAliasResolver $aliases)
    {
    }

    /**
     * The workspace column for every field this person may write.
     *
     * @return array{ids: array<string, int>, missing: list<string>, locked: list<string>}
     */
    public function fieldColumns(User $user): array
    {
        $headers = ExcelHeader::where('is_active', true)->orderBy('position')->get();
        $roleIds = $user->roles->pluck('id');
        $ids = [];
        $missing = [];
        $locked = [];

        foreach (self::FIELDS as $field => [$label, $names]) {
            $match = null;

            foreach ($names as $name) {
                $match = $headers->first(fn (ExcelHeader $h) => $this->aliases->normalize($h->header_key) === $name || $this->aliases->normalize($h->header_name) === $name);
                if ($match) {
                    break;
                }
            }

            if (! $match) {
                $missing[] = $label;
            } elseif (! $user->hasRole('admin') && ! $roleIds->contains($match->owner_role_id)) {
                $locked[] = $label;
            } else {
                $ids[$field] = $match->id;
            }
        }

        return ['ids' => $ids, 'missing' => $missing, 'locked' => $locked];
    }

    /**
     * Add the BOM lines. Every order line gets every material.
     *
     * @param  list<array{color: ?string, po: ?string, ship_date: ?string, qty: float|int|string, child: ?string}>  $lines
     * @param  list<array{type: ?string, description: string, sap_code: ?string, color: ?string, uom: ?string, consumption: float|int|string, wastage: float|int|string|null}>  $materials
     * @return array{created: int, skipped: int, not_saved: list<string>, file: ExcelFile}
     * @throws \DomainException
     */
    public function create(MotherStyle $mother, array $lines, array $materials, User $by): array
    {
        $columns = $this->fieldColumns($by);

        foreach (self::REQUIRED as $field) {
            if (! isset($columns['ids'][$field])) {
                throw new \DomainException('The workspace has no usable "' . self::FIELDS[$field][0] . '" column for your role. Ask Admin to check the workspace columns.');
            }
        }

        if (count($lines) * count($materials) > self::MAX_ROWS) {
            throw new \DomainException('That is more than ' . self::MAX_ROWS . ' BOM lines at once. Add fewer order lines or materials and repeat.');
        }

        $file = $this->manualFile($mother, $by);

        if ($file->isLockedForUser($by)) {
            throw new \DomainException('The BOM file of this season is locked by Admin.');
        }

        $existing = $this->existingKeys($mother);
        $headers = ExcelHeader::where('is_active', true)->orderBy('position')->get();
        $rowIds = [];
        $skipped = 0;

        DB::transaction(function () use ($mother, $lines, $materials, $by, $file, $columns, $existing, $headers, &$rowIds, &$skipped) {
            $next = ((int) $file->rows()->max('row_number')) + 1;
            $now = now()->toDateTimeString();
            $prepared = [];

            foreach ($lines as $line) {
                $styleCode = ! empty($line['child']) ? $mother->style_no . ' (' . $line['child'] . ')' : $mother->style_no;
                $color = $this->clean($line['color'] ?? null);
                $po = $this->clean($line['po'] ?? null);
                $lineKey = md5(mb_strtolower($styleCode . '|' . $color . '|' . $po));

                foreach ($materials as $material) {
                    $dupe = $lineKey . '|' . mb_strtolower(trim((string) ($material['description'] ?? ''))) . '|' . mb_strtolower(trim((string) ($material['sap_code'] ?? '')));

                    if (isset($existing[$dupe])) {
                        $skipped++;

                        continue;
                    }
                    $existing[$dupe] = true;

                    $prepared[$next++] = $this->values($columns['ids'], $mother, $styleCode, $line, $material);
                }
            }

            if ($prepared === []) {
                return;
            }

            foreach (array_chunk(array_map(fn ($n) => [
                'excel_file_id' => $file->id, 'row_number' => $n, 'origin' => ExcelRow::ORIGIN_ADDED,
                'created_at' => $now, 'updated_at' => $now,
            ], array_keys($prepared)), 500) as $chunk) {
                ExcelRow::insert($chunk);
            }

            $idByNumber = ExcelRow::where('excel_file_id', $file->id)->whereIn('row_number', array_keys($prepared))->pluck('id', 'row_number');
            $cells = [];
            $logs = [];

            foreach ($prepared as $number => $valueByHeader) {
                $rowId = $idByNumber[$number];
                $rowIds[] = $rowId;

                foreach ($headers as $header) {
                    $cells[] = [
                        'row_id' => $rowId, 'header_id' => $header->id, 'value' => $valueByHeader[$header->id] ?? null,
                        'updated_by' => $by->id, 'created_at' => $now, 'updated_at' => $now,
                    ];
                }

                $logs[] = [
                    'excel_file_id' => $file->id, 'row_id' => $rowId, 'header_id' => null, 'old_value' => null,
                    'new_value' => 'Row created by the BOM builder', 'action' => 'row_created', 'user_id' => $by->id,
                    'created_at' => $now, 'updated_at' => $now,
                ];
            }

            foreach (array_chunk($cells, 1000) as $chunk) {
                \App\Models\ExcelCell::insert($chunk);
            }
            foreach (array_chunk($logs, 500) as $chunk) {
                ActivityLog::insert($chunk);
            }

            $file->update(['total_rows' => $file->rows()->count()]);
        });

        if ($rowIds !== []) {
            $this->afterCreate($file, $rowIds, $by);
        }

        return ['created' => count($rowIds), 'skipped' => $skipped, 'not_saved' => $columns['locked'], 'file' => $file];
    }

    /** The "created in the system" file of this buyer and season. */
    private function manualFile(MotherStyle $mother, User $by): ExcelFile
    {
        $file = ExcelFile::where('buyer_id', $mother->buyer_id)->where('buyer_season_id', $mother->buyer_season_id)
            ->where('file_path', self::MANUAL_PATH)->first();

        if ($file) {
            return $file;
        }

        $mother->loadMissing(['buyer', 'season']);

        return ExcelFile::create([
            'file_name' => 'bom-' . $mother->buyer_id . '-' . $mother->buyer_season_id,
            'original_file_name' => 'BOM ' . $mother->buyer?->buyer_name . ' ' . $mother->season?->code . ' (created in the system)',
            'file_path' => self::MANUAL_PATH,
            'uploaded_by' => $by->id,
            'buyer_id' => $mother->buyer_id,
            'buyer_season_id' => $mother->buyer_season_id,
            'upload_batch_no' => 'BUILDER-' . now()->format('YmdHis'),
            'total_rows' => 0,
            'status' => 'pending',
            'remarks' => 'Created with the BOM builder',
            'submitted_at' => now(),
        ]);
    }

    /**
     * What this style already has: order-line key + material, so nothing is added twice.
     *
     * @return array<string, true>
     */
    private function existingKeys(MotherStyle $mother): array
    {
        $keys = [];

        OrderLine::where('mother_style_id', $mother->id)->with('bomItems')->get()->each(function (OrderLine $line) use (&$keys) {
            foreach ($line->bomItems as $item) {
                $keys[$line->line_key . '|' . mb_strtolower(trim((string) $item->material_description)) . '|' . mb_strtolower(trim((string) $item->sap_code))] = true;
            }
        });

        return $keys;
    }

    /**
     * @param  array<string, int>  $ids  field => header id
     * @return array<int, string>  header id => value
     */
    private function values(array $ids, MotherStyle $mother, string $styleCode, array $line, array $material): array
    {
        $wastage = $material['wastage'] ?? null;
        $wastage = $wastage === null || $wastage === '' ? null : (float) $wastage;

        $by = [
            'season' => $mother->season?->code,
            'style' => $styleCode,
            'gmts_color' => $this->clean($line['color'] ?? null),
            'customer_po' => $this->clean($line['po'] ?? null),
            'ship_date' => $this->clean($line['ship_date'] ?? null),
            'material_type' => $this->clean($material['type'] ?? null),
            'material_description' => $this->clean($material['description'] ?? null),
            'sap_code' => $this->clean($material['sap_code'] ?? null),
            'material_color' => $this->clean($material['color'] ?? null),
            'uom' => $this->clean($material['uom'] ?? null),
            // BOM Quantity is the garment quantity of the order line; the workspace works the rest out from it.
            'bom_qty' => $this->number($line['qty'] ?? null),
            'consumption' => $this->number($material['consumption'] ?? null),
            // Stored as a fraction (5% -> 0.05), the way the workspace reads it.
            'wastage' => $wastage === null ? null : $this->number($wastage / 100, 6),
        ];

        $values = [];

        foreach ($ids as $field => $headerId) {
            $values[$headerId] = $by[$field] ?? null;
        }

        return $values;
    }

    /**
     * Calculated columns, standard tables, approval status.
     *
     * @param  list<int>  $rowIds
     */
    private function afterCreate(ExcelFile $file, array $rowIds, User $by): void
    {
        app(ExcelFileController::class)->recalculateFile($file, $by->id);

        $sync = app(BomTableSync::class);
        $sync->syncRows($file, $rowIds);

        try {
            app(BomApprovals::class)->afterRowsChanged($rowIds);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('BOM change check failed: ' . $e->getMessage());
        }
    }

    private function clean(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : preg_replace('/\s+/', ' ', $text);
    }

    private function number(mixed $value, int $decimals = 6): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return rtrim(rtrim(number_format((float) $value, $decimals, '.', ''), '0'), '.') ?: '0';
    }
}
