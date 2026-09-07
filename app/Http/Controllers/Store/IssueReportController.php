<?php

namespace App\Http\Controllers\Store;

use App\Exports\IssueReportExport;
use App\Http\Controllers\Controller;
use App\Models\IndentPerson;
use App\Models\IndentSection;
use App\Models\ItemCategory;
use App\Models\StockItem;
use App\Models\StockIssue;
use App\Services\IssueReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

/**
 * General Stock (module A) — the Issues (Consumption) report.
 *
 * Read-only, and entirely separate from the Record Issue screen: this compiles
 * what that screen recorded, it never writes. In particular it does not go near
 * the stock check that blocks an over-issue — that guards a write, and there is
 * no write here.
 *
 * Built to the same shape as MonthlyRequisitionReportController and its
 * Receiving twin — one build() feeding screen, PDF and Excel.
 */
class IssueReportController extends Controller
{
    public function __construct(private readonly IssueReportService $report)
    {
    }

    public function index(Request $request)
    {
        return view('store.stock.issue-report', $this->build($request));
    }

    /**
     * The report as a PDF.
     *
     * `?preview=1` streams it inline instead of downloading, which is what the
     * Print button opens — see the note on ReceivingReportController::pdf().
     */
    public function pdf(Request $request)
    {
        $data = $this->build($request);

        // Landscape A4: nine columns including item and section names.
        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('store.stock.issue-report-pdf', $data)
            ->setPaper('a4', 'landscape');

        $filename = $this->filename($data).'.pdf';

        return $request->boolean('preview')
            ? $pdf->stream($filename)
            : $pdf->download($filename);
    }

    public function excel(Request $request)
    {
        $data = $this->build($request);

        return Excel::download(new IssueReportExport($data), $this->filename($data).'.xlsx');
    }

    /**
     * Shared payload for screen, PDF and Excel, so the three can never disagree.
     *
     * @return array<string, mixed>
     */
    private function build(Request $request): array
    {
        $filters = $request->validate([
            'month' => ['nullable', 'string'],
            'requisition_no' => ['nullable', 'string', 'max:100'],
            'item' => ['nullable', 'integer', 'exists:stock_items,id'],
            'section' => ['nullable', 'integer', 'exists:indent_sections,id'],
            'person' => ['nullable', 'integer', 'exists:indent_persons,id'],
            'category' => ['nullable', 'integer', 'exists:item_categories,id'],
            'type' => ['nullable', 'string', 'max:50'],
            'search' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        // The report itself now comes from the service, so the combined Store
        // workbook reads it from the same place this screen does. Only the
        // filter form's dropdown masters are added here: they are furniture,
        // not part of what the report says, and the PDF and Excel never use
        // them.
        return $this->report->reportPayload($filters) + [
            // Filter dropdowns, the same masters the Record Issue screen uses.
            'items' => StockItem::orderBy('name')->get(['id', 'name']),
            'sections' => IndentSection::selectable()->get(['id', 'name']),
            'persons' => IndentPerson::selectable()->get(['id', 'name']),
            'categories' => ItemCategory::selectable()->get(['id', 'name']),
            'requisitionTypes' => StockIssue::REQUISITION_TYPES,
        ];
    }

    /** @param array<string, mixed> $data */
    private function filename(array $data): string
    {
        return 'Issue-Report-'.Str::slug($data['periodLabel']);
    }
}
