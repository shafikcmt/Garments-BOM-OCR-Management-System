<?php

namespace App\Http\Controllers\Store;

use App\Exports\ReceivingReportExport;
use App\Http\Controllers\Controller;
use App\Services\ReceivingReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

/**
 * General Stock (module A) — the Receiving report.
 *
 * Read-only, and entirely separate from the Record Receiving screen: this
 * compiles what that screen recorded, it never writes.
 *
 * Built to the same shape as MonthlyRequisitionReportController — one build()
 * feeding screen, PDF and Excel — so the three can never drift apart and the
 * two reports behave identically for whoever uses them.
 */
class ReceivingReportController extends Controller
{
    public function __construct(private readonly ReceivingReportService $report)
    {
    }

    public function index(Request $request)
    {
        return view('store.stock.receiving-report', $this->build($request));
    }

    /**
     * The report as a PDF.
     *
     * `?preview=1` streams it inline instead of downloading, which is what the
     * Print button opens: printing the PDF itself rather than the HTML page
     * means what comes out of the printer is the same document that comes out
     * of the Download button, with the same margins and page breaks. There is
     * no second layout to keep in step.
     */
    public function pdf(Request $request)
    {
        $data = $this->build($request);

        // Landscape A4: the delivery table plus its item lines will not fit
        // portrait without squeezing the item names.
        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('store.stock.receiving-report-pdf', $data)
            ->setPaper('a4', 'landscape');

        $filename = $this->filename($data).'.pdf';

        return $request->boolean('preview')
            ? $pdf->stream($filename)
            : $pdf->download($filename);
    }

    public function excel(Request $request)
    {
        $data = $this->build($request);

        return Excel::download(new ReceivingReportExport($data), $this->filename($data).'.xlsx');
    }

    /**
     * Shared payload for screen, PDF and Excel, so the three can never disagree
     * — the same deliveries and the same filter summary feed all of them.
     *
     * @return array<string, mixed>
     */
    private function build(Request $request): array
    {
        $filters = $request->validate([
            'month' => ['nullable', 'string'],
            'challan_no' => ['nullable', 'string', 'max:100'],
            'rv_no' => ['nullable', 'string', 'max:100'],
            'supplier' => ['nullable', 'string', 'max:255'],
            'search' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'rcv_from' => ['nullable', 'date'],
            'rcv_to' => ['nullable', 'date'],
        ]);

        $rows = $this->report->rows($filters);
        $month = $this->report->resolveMonth($filters['month'] ?? null);

        return [
            'rows' => $rows,
            'lines' => $this->report->lines($rows),
            'summary' => $this->report->summary($rows),
            'suppliers' => $this->report->suppliers(),
            'filters' => $filters,
            'month' => $month?->format('Y-m'),
            'periodLabel' => $this->periodLabel($filters, $month),
            'title' => 'Receiving Report',
        ];
    }

    /**
     * What the report covers, in words — printed on the PDF so a filtered
     * download can never be mistaken for the whole period.
     *
     * @param  array<string, mixed>  $filters
     */
    private function periodLabel(array $filters, $month): string
    {
        if ($month) {
            return $month->format('F Y');
        }

        $from = $filters['from'] ?? null;
        $to = $filters['to'] ?? null;

        if ($from && $to) {
            return \Illuminate\Support\Carbon::parse($from)->format('d M Y')
                .' — '.\Illuminate\Support\Carbon::parse($to)->format('d M Y');
        }

        if ($from) {
            return 'From '.\Illuminate\Support\Carbon::parse($from)->format('d M Y');
        }

        if ($to) {
            return 'Up to '.\Illuminate\Support\Carbon::parse($to)->format('d M Y');
        }

        return 'All dates';
    }

    /** @param array<string, mixed> $data */
    private function filename(array $data): string
    {
        return 'Receiving-Report-'.Str::slug($data['periodLabel']);
    }
}
