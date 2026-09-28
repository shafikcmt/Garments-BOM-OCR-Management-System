<?php

namespace App\Http\Controllers\Store;

use App\Exports\RequirementVsIssueExport;
use App\Http\Controllers\Controller;
use App\Models\PurchaseRequisition;
use App\Services\RequirementVsIssueCharts;
use App\Services\RequirementVsIssueService;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Requirement vs Issue — requested against issued,
 * per section and item. Read only.
 *
 * Screen, Excel and PDF share build(), so the three always show the same
 * rows under the same filters.
 */
class RequirementVsIssueController extends Controller
{
    public function __construct(private readonly RequirementVsIssueService $report)
    {
    }

    public function index(Request $request)
    {
        $user = $request->user();

        return view('store.stock.requisitions.requirement-vs-issue', $this->build($request) + [
            'awaitingCount' => PurchaseRequisition::awaitingStoreCountFor($user),
            'departmentReviewCount' => PurchaseRequisition::departmentReviewCountFor($user),
        ]);
    }

    public function excel(Request $request)
    {
        $data = $this->build($request);

        return Excel::download(new RequirementVsIssueExport($data), $this->filename($data).'.xlsx');
    }

    public function pdf(Request $request)
    {
        $data = $this->build($request);

        return \Barryvdh\DomPDF\Facade\Pdf::loadView('store.stock.requisitions.requirement-vs-issue-pdf', $data)
            ->setPaper('a4', 'landscape')
            ->download($this->filename($data).'.pdf');
    }

    /** @return array<string, mixed> */
    private function build(Request $request): array
    {
        abort_unless(RequirementVsIssueService::canView($request->user()), 403,
            'You do not have access to Requirement vs Issue. Ask Admin.');

        $options = $this->report->options();
        $filters = $this->report->filters($request->query(), $options);
        $result = $this->report->build($filters, $options);

        return $result + [
            'filters' => $filters,
            'options' => $options,
            'sectionOptions' => $this->report->sectionsFor($options, $filters['departments']),
            'statusLabels' => RequirementVsIssueService::statusLabels(),
            'title' => $this->report->title($filters, $options),
            'period' => $this->report->periodLabel($filters),
            'query' => $this->query($filters),
            // Screen-only charts, from the same rows and totals as the table.
            'charts' => RequirementVsIssueCharts::issue($result['rows'], $result['summary'], $result['sections']),
        ];
    }

    /**
     * The active filters as a query string array — for the export links,
     * the sort links and the removable chips, so each keeps the rest.
     *
     * @return array<string, mixed>
     */
    private function query(array $filters): array
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
            'sort' => $filters['sort'],
            'dir' => $filters['dir'],
        ], fn ($v) => $v !== '' && $v !== []);
    }

    private function filename(array $data): string
    {
        return 'Requirement-vs-Issue-'.$data['filters']['from']->format('Y-m')
            .($data['filters']['from']->equalTo($data['filters']['to']) ? '' : '-to-'.$data['filters']['to']->format('Y-m'));
    }
}
