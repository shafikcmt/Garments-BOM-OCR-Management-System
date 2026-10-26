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

    /** @var array<int, string>|null header id => canonical key, resolved once per instance */
    private ?array $keyByHeader = null;

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
     * Shared by the style page and the season page, so the two can never disagree.
     *
     * @param  Collection<int, OrderLine>  $lines  with bomItems (and childStyle / excelFile when wanted)
     * @param  array<int, array<string, string>>  $values  status cells by workspace row
     */
    private function build(Collection $lines, array $values, Carbon $today): array
    {
        $materials = $lines->flatMap(function (OrderLine $line) use ($values, $today) {
            return $line->bomItems->map(fn (BomItem $item) => $this->materialRow($line, $item, $values[$item->excel_row_id] ?? [], $today));
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
    private function materialRow(OrderLine $line, BomItem $item, array $v, Carbon $today): array
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
    private function statusValues(array $rowIds): array
    {
        if ($rowIds === []) {
            return [];
        }

        if ($this->keyByHeader === null) {
            $this->keyByHeader = [];
            foreach (self::FIELDS as $canonical) {
                foreach ($this->headers->headerIdsForCanonical($canonical) as $headerId) {
                    $this->keyByHeader[$headerId] ??= $canonical;
                }
            }
        }
        $keyByHeader = $this->keyByHeader;

        if ($keyByHeader === []) {
            return [];
        }

        $out = [];

        ExcelCell::whereIn('row_id', $rowIds)->whereIn('header_id', array_keys($keyByHeader))
            ->whereNotNull('value')->get(['row_id', 'header_id', 'value'])
            ->each(function ($cell) use (&$out, $keyByHeader) {
                $key = $keyByHeader[$cell->header_id];

                // First non-blank value wins when two headers answer to one key.
                if (trim((string) $cell->value) !== '' && ! isset($out[$cell->row_id][$key])) {
                    $out[$cell->row_id][$key] = (string) $cell->value;
                }
            });

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
