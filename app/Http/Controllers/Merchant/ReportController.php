<?php

namespace App\Http\Controllers\Merchant;

use App\Exports\MerchantReportExport;
use App\Http\Controllers\Controller;
use App\Services\MerchantReportService;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Merchandising reports — a filtered, line-level view of the BOM columns this
 * department owns, previewable on screen and downloadable as PDF or Excel.
 *
 * Read-only: nothing here writes a cell, a header or a file.
 *
 * Access mirrors the Store reports. Merchant, Admin and Management reach the
 * screen and both exports; the download routes re-check the role in
 * canDownload() so a hand-typed URL cannot get past the middleware.
 *
 * The department boundary is not enforced here at all — MerchantReportService
 * scopes every query to Merchandising-owned headers, so there is no request
 * this controller could accept that would surface another department's column.
 */
class ReportController extends Controller
{
    /** Roles allowed to export. */
    private const DOWNLOAD_ROLES = ['merchant', 'admin', 'management'];

    /** Preview page size. The exports are never paginated. */
    private const PER_PAGE = 50;

    public function __construct(private readonly MerchantReportService $reports)
    {
    }

    public function index(Request $request)
    {
        $filters = $this->resolve($request);

        return view('merchant.reports.index', [
            'filters' => $filters,
            'columns' => $this->reports->columns(),
            'rows' => $this->reports->paginate($filters, self::PER_PAGE),
            'options' => $this->reports->filterOptions(),
            'reports' => $this->reports,
            'canDownload' => $this->canDownload($request),
        ]);
    }

    public function pdf(Request $request)
    {
        abort_unless($this->canDownload($request), 403, 'Unauthorized');

        $filters = $this->resolve($request);

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('merchant.reports.pdf', [
            'filters' => $filters,
            // The summary subset, not all 35 — see MerchantReportService::pdfKeys().
            'columns' => $this->reports->pdfColumns(),
            'rows' => $this->reports->all($filters),
            'reports' => $this->reports,
            'title' => 'Merchandising BOM Report',
        ])->setPaper('a4', 'landscape');

        return $pdf->download($this->filename('pdf'));
    }

    public function excel(Request $request)
    {
        abort_unless($this->canDownload($request), 403, 'Unauthorized');

        $filters = $this->resolve($request);

        return Excel::download(
            new MerchantReportExport(
                filters: $filters,
                columns: $this->reports->columns(),
                rows: $this->reports->all($filters),
                reports: $this->reports,
            ),
            $this->filename('xlsx')
        );
    }

    /**
     * Validate once and return clean filters, so preview, PDF and Excel always
     * read exactly the same data.
     *
     * This is also where the report is scoped to its viewer. All three actions
     * call it before they touch the service, so the buyer scope reaches the
     * screen, the PDF and the Excel sheet from one line rather than three.
     *
     * @return array<string, string|null>
     */
    private function resolve(Request $request): array
    {
        $this->reports->forUser($request->user());

        $validated = $request->validate([
            'buyer' => ['nullable', 'string', 'max:255'],
            'style' => ['nullable', 'string', 'max:255'],
            'season' => ['nullable', 'string', 'max:255'],
            'gmts_color' => ['nullable', 'string', 'max:255'],
            'po_no' => ['nullable', 'string', 'max:255'],
            'material' => ['nullable', 'string', 'max:255'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ], [], [
            'date_from' => 'from date',
            'date_to' => 'to date',
            'po_no' => 'garments PO',
            'gmts_color' => 'GMTS colour',
            'material' => 'material search',
        ]);

        return [
            'buyer' => $validated['buyer'] ?? null,
            'style' => $validated['style'] ?? null,
            'season' => $validated['season'] ?? null,
            'gmts_color' => $validated['gmts_color'] ?? null,
            'po_no' => $validated['po_no'] ?? null,
            'material' => $validated['material'] ?? null,
            // Dates are compared against ISO text in the cells, so they are
            // normalised to the same shape here rather than passed through raw.
            'date_from' => isset($validated['date_from']) ? date('Y-m-d', strtotime($validated['date_from'])) : null,
            'date_to' => isset($validated['date_to']) ? date('Y-m-d', strtotime($validated['date_to'])) : null,
        ];
    }

    private function canDownload(Request $request): bool
    {
        return (bool) $request->user()?->hasAnyRole(self::DOWNLOAD_ROLES);
    }

    private function filename(string $extension): string
    {
        return 'merchandising-bom-report-'.now()->format('Ymd-His').'.'.$extension;
    }
}
