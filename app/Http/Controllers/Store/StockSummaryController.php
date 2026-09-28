<?php

namespace App\Http\Controllers\Store;

use App\Exports\RequirementVsIssueExport;
use App\Exports\StockSummaryPurchaseExport;
use App\Http\Controllers\Controller;
use App\Services\RequirementVsIssueService;
use App\Services\StockSummaryService;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Excel / PDF of the Stock Summary card, in the view it is showing and under
 * its filters. The Issue View is the Requirement vs Issue export itself; the
 * Purchase View has its own one-sheet layout. Same access as Requirement vs
 * Issue: store.requirement_issue.view.
 */
class StockSummaryController extends Controller
{
    public function __construct(private readonly StockSummaryService $summary)
    {
    }

    public function excel(Request $request)
    {
        $data = $this->build($request);

        return $data['view'] === StockSummaryService::VIEW_ISSUE
            ? Excel::download(new RequirementVsIssueExport($this->issueData($data)), $this->filename($data).'.xlsx')
            : Excel::download(new StockSummaryPurchaseExport($data), $this->filename($data).'.xlsx');
    }

    public function pdf(Request $request)
    {
        $data = $this->build($request);

        $pdf = $data['view'] === StockSummaryService::VIEW_ISSUE
            ? \Barryvdh\DomPDF\Facade\Pdf::loadView('store.stock.requisitions.requirement-vs-issue-pdf', $this->issueData($data))
            : \Barryvdh\DomPDF\Facade\Pdf::loadView('store.stock.requisitions.stock-summary-purchase-pdf', $data);

        return $pdf->setPaper('a4', 'landscape')->download($this->filename($data).'.pdf');
    }

    /** @return array<string, mixed> */
    private function build(Request $request): array
    {
        abort_unless(RequirementVsIssueService::canView($request->user()), 403,
            'You do not have access to the Stock Summary. Ask Admin.');

        return $this->summary->build($request->user(), (array) $request->query('sum', []));
    }

    /** The Requirement vs Issue export's own payload, from the summary. */
    private function issueData(array $data): array
    {
        return $data['issue'] + [
            'filters' => $data['filters'],
            'options' => $data['options'],
            'title' => $data['title'],
            'period' => $data['period'],
        ];
    }

    private function filename(array $data): string
    {
        return 'Stock-Summary-'.($data['view'] === StockSummaryService::VIEW_ISSUE ? 'Issue' : 'Purchase')
            .'-'.$data['filters']['from']->format('Y-m')
            .($data['filters']['from']->equalTo($data['filters']['to']) ? '' : '-to-'.$data['filters']['to']->format('Y-m'));
    }
}
