<?php

namespace App\Services;

use App\Models\BuyerImportProfile;
use App\Models\ExcelCell;
use App\Models\ExcelHeader;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The OCR summary sheets the teams used to build by hand from the OCR
 * "Database" sheet: Contract, Material, Payment and Clearance summaries.
 *
 * Everything comes from StyleRollup (order lines, BOM items and the workspace
 * status columns), so a figure here is the same one Style Status and the
 * dashboard show. Columns are found by header name through
 * HeaderAliasResolver, never by a buyer: a buyer whose sheet has no ETA column
 * simply shows blanks in the Clearance summary.
 */
class OcrSummary
{
    /** Extra workspace columns the summaries read, by canonical key. */
    public const EXTRA = [
        'pi_no', 'pi_amount', 'payment_reqd_date', 'pmt_doc_no',
        'ship_mode', 'forwarder', 'bl_awb_no', 'committed_eta', 'eta', 'ata',
        'arrival_status', 'committed_inhouse', 'actual_inhouse',
    ];

    public const TABS = [
        'contract' => 'Contract Summary',
        'material' => 'Material Summary',
        'payment' => 'Payment Summary',
        'clearance' => 'Clearance Summary',
    ];

    public function __construct(private StyleRollup $rollup)
    {
    }

    /**
     * @param  list<int>|null  $buyerIds  null = every buyer
     * @return array{kpis: array<string, int|float>, materials: Collection, contract: Collection, material: Collection, payment: Collection, clearance: Collection}
     */
    public function build(?array $buyerIds, ?int $seasonId = null, ?Carbon $today = null): array
    {
        $data = $this->rollup->forScope($buyerIds, $seasonId, self::EXTRA, $today);
        $materials = $data['materials'];
        $lines = $data['orderLines']->keyBy('id');

        return [
            'kpis' => $data['kpis'],
            'materials' => $materials,
            'contract' => $this->contract($lines, $materials),
            'material' => $this->material($materials),
            'payment' => $this->payment($materials),
            'clearance' => $this->clearance($materials),
        ];
    }

    /** One row per order line (style, colour, contract / customer PO). */
    private function contract(Collection $lines, Collection $materials): Collection
    {
        $byLine = $materials->groupBy('order_line_id');

        return $lines->map(function ($line) use ($byLine) {
            $m = $byLine->get($line->id, collect());
            $count = $m->count();

            return [
                'buyer' => $line->buyer?->buyer_name,
                'season' => $line->season?->code,
                'style' => $line->motherStyle?->style_no ?? $line->style_no,
                'child' => $line->childStyle?->style_no,
                'color' => $line->gmts_color,
                'contract' => $line->customer_po_no,
                'ship_date' => $line->ship_date,
                'order_qty' => $line->order_qty !== null ? (float) $line->order_qty : null,
                'materials' => $count,
                'ordered_pct' => $this->pct($m->where('is_ordered', true)->count(), $count),
                'pi_pct' => $this->pct($m->where('is_pi_received', true)->count(), $count),
                'paid_pct' => $this->pct($m->where('is_paid', true)->count(), $count),
                'received_pct' => $this->pct($m->where('is_received', true)->count(), $count),
                'late' => $m->where('light', 'red')->count(),
            ];
        })->sortBy([['ship_date', 'asc'], ['style', 'asc']])->values();
    }

    /** One row per material (description, colour, unit) across the lines in scope. */
    private function material(Collection $materials): Collection
    {
        return $materials
            ->groupBy(fn ($m) => mb_strtolower(trim(($m['description'] ?? '') . '|' . ($m['material_color'] ?? '') . '|' . ($m['uom'] ?? ''))))
            ->map(function (Collection $group) {
                $first = $group->first();
                $bom = (float) $group->sum('bom_qty');
                $ordered = (float) $group->sum('ordered_qty');
                $received = (float) $group->sum('receipt_qty');

                // (Short) / excess only over lines that carry an ordered
                // quantity; a line not ordered yet is not a shortage here,
                // it is counted in "not ordered".
                $withQty = $group->filter(fn ($m) => $m['ordered_qty'] !== null);
                $shortExcess = $withQty->isEmpty() ? null : (float) $withQty->sum('ordered_qty') - (float) $withQty->sum('bom_qty');

                return [
                    'type' => $first['material_type'],
                    'description' => $first['description'],
                    'sap_code' => $group->pluck('sap_code')->filter()->first(),
                    'material_color' => $first['material_color'],
                    'uom' => $first['uom'],
                    'lines' => $group->count(),
                    'bom_qty' => $bom,
                    'ordered_qty' => $ordered,
                    'short_excess' => $shortExcess,
                    'received_qty' => $received,
                    'received_pct' => $this->pct($received, $bom),
                    'vendors' => $group->pluck('vendor')->filter()->unique()->values()->implode(', '),
                    'not_ordered' => $group->where('is_ordered', false)->count(),
                ];
            })
            ->sortBy([['type', 'asc'], ['description', 'asc']])
            ->values();
    }

    /** One row per supplier: PI value, paid, pending and the next payment date. */
    private function payment(Collection $materials): Collection
    {
        // A PI is usually repeated on every line it covers; count its amount
        // once per PI number (lines without a PI number count line by line).
        return $materials->where('is_ordered', true)
            ->groupBy(fn ($m) => $m['vendor'] ?? 'Supplier not set')
            ->map(function (Collection $group, string $vendor) {
                $pis = $group->groupBy(fn ($m, $i) => $this->text($m['extra']['pi_no'] ?? null) ?? 'line-' . $i);
                $amount = 0.0;
                $paid = 0.0;
                foreach ($pis as $pi) {
                    $value = (float) $pi->max(fn ($m) => $this->number($m['extra']['pi_amount'] ?? null));
                    $amount += $value;
                    if ($pi->every(fn ($m) => $m['is_paid'])) {
                        $paid += $value;
                    }
                }

                $nextDue = $group->where('is_paid', false)
                    ->map(fn ($m) => $this->date($m['extra']['payment_reqd_date'] ?? null))
                    ->filter()->sort()->first();

                return [
                    'vendor' => $vendor,
                    'pos' => $group->pluck('po_no')->filter()->unique()->count(),
                    'pis' => $group->map(fn ($m) => $this->text($m['extra']['pi_no'] ?? null))->filter()->unique()->count(),
                    'pi_pending' => $group->where('is_pi_received', false)->count(),
                    'pi_amount' => $amount,
                    'paid_amount' => $paid,
                    'pending_amount' => $amount - $paid,
                    'unpaid_lines' => $group->where('is_pi_received', true)->where('is_paid', false)->count(),
                    'next_due' => $nextDue,
                ];
            })
            ->sortByDesc('pending_amount')
            ->values();
    }

    /** Ordered material not yet received, grouped by BL / AWB (or by ETA when there is none). */
    private function clearance(Collection $materials): Collection
    {
        return $materials->where('is_ordered', true)->where('is_received', false)
            ->groupBy(function ($m) {
                $bl = $this->text($m['extra']['bl_awb_no'] ?? null);

                return $bl ?? 'No BL / AWB yet';
            })
            ->map(function (Collection $group, string $bl) {
                $eta = $group->map(fn ($m) => $this->date($m['extra']['eta'] ?? null)
                    ?? $this->date($m['extra']['committed_eta'] ?? null))->filter()->sort()->first();
                $inhouse = $group->map(fn ($m) => $this->date($m['extra']['committed_inhouse'] ?? null))->filter()->sort()->first();

                return [
                    'bl' => $bl,
                    'ship_mode' => $group->map(fn ($m) => $this->text($m['extra']['ship_mode'] ?? null))->filter()->unique()->implode(', '),
                    'forwarder' => $group->map(fn ($m) => $this->text($m['extra']['forwarder'] ?? null))->filter()->unique()->implode(', '),
                    'vendors' => $group->pluck('vendor')->filter()->unique()->implode(', '),
                    'pos' => $group->pluck('po_no')->filter()->unique()->count(),
                    'lines' => $group->count(),
                    'eta' => $eta,
                    'committed_inhouse' => $inhouse,
                    'arrival_status' => $group->map(fn ($m) => $this->text($m['extra']['arrival_status'] ?? null))->filter()->unique()->implode(', '),
                ];
            })
            // Shipments with a date first, soonest first; "no BL yet" last.
            ->sortBy(fn ($r) => [$r['bl'] === 'No BL / AWB yet' ? 1 : 0, $r['eta']?->timestamp ?? PHP_INT_MAX])
            ->values();
    }

    /**
     * The OCR "Database" sheet: one row per material line in scope, every
     * workspace column the user may see. When one buyer is chosen and that
     * buyer has a saved import profile, its columns come first, in the
     * profile's order and under the buyer's own titles, so the file looks like
     * the one the buyer sent; the other columns follow under system names.
     *
     * @return array{headings: list<string>, rows: list<list<string|null>>}
     */
    public function databaseSheet(Collection $materials, ?int $buyerId, User $user): array
    {
        $roleIds = $user->roles->pluck('id');
        $isAdmin = $user->hasRole('admin') || $user->hasRole('management');

        $headers = ExcelHeader::with('editorRoles:id')->where('is_active', true)->orderBy('position')->get()
            ->filter(fn (ExcelHeader $h) => $isAdmin || $h->can_view_all || $h->isEditableByRoleIds($roleIds))
            ->keyBy('id');

        $columns = collect();
        if ($buyerId) {
            $profile = BuyerImportProfile::with('columns')->where('buyer_id', $buyerId)->latest('id')->first();
            foreach ($profile?->columns->sortBy('id') ?? [] as $col) {
                if ($col->excel_header_id && $headers->has($col->excel_header_id) && ! $columns->has($col->excel_header_id)) {
                    $columns->put($col->excel_header_id, $col->source_header ?: $headers[$col->excel_header_id]->header_name);
                }
            }
        }
        foreach ($headers as $id => $header) {
            if (! $columns->has($id)) {
                $columns->put($id, $header->header_name);
            }
        }

        $rowIds = $materials->pluck('excel_row_id')->filter()->unique()->values()->all();
        $cells = [];
        foreach (array_chunk($rowIds, 900) as $chunk) {
            ExcelCell::whereIn('row_id', $chunk)->whereIn('header_id', $columns->keys()->all())
                ->get(['row_id', 'header_id', 'value'])
                ->each(function ($c) use (&$cells) {
                    $cells[$c->row_id][$c->header_id] = $c->value;
                });
        }

        $ids = $columns->keys()->all();
        $rows = array_map(fn ($rowId) => array_map(fn ($id) => $cells[$rowId][$id] ?? null, $ids), $rowIds);

        return ['headings' => $columns->values()->all(), 'rows' => $rows];
    }

    private function pct(float|int $part, float|int $of): int
    {
        return $of > 0 ? (int) round($part / $of * 100) : 0;
    }

    private function text(mixed $value): ?string
    {
        $text = trim((string) $value);

        return in_array(mb_strtolower($text), ['', '-', 'n/a', 'na'], true) ? null : $text;
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
