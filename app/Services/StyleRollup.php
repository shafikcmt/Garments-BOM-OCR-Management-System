<?php

namespace App\Services;

use App\Models\BomItem;
use App\Models\BuyerSeason;
use App\Models\ExcelCell;
use App\Models\MotherStyle;
use App\Models\OrderLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Where a mother style stands, from order down to material, in one structure.
 *
 * Reads the standard tables (order_lines, bom_items) and, for each material
 * line, the status columns Supply Chain, Commercial and Store fill in the BOM
 * workspace — so the figures here are always the same ones those departments
 * see, never a second copy.
 *
 * STAGE of a material line, the furthest it has reached:
 *   Not ordered -> Ordered (has a PO) -> PI received -> Paid -> Received -> Issued
 *
 * LIGHT, only for material not yet ordered, from the date it had to be ordered by:
 *   red    that date has passed
 *   amber  due within DUE_SOON_DAYS
 *   none   otherwise, or the date is unknown
 */
class StyleRollup
{
    public const DUE_SOON_DAYS = 14;

    /** Status columns read for every material line, by canonical header key. */
    private const FIELDS = [
        'po_no', 'vendor_name', 'materials_ordered', 'pi_status', 'payment_status',
        'receipt_qty', 'bulk_issued', 'order_to_be_placed_by',
    ];

    /** Values that mean "no PO / nothing yet", in any capitalisation. */
    private const EMPTY_WORDS = ['', '-', '0', 'na', 'n/a', 'none', 'nil', 'pending', 'po pending', 'waiting for po', 'wait for po'];

    public function __construct(private HeaderAliasResolver $headers)
    {
    }

    /** @var array<string, list<int>> canonical key => header ids, resolved once per instance */
    private array $headerIdsByCanonical = [];

    /**
     * @return array{
     *   kpis: array<string, int|float>,
     *   lines: Collection,
     *   materials: Collection,
     *   children: Collection
     * }
     */
    public function forMother(MotherStyle $mother, ?int $childId = null, ?Carbon $today = null): array
    {
        $today ??= Carbon::today();

        $lines = OrderLine::where('mother_style_id', $mother->id)
            ->when($childId, fn ($q) => $q->where('child_style_id', $childId))
            ->with(['bomItems', 'childStyle', 'excelFile:id,original_file_name'])
            ->orderBy('customer_po_no')->orderBy('gmts_color')
            ->get();

        $values = $this->statusValues($lines->flatMap->bomItems->pluck('excel_row_id')->all());

        return $this->build($lines, $values, $today) + ['children' => $mother->children()->get()];
    }

    /**
     * Every style of a season, one summary row each, plus the season's totals.
     * One query for the lines and one for the status cells, however many styles.
     *
     * @return array{styles: Collection, totals: array<string, int|float>}
     */
    public function forSeason(BuyerSeason $season, ?Carbon $today = null): array
    {
        $today ??= Carbon::today();

        $mothers = $season->motherStyles()->with('children')->get();

        $lines = OrderLine::whereIn('mother_style_id', $mothers->pluck('id'))
            ->with(['bomItems', 'childStyle'])
            ->get();

        $values = $this->statusValues($lines->flatMap->bomItems->pluck('excel_row_id')->all());
        $byMother = $lines->groupBy('mother_style_id');

        $styles = $mothers->map(function (MotherStyle $mother) use ($byMother, $values, $today) {
            $built = $this->build($byMother->get($mother->id, collect()), $values, $today);

            return ['mother' => $mother, 'kpis' => $built['kpis']];
        })->values();

        return ['styles' => $styles, 'totals' => $this->sumKpis($styles->pluck('kpis'))];
    }

    /**
     * Every order line of the given buyers (null = every buyer), built the same
     * way as the style and season pages — for the Merchandising dashboard. One
     * query for the lines and one for the status cells.
     *
     * @param  list<int>|null  $buyerIds
     * @return array{kpis: array<string, int|float>, lines: Collection, materials: Collection, orderLines: Collection}
     */
    public function forBuyers(?array $buyerIds, ?Carbon $today = null): array
    {
        $today ??= Carbon::today();

        $lines = OrderLine::query()
            ->when($buyerIds !== null, fn ($q) => $q->whereIn('buyer_id', $buyerIds))
            ->with(['bomItems', 'motherStyle:id,style_no,buyer_id', 'buyer:id,buyer_name'])
            ->get();

        $values = $this->statusValues($lines->flatMap->bomItems->pluck('excel_row_id')->all());

        return $this->build($lines, $values, $today) + ['orderLines' => $lines];
    }

    /**
     * Every order line of the given buyers (null = all) and, optionally, one
     * season — for the OCR summaries. $extra names more workspace columns
     * (canonical keys, resolved by header name like the status columns) to
     * carry on each material row under 'extra', e.g. pi_amount or eta.
     *
     * @param  list<int>|null  $buyerIds
     * @param  list<string>  $extra
     * @return array{kpis: array<string, int|float>, lines: Collection, materials: Collection, orderLines: Collection}
     */
    public function forScope(?array $buyerIds, ?int $seasonId = null, array $extra = [], ?Carbon $today = null): array
    {
        $today ??= Carbon::today();

        $lines = OrderLine::query()
            ->when($buyerIds !== null, fn ($q) => $q->whereIn('buyer_id', $buyerIds))
            ->when($seasonId, fn ($q) => $q->where('buyer_season_id', $seasonId))
            ->with(['bomItems', 'childStyle', 'motherStyle:id,style_no,buyer_id', 'buyer:id,buyer_name', 'season:id,code'])
            ->orderBy('style_no')->orderBy('customer_po_no')->orderBy('gmts_color')
            ->get();

        $values = $this->statusValues($lines->flatMap->bomItems->pluck('excel_row_id')->all(), $extra);

        return $this->build($lines, $values, $today, $extra) + ['orderLines' => $lines];
    }

    /**
     * Shared by the style page and the season page, so the two can never disagree.
     *
     * @param  Collection<int, OrderLine>  $lines  with bomItems (and childStyle / excelFile when wanted)
     * @param  array<int, array<string, string>>  $values  status cells by workspace row
     */
    private function build(Collection $lines, array $values, Carbon $today, array $extra = []): array
    {
        $materials = $lines->flatMap(function (OrderLine $line) use ($values, $today, $extra) {
            return $line->bomItems->map(fn (BomItem $item) => $this->materialRow($line, $item, $values[$item->excel_row_id] ?? [], $today, $extra));
        })->values();

        $lineRows = $lines->map(function (OrderLine $line) use ($materials) {
            $own = $materials->where('order_line_id', $line->id);

            return [
                'id' => $line->id,
                'color' => $line->gmts_color,
                'customer_po' => $line->customer_po_no,
                'ship_date' => $line->ship_date,
                'order_qty' => $line->order_qty !== null ? (float) $line->order_qty : null,
                'child' => $line->childStyle?->style_no,
                'file' => $line->relationLoaded('excelFile') ? $line->excelFile?->original_file_name : null,
                'materials' => $own->count(),
                'ordered' => $own->where('is_ordered', true)->count(),
                'received' => $own->where('is_received', true)->count(),
                'late' => $own->where('light', 'red')->count(),
            ];
        })->values();

        return [
            'kpis' => [
                'order_lines' => $lineRows->count(),
                'order_qty' => (float) $lineRows->sum('order_qty'),
                'materials' => $materials->count(),
                'ordered' => $materials->where('is_ordered', true)->count(),
                'pi_received' => $materials->where('is_pi_received', true)->count(),
                'paid' => $materials->where('is_paid', true)->count(),
                'received' => $materials->where('is_received', true)->count(),
                'issued' => $materials->where('is_issued', true)->count(),
                'late' => $materials->where('light', 'red')->count(),
                'due_soon' => $materials->where('light', 'amber')->count(),
            ],
            'lines' => $lineRows,
            'materials' => $materials,
        ];
    }

    /**
     * Add up KPI sets (styles of a season, or seasons of a buyer).
     *
     * @param  Collection<int, array<string, int|float>>  $sets
     * @return array<string, int|float>
     */
    public function sumKpis(Collection $sets): array
    {
        $keys = ['order_lines', 'order_qty', 'materials', 'ordered', 'pi_received', 'paid', 'received', 'issued', 'late', 'due_soon'];

        return collect($keys)->mapWithKeys(fn ($k) => [$k => $sets->sum($k)])->all();
    }

    /**
     * @param  array<string, mixed>  $v  status values of this material's workspace row
     */
    private function materialRow(OrderLine $line, BomItem $item, array $v, Carbon $today, array $extra = []): array
    {
        $po = $this->real($v['po_no'] ?? null);
        $piStatus = trim((string) ($v['pi_status'] ?? ''));
        $paymentStatus = trim((string) ($v['payment_status'] ?? ''));

        $ordered = $po !== null;
        $pi = $ordered && stripos($piStatus, 'received') !== false;
        $paid = $pi && stripos($paymentStatus, 'done') !== false;
        $received = $this->number($v['receipt_qty'] ?? null) > 0;
        $issued = $this->number($v['bulk_issued'] ?? null) > 0;

        return [
            'order_line_id' => $line->id,
            'color' => $line->gmts_color,
            'child' => $line->childStyle?->style_no,
            'description' => $item->material_description,
            'sap_code' => $item->sap_code,
            'material_type' => $item->material_type,
            'bom_qty' => $item->bom_qty !== null ? (float) $item->bom_qty : null,
            'uom' => $item->uom,
            'po_no' => $po,
            'vendor' => $this->real($v['vendor_name'] ?? null),
            'ordered_qty' => $this->number($v['materials_ordered'] ?? null) ?: null,
            'pi_status' => $piStatus !== '' ? $piStatus : null,
            'payment_status' => $paymentStatus !== '' ? $paymentStatus : null,
            'receipt_qty' => $this->number($v['receipt_qty'] ?? null) ?: null,
            'is_ordered' => $ordered,
            'is_pi_received' => $pi,
            'is_paid' => $paid,
            'is_received' => $received,
            'is_issued' => $issued,
            'stage' => match (true) {
                $issued => 'Issued',
                $received => 'Received',
                $paid => 'Paid',
                $pi => 'PI received',
                $ordered => 'Ordered',
                default => 'Not ordered',
            },
            'light' => $ordered ? null : $this->light($v['order_to_be_placed_by'] ?? null, $today),
            'excel_row_id' => $item->excel_row_id,
            'material_color' => $item->material_color,
            // Extra workspace columns asked for by the caller (forScope), raw.
            'extra' => array_intersect_key($v, array_flip($extra)),
        ];
    }

    private function light(mixed $orderBy, Carbon $today): ?string
    {
        $date = $this->date($orderBy);

        if (! $date) {
            return null;
        }

        return match (true) {
            $date->lt($today) => 'red',
            $date->lte($today->copy()->addDays(self::DUE_SOON_DAYS)) => 'amber',
            default => null,
        };
    }

    /**
     * The status cells of the given workspace rows, in one query.
     *
     * @param  list<int>  $rowIds
     * @return array<int, array<string, string>>  row id => canonical key => value
     */
    private function statusValues(array $rowIds, array $extra = []): array
    {
        if ($rowIds === []) {
            return [];
        }

        $keyByHeader = [];
        foreach (array_unique(array_merge(self::FIELDS, $extra)) as $canonical) {
            $this->headerIdsByCanonical[$canonical] ??= $this->headers->headerIdsForCanonical($canonical);
            foreach ($this->headerIdsByCanonical[$canonical] as $headerId) {
                $keyByHeader[$headerId] ??= $canonical;
            }
        }

        if ($keyByHeader === []) {
            return [];
        }

        $out = [];

        // In chunks, so a whole buyer's rows stay under the database's limit
        // on bound parameters.
        foreach (array_chunk(array_values(array_unique($rowIds)), 900) as $chunk) {
            ExcelCell::whereIn('row_id', $chunk)->whereIn('header_id', array_keys($keyByHeader))
                ->whereNotNull('value')->get(['row_id', 'header_id', 'value'])
                ->each(function ($cell) use (&$out, $keyByHeader) {
                    $key = $keyByHeader[$cell->header_id];

                    // First non-blank value wins when two headers answer to one key.
                    if (trim((string) $cell->value) !== '' && ! isset($out[$cell->row_id][$key])) {
                        $out[$cell->row_id][$key] = (string) $cell->value;
                    }
                });
        }

        return $out;
    }

    private function real(mixed $value): ?string
    {
        $text = trim((string) $value);

        return in_array(mb_strtolower($text), self::EMPTY_WORDS, true) ? null : $text;
    }

    private function number(mixed $value): float
    {
        $clean = str_replace([',', ' '], '', trim((string) $value));

        return is_numeric($clean) ? (float) $clean : 0.0;
    }

    private function date(mixed $value): ?Carbon
    {
        $text = trim((string) $value);

        if ($text === '') {
            return null;
        }

        try {
            return Carbon::parse($text)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}
