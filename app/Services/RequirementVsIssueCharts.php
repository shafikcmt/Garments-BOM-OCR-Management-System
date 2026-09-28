<?php

namespace App\Services;

use Illuminate\Support\Collection;

/**
 * The figures the Requirement vs Issue charts draw — built from the rows and
 * totals the table already shows, never queried again, so a chart can never
 * disagree with the table under the same filters.
 *
 * Screen only: the PDF and Excel exports do not use this.
 */
class RequirementVsIssueCharts
{
    public const TOP = 10;

    /**
     * @param  Collection<int, array>  $rows      the table's rows (all filtered, not one page)
     * @param  array                   $summary   the table's totals row
     * @param  Collection<int, array>  $sections  RequirementVsIssueService::build()['sections']
     * @return array{empty: bool, pct: ?float, statuses: list<array>, sections: list<array>, items: list<array>, section_max: float, item_max: float}
     */
    public static function issue(Collection $rows, array $summary, Collection $sections): array
    {
        $labels = RequirementVsIssueService::statusLabels();

        // The status split of exactly the rows in the table.
        $statuses = collect($labels)
            ->map(fn ($label, $key) => ['key' => $key, 'label' => $label, 'count' => $rows->where('status', $key)->count()])
            ->values()->all();

        // Requested vs Issued value by section: the top ten by requested
        // value, the rest folded into "Others" so the totals still add up.
        $ranked = $sections->sortByDesc(fn ($s) => $s['requested_value'])->values();
        $top = $ranked->take(self::TOP)->map(fn ($s) => [
            'section_id' => $s['section_id'],
            'label' => $s['section_name'],
            'requested' => (float) $s['requested_value'],
            'issued' => (float) $s['issued_value'],
        ]);
        $rest = $ranked->slice(self::TOP);
        if ($rest->isNotEmpty()) {
            $top->push([
                'section_id' => null,
                'label' => 'Others ('.$rest->count().')',
                'requested' => (float) $rest->sum('requested_value'),
                'issued' => (float) $rest->sum('issued_value'),
                'others' => true,
            ]);
        }

        // The ten largest outstanding balances. Over-issued rows (negative
        // balance) and rows with no rate (no value) are left out.
        $items = $rows
            ->filter(fn ($r) => $r['status'] !== RequirementVsIssueService::STATUS_OVER
                && $r['balance_value'] !== null && $r['balance_value'] > 0)
            ->sortByDesc('balance_value')
            ->take(self::TOP)
            ->map(fn ($r) => [
                'label' => $r['item_name'],
                'section' => $r['section_name'],
                'value' => (float) $r['balance_value'],
            ])->values()->all();

        return [
            'empty' => $rows->isEmpty(),
            'pct' => $summary['pct'],
            'statuses' => $statuses,
            'sections' => $top->values()->all(),
            'items' => $items,
            'section_max' => (float) ($top->max(fn ($s) => max($s['requested'], $s['issued'])) ?? 0),
            'item_max' => (float) (collect($items)->max('value') ?? 0),
        ];
    }

    /**
     * Requested vs Purchased vs To Purchase for the ten items with the most
     * still to purchase.
     *
     * @param  Collection<int, array>  $rows  the Purchase View's rows (all filtered)
     * @return array{empty: bool, items: list<array>, max: float}
     */
    public static function purchase(Collection $rows): array
    {
        $items = $rows->filter(fn ($r) => $r['pending'] > 0)
            ->sortByDesc('pending')
            ->take(self::TOP)
            ->map(fn ($r) => [
                'label' => $r['item_name'],
                'requested' => (float) $r['requested'],
                'purchased' => (float) $r['purchased'],
                'pending' => (float) $r['pending'],
            ])->values()->all();

        return [
            'empty' => $items === [],
            'items' => $items,
            'max' => (float) (collect($items)->max(fn ($i) => max($i['requested'], $i['purchased'], $i['pending'])) ?? 0),
        ];
    }
}
