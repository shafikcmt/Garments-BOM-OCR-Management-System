<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * The Stock Summary: one card with two views of the same period.
 *
 *   Purchase View — requested, current stock, purchased, to purchase, per
 *                   item: RequisitionFulfilmentService, unchanged.
 *   Issue View    — the "Requirement against consume qty" columns, per
 *                   section and item: RequirementVsIssueService, unchanged.
 *
 * NO FORMULAS OF ITS OWN. It runs the two existing services on the same
 * filters and puts their results side by side, so every figure matches the
 * Requirement vs Issue tab and the old Stock Summary for the same filters.
 *
 * Filters are the Requirement vs Issue filters, read from the `sum[...]`
 * query key so they never collide with the requisition list's own filters on
 * the same page.
 *
 * The Purchase View ignores Department and Section — purchases and stock are
 * company-wide, not kept per section — while status, category and search
 * narrow it to the items they select. Each view's cards are its own totals.
 */
class StockSummaryService
{
    public const VIEW_PURCHASE = 'purchase';
    public const VIEW_ISSUE = 'issue';

    public const PER_PAGE = 25;

    public function __construct(
        private readonly RequirementVsIssueService $issue,
        private readonly RequisitionFulfilmentService $fulfilment,
    ) {
    }

    /**
     * @param  array<string, mixed>  $input  the `sum` query array
     * @return array<string, mixed>
     */
    public function build(?User $user, array $input): array
    {
        $options = $this->issue->options();
        $filters = $this->issue->filters($input, $options);
        $issue = $this->issue->build($filters, $options);

        // The Purchase View's period, in the form the fulfilment service
        // takes: one month exactly as the old card asked for it, a range as
        // from/to dates.
        $period = $filters['from']->equalTo($filters['to'])
            ? ['month' => $filters['from']->format('Y-m')]
            : ['from' => $filters['from']->toDateString(), 'to' => $filters['to']->copy()->endOfMonth()->toDateString()];

        // Purchases and stock are company-wide, so the Purchase View ignores
        // Department and Section. They stay in the URL for the Issue View.
        // Status and category still narrow it, to the items the Issue View
        // would show without those two filters.
        $purchaseFilters = ['departments' => [], 'sections' => []] + $filters;
        $narrowingIssue = ($filters['departments'] === [] && $filters['sections'] === [])
            ? $issue
            : $this->issue->build($purchaseFilters, $options);

        $purchaseRows = $this->purchaseRows($this->fulfilment->summary($user, $period, companyWide: true)['rows'], $purchaseFilters, $narrowingIssue['rows']);
        $purchaseTotals = [
            'requested' => (float) $purchaseRows->sum('requested'),
            'stock_in_hand' => (float) $purchaseRows->sum('stock_in_hand'),
            'purchased' => (float) $purchaseRows->sum('purchased'),
            'pending' => (float) $purchaseRows->sum('pending'),
        ];

        $view = ($input['view'] ?? null) === self::VIEW_ISSUE ? self::VIEW_ISSUE : self::VIEW_PURCHASE;

        return [
            'view' => $view,
            'filters' => $filters,
            'purchaseFilters' => $purchaseFilters,
            'options' => $options,
            'sectionOptions' => $this->issue->sectionsFor($options, $filters['departments']),
            'period' => $this->issue->periodLabel($filters),
            // What the export's title says was filtered — for the view exported.
            'title' => $this->issue->title($view === self::VIEW_ISSUE ? $filters : $purchaseFilters, $options),
            'issue' => $issue,
            'purchase' => ['rows' => $purchaseRows, 'totals' => $purchaseTotals],
            // Each view's cards are that view's own totals row, so the two can
            // never disagree. Purchase rows carry no rate, so there is no
            // Requested Value on the Purchase View.
            'cards' => [
                self::VIEW_PURCHASE => [
                    ['label' => 'Requested', 'value' => $purchaseTotals['requested'], 'format' => 'qty', 'tone' => 'primary', 'icon' => 'bi-clipboard-check'],
                    ['label' => 'Current Stock', 'value' => $purchaseTotals['stock_in_hand'], 'format' => 'qty', 'tone' => 'secondary', 'icon' => 'bi-box-seam'],
                    ['label' => 'Purchased', 'value' => $purchaseTotals['purchased'], 'format' => 'qty', 'tone' => 'success', 'icon' => 'bi-truck'],
                    ['label' => 'To Purchase', 'value' => $purchaseTotals['pending'], 'format' => 'qty', 'tone' => 'warning', 'icon' => 'bi-hourglass-split'],
                ],
                self::VIEW_ISSUE => [
                    ['label' => 'Requested Qty', 'value' => $issue['summary']['requested_qty'], 'format' => 'qty', 'tone' => 'primary', 'icon' => 'bi-clipboard-check'],
                    ['label' => 'Requested Value', 'value' => $issue['summary']['requested_value'], 'format' => 'money', 'tone' => 'primary', 'icon' => 'bi-cash-stack'],
                    ['label' => 'Issued Qty', 'value' => $issue['summary']['issued_qty'], 'format' => 'qty', 'tone' => 'success', 'icon' => 'bi-box-arrow-right'],
                    ['label' => 'Issued Value', 'value' => $issue['summary']['issued_value'], 'format' => 'money', 'tone' => 'success', 'icon' => 'bi-cash'],
                    ['label' => 'Issued %', 'value' => $issue['summary']['pct'], 'format' => 'pct', 'tone' => 'info', 'icon' => 'bi-percent'],
                    ['label' => 'Balance Value', 'value' => $issue['summary']['balance_value'], 'format' => 'money', 'tone' => 'warning', 'icon' => 'bi-hourglass-split'],
                ],
            ],
        ];
    }

    /**
     * The Purchase View's rows: the fulfilment rows, narrowed by the Purchase
     * View's filters (no department or section). Search matches the item
     * name; category and status keep only the items the Issue View shows
     * under those same filters.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function purchaseRows(Collection $rows, array $filters, Collection $issueRows): Collection
    {
        $narrowed = $filters['departments'] !== [] || $filters['sections'] !== []
            || $filters['categories'] !== [] || $filters['statuses'] !== [];

        if ($narrowed) {
            $items = $issueRows->pluck('item_id')->filter()->unique()->flip();
            $rows = $rows->filter(fn ($r) => $r['item_id'] !== null && $items->has($r['item_id']));
        }

        if ($filters['search'] !== '') {
            $needle = mb_strtolower($filters['search']);
            $rows = $rows->filter(fn ($r) => str_contains(mb_strtolower((string) $r['item_name']), $needle));
        }

        // Highest To Purchase first — the items someone has to chase.
        return $rows->sortByDesc('pending')->values();
    }

    /** One page of a view's rows; the totals above it still cover every row. */
    public function page(Collection $rows, int $page, string $pageName, string $path, array $query): LengthAwarePaginator
    {
        $page = max(1, $page);

        return (new LengthAwarePaginator(
            $rows->forPage($page, self::PER_PAGE)->values(),
            $rows->count(),
            self::PER_PAGE,
            $page,
            ['path' => $path, 'pageName' => $pageName, 'query' => $query],
        ));
    }

    /**
     * The filters as they go back into a URL: under `sum[...]` for this card,
     * or bare for the Requirement vs Issue tab ("Full Report").
     *
     * @return array<string, mixed>
     */
    public static function query(array $filters, ?string $view = null): array
    {
        return array_filter([
            'from' => $filters['from']->format('Y-m'),
            'to' => $filters['to']->format('Y-m'),
            'departments' => $filters['departments'],
            'sections' => $filters['sections'],
            'categories' => $filters['categories'],
            'statuses' => $filters['statuses'],
            'requisition_no' => $filters['requisition_no'],
            'search' => $filters['search'],
            'view' => $view,
        ], fn ($v) => $v !== '' && $v !== [] && $v !== null);
    }
}
