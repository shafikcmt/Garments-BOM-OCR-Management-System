<?php

namespace App\Services;

use App\Models\BomItem;
use App\Models\BuyerSeason;
use App\Models\ChildStyle;
use App\Models\ExcelFile;
use App\Models\ExcelRow;
use App\Models\MotherStyle;
use App\Models\OrderLine;
use App\Support\StyleCodeParser;
use Illuminate\Support\Facades\Log;

/**
 * Fills the standard tables (order_lines, bom_items) from the BOM workspace and
 * links each order line to the Style Library.
 *
 * One workspace row becomes one bom_item; rows sharing style + color + customer
 * PO inside a file share one order_line. The workspace stays the place users
 * edit (this is a one-way read model), so a sync can always be re-run and gives
 * the same result.
 *
 * FIELD MAP. Which workspace column feeds which table column is declared once,
 * below, by header group (BookingPoSourceService) or canonical key
 * (HeaderAliasResolver). A buyer whose sheet names columns differently is
 * handled by those alias lists, never here.
 */
class BomTableSync
{
    /** order_lines column => header group read through BookingPoSourceService. */
    public const ORDER_LINE_TEXT = [
        'style_no' => 'style',
        'gmts_color' => 'gmts_color',
        'customer_po_no' => 'contract_po',
    ];

    /** bom_items column => header group. */
    public const BOM_ITEM_TEXT = [
        'material_type' => 'material_type',
        'material_description' => 'material_description',
        'sap_code' => 'sap_code',
        'material_color' => 'material_color',
        'uom' => 'uom',
    ];

    /** bom_items column => canonical key (HeaderAliasResolver), numeric. */
    public const BOM_ITEM_NUMBER = [
        'bom_qty' => 'bom_quantity',
        'initial_consumption' => 'initial_consumption',
        'wastage_percent' => 'wastage_for_ordering_percent',
        'consumption_incl_yy' => 'consumption_incl_yy',
    ];

    public const ORDER_QTY_CANONICAL = 'customer_contract_quantity';

    /** @var array<string, array<int, int>> */
    private array $canonicalIds = [];

    public function __construct(
        private BookingPoSourceService $source,
        private HeaderAliasResolver $headers,
    ) {
    }

    public function enabled(): bool
    {
        return (bool) config('stock.sync_bom_tables', true);
    }

    /**
     * Sync every row of a file.
     *
     * @return array{rows: int, order_lines: int, bom_items: int, skipped: int}
     */
    public function syncFile(ExcelFile $file): array
    {
        $result = ['rows' => 0, 'order_lines' => 0, 'bom_items' => 0, 'skipped' => 0];

        $file->rows()->with('cells.header')->orderBy('row_number')->chunkById(200, function ($rows) use ($file, &$result) {
            foreach ($rows as $row) {
                $row->setRelation('excelFile', $file);
                $this->tally($result, $this->syncRow($row));
            }
        });

        $this->pruneEmptyLines($file);

        return $result;
    }

    /**
     * Sync specific rows (after a workspace save). Failure is logged, never
     * raised: the save that triggered it has already succeeded.
     *
     * @param  list<int>  $rowIds
     */
    public function syncRows(ExcelFile $file, array $rowIds): void
    {
        if (! $this->enabled() || $rowIds === []) {
            return;
        }

        try {
            ExcelRow::whereIn('id', $rowIds)->where('excel_file_id', $file->id)->with('cells.header')->get()
                ->each(function (ExcelRow $row) use ($file) {
                    $row->setRelation('excelFile', $file);
                    $this->syncRow($row);
                });
            $this->pruneEmptyLines($file);
        } catch (\Throwable $e) {
            Log::warning('BOM table sync failed for file ' . $file->id . ': ' . $e->getMessage());
        }
    }

    /**
     * @return array{order_line: ?OrderLine, bom_item: ?BomItem, created_line: bool, created_item: bool}
     */
    public function syncRow(ExcelRow $row): array
    {
        $row->loadMissing(['cells.header', 'excelFile']);
        $file = $row->excelFile;

        $style = trim((string) $this->source->sourceValueForRow($row, 'style'));

        // No style, no order line: the row is a blank or a stray, not a BOM line.
        if ($style === '') {
            BomItem::where('excel_row_id', $row->id)->delete();

            return ['order_line' => null, 'bom_item' => null, 'created_line' => false, 'created_item' => false];
        }

        $text = fn (string $group) => ($v = trim((string) $this->source->sourceValueForRow($row, $group))) === '' ? null : $v;

        $color = $text('gmts_color');
        $customerPo = $text('contract_po');
        $lineKey = md5(mb_strtolower($style . '|' . $color . '|' . $customerPo));

        $line = OrderLine::firstOrNew(['excel_file_id' => $file->id, 'line_key' => $lineKey]);
        $createdLine = ! $line->exists;

        $shipDate = $this->source->dateValue($this->source->sourceValueForRow($row, 'contract_shipment'));
        $orderQty = $this->canonicalNumber($row, self::ORDER_QTY_CANONICAL);

        $line->fill([
            'style_no' => $style,
            'gmts_color' => $color,
            'customer_po_no' => $customerPo,
            'buyer_id' => $file->buyer_id,
        ]);
        $line->ship_date = $shipDate?->toDateString() ?: $line->ship_date;

        // The workspace zeroes a repeated order qty on the rows after the first,
        // so keep the largest figure seen for the line.
        if ($orderQty !== null && $orderQty > (float) ($line->order_qty ?? 0)) {
            $line->order_qty = $orderQty;
        }

        $this->linkStyleLibrary($line, $file, $this->source->sourceValueForRow($row, 'season'), $style);
        $line->save();

        $item = BomItem::firstOrNew(['excel_row_id' => $row->id]);
        $createdItem = ! $item->exists;
        $item->order_line_id = $line->id;

        foreach (self::BOM_ITEM_TEXT as $column => $group) {
            $item->{$column} = $text($group);
        }
        foreach (self::BOM_ITEM_NUMBER as $column => $canonical) {
            $item->{$column} = $this->canonicalNumber($row, $canonical);
        }
        $item->save();

        return ['order_line' => $line, 'bom_item' => $item, 'created_line' => $createdLine, 'created_item' => $createdItem];
    }

    /**
     * Link the line to Buyer -> Season -> Mother -> Child in the Style Library,
     * creating what is missing. Needs a buyer on the file and a season in the
     * row; without either the line simply stays unlinked.
     */
    private function linkStyleLibrary(OrderLine $line, ExcelFile $file, ?string $season, string $styleCode): void
    {
        $season = trim((string) $season);

        if (! $file->buyer_id) {
            return;
        }

        // The row's own Season cell is the more specific (a file may mix
        // seasons); the season chosen when the file was uploaded fills the gap.
        if ($season === '' && $file->buyer_season_id) {
            $season = (string) $file->season?->code;
        }

        if ($season === '') {
            return;
        }

        $parsed = StyleCodeParser::parse($styleCode);

        if ($parsed['style_no'] === '') {
            return;
        }

        $seasonModel = BuyerSeason::firstOrCreate(
            ['buyer_id' => $file->buyer_id, 'code' => mb_strtoupper(mb_substr($season, 0, 50))]
        );

        $mother = MotherStyle::firstOrCreate(
            ['buyer_season_id' => $seasonModel->id, 'style_no' => $parsed['style_no']],
            ['buyer_id' => $file->buyer_id]
        );

        $child = $parsed['child'] === null ? null : ChildStyle::firstOrCreate(
            ['mother_style_id' => $mother->id, 'style_no' => $parsed['child']],
            ['kind' => $parsed['kind'] ?? 'variant']
        );

        $line->buyer_season_id = $seasonModel->id;
        $line->mother_style_id = $mother->id;
        $line->child_style_id = $child?->id;
    }

    /** Remove order lines left with no material rows. */
    private function pruneEmptyLines(ExcelFile $file): void
    {
        OrderLine::where('excel_file_id', $file->id)->whereDoesntHave('bomItems')->delete();
    }

    private function canonicalNumber(ExcelRow $row, string $canonical): ?float
    {
        $ids = $this->canonicalIds[$canonical] ??= $this->headers->headerIdsForCanonical($canonical);

        foreach ($row->cells as $cell) {
            if (in_array((int) $cell->header_id, $ids, true) && trim((string) $cell->value) !== '') {
                $number = $this->source->numericValue($cell->value);

                if ($number !== null) {
                    return $number;
                }
            }
        }

        return null;
    }

    private function tally(array &$result, array $synced): void
    {
        $result['rows']++;

        if ($synced['bom_item'] === null) {
            $result['skipped']++;

            return;
        }

        $result['order_lines'] += $synced['created_line'] ? 1 : 0;
        $result['bom_items'] += $synced['created_item'] ? 1 : 0;
    }
}
