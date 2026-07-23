<?php

namespace App\Http\Controllers\Store;

use App\Exports\StoreReportExport;
use App\Http\Controllers\Controller;
use App\Services\StoreReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Store reports — style-wise / buyer-wise / material-wise summaries built from
 * the existing material movement tables. Read-only.
 *
 * Access: store, admin and management get preview + PDF + Excel. Merchant gets
 * preview only (see canDownload()).
 */
class ReportController extends Controller
{
    /** Roles allowed to export. Preview-only roles are gated in the route. */
    private const DOWNLOAD_ROLES = ['store', 'admin', 'management'];

    public function __construct(private readonly StoreReportService $reports)
    {
    }

    public function index(Request $request)
    {
        [$type, $filters] = $this->resolve($request);

        $rows = $this->reports->rows($type, $filters);

        return view('store.reports.index', [
            'type' => $type,
            'filters' => $filters,
            'rows' => $rows,
            'totals' => $this->reports->totals($rows),
            'groupHeading' => StoreReportService::groupHeading($type),
            'reportTypes' => StoreReportService::types(),
            'canDownload' => $this->canDownload($request),
            'options' => $this->reports->filterOptions(),
        ]);
    }

    public function pdf(Request $request)
    {
        abort_unless($this->canDownload($request), 403, 'Unauthorized');

        [$type, $filters] = $this->resolve($request);
        $rows = $this->reports->rows($type, $filters);

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('store.reports.pdf', [
            'type' => $type,
            'filters' => $filters,
            'rows' => $rows,
            'totals' => $this->reports->totals($rows),
            'groupHeading' => StoreReportService::groupHeading($type),
            'title' => StoreReportService::types()[$type] . ' Stock Report',
        ])->setPaper('a4', 'landscape');

        return $pdf->download($this->filename($type, 'pdf'));
    }

    public function excel(Request $request)
    {
        abort_unless($this->canDownload($request), 403, 'Unauthorized');

        [$type, $filters] = $this->resolve($request);
        $rows = $this->reports->rows($type, $filters);

        return Excel::download(
            new StoreReportExport(
                type: $type,
                filters: $filters,
                rows: $rows,
                totals: $this->reports->totals($rows),
            ),
            $this->filename($type, 'xlsx')
        );
    }

    /**
     * Validate the request once and return the report type plus clean filters,
     * so preview, PDF and Excel always read exactly the same data.
     *
     * @return array{0: string, 1: array<string, string|null>}
     */
    private function resolve(Request $request): array
    {
        $validated = $request->validate([
            'type' => ['nullable', 'string', 'in:' . implode(',', array_keys(StoreReportService::types()))],
            'buyer' => ['nullable', 'string', 'max:255'],
            'style' => ['nullable', 'string', 'max:255'],
            'material' => ['nullable', 'string', 'max:255'],
            'season' => ['nullable', 'string', 'max:255'],
            'po_no' => ['nullable', 'string', 'max:255'],
            'gmts_color' => ['nullable', 'string', 'max:255'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ], [], [
            'date_from' => 'from date',
            'date_to' => 'to date',
            'po_no' => 'PO number',
            'gmts_color' => 'GMTS colour',
        ]);

        $type = $validated['type'] ?? StoreReportService::TYPE_STYLE;

        // Carried through index(), pdf() and excel() alike — the three share this
        // method, so a filter added here reaches the screen and both exports.
        $filters = [
            'buyer' => $validated['buyer'] ?? null,
            'style' => $validated['style'] ?? null,
            'material' => $validated['material'] ?? null,
            'season' => $validated['season'] ?? null,
            'po_no' => $validated['po_no'] ?? null,
            'gmts_color' => $validated['gmts_color'] ?? null,
            'date_from' => $validated['date_from'] ?? null,
            'date_to' => $validated['date_to'] ?? null,
        ];

        return [$type, $filters];
    }

    private function canDownload(Request $request): bool
    {
        return (bool) $request->user()?->hasAnyRole(self::DOWNLOAD_ROLES);
    }

    private function filename(string $type, string $extension): string
    {
        return 'store-' . Str::slug($type) . '-report-' . now()->format('Ymd-His') . '.' . $extension;
    }
}
