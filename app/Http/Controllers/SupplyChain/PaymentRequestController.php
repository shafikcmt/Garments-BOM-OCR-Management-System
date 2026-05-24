<?php

namespace App\Http\Controllers\SupplyChain;

use App\Http\Controllers\Controller;
use App\Models\BookingPo;
use App\Models\PaymentRequest;
use App\Models\PaymentRequestItem;
use App\Services\BookingPoSourceService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PaymentRequestController extends Controller
{
    public function __construct(protected BookingPoSourceService $sourceService)
    {
    }

    public function index(Request $request)
    {
        abort_if(! auth()->user()?->hasRole('supply_chain'), 403);

        [$pendingRows, $filterOptions, $kpis, $activeFilters] = $this->pendingPaymentRows($request);

        $paymentRequests = PaymentRequest::query()
            ->with(['createdBy'])
            ->latest('id')
            ->paginate(20, ['*'], 'requests_page')
            ->withQueryString();

        return view('supply-chain.payment-requests.index', compact('pendingRows', 'filterOptions', 'kpis', 'activeFilters', 'paymentRequests'));
    }

    public function create(Request $request)
    {
        return $this->index($request);
    }

    public function store(Request $request)
    {
        abort_if(! auth()->user()?->hasRole('supply_chain'), 403);

        $validated = $request->validate([
            'booking_po_ids' => ['required', 'array', 'min:1'],
            'booking_po_ids.*' => ['integer', 'exists:booking_pos,id'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $bookingPos = BookingPo::query()
            ->with(['excelFile.uploader', 'excelRow.cells.header', 'generatedBy'])
            ->whereIn('id', collect($validated['booking_po_ids'])->map(fn ($id) => (int) $id)->unique()->values())
            ->get();

        $snapshots = $bookingPos
            ->map(fn (BookingPo $bookingPo) => $this->sourceService->paymentSnapshot($bookingPo))
            ->filter(fn (array $row) => (bool) ($row['eligible_for_payment_request'] ?? false))
            ->values();

        if ($snapshots->isEmpty()) {
            return back()->with('warning', 'No eligible PI received / payment pending row was selected. Please check PI Number, PI Status and Payment Status.');
        }

        $paymentRequest = DB::transaction(function () use ($snapshots, $validated) {
            $paymentRequest = PaymentRequest::create([
                'request_no' => $this->nextRequestNo(),
                'supplier_name' => $this->uniqueText($snapshots, 'supplier_name'),
                'buyer_name' => $this->uniqueText($snapshots, 'buyer_name'),
                'season_name' => $this->uniqueText($snapshots, 'season_name'),
                'total_pi_amount' => $snapshots->sum(fn (array $row) => (float) ($row['pi_amount'] ?? 0)),
                'status' => 'draft',
                'created_by' => auth()->id(),
                'remarks' => $validated['remarks'] ?? null,
                'data' => [
                    'pi_numbers' => $snapshots->pluck('pi_number')->filter()->unique()->values()->all(),
                    'po_numbers' => $snapshots->pluck('po_no')->filter()->unique()->values()->all(),
                    'approval_filters' => $this->approvalFiltersFromSnapshots($snapshots),
                    'payment_status_summary' => $snapshots->countBy('payment_status')->all(),
                    'source' => 'supply_chain_payment_request',
                ],
            ]);

            foreach ($snapshots as $snapshot) {
                PaymentRequestItem::create([
                    'payment_request_id' => $paymentRequest->id,
                    'booking_po_id' => $snapshot['booking_po_id'] ?? null,
                    'excel_file_id' => $snapshot['excel_file_id'] ?? null,
                    'excel_row_id' => $snapshot['excel_row_id'] ?? null,
                    'po_no' => $snapshot['po_no'] ?? null,
                    'pi_number' => $snapshot['pi_number'] ?? null,
                    'pi_status' => $snapshot['pi_status'] ?? null,
                    'pi_rate' => $snapshot['pi_rate'] ?? null,
                    'pi_amount' => $snapshot['pi_amount'] ?? null,
                    'payment_status' => $snapshot['payment_status'] ?? null,
                    'payment_required_date' => $snapshot['payment_required_date'] ?? null,
                    'supplier_name' => $snapshot['supplier_name'] ?? null,
                    'buyer_name' => $snapshot['buyer_name'] ?? null,
                    'season_name' => $snapshot['season_name'] ?? null,
                    'style_name' => $snapshot['style_name'] ?? null,
                    'material_description' => $snapshot['material_description'] ?? null,
                    'sap_code' => $snapshot['sap_code'] ?? null,
                    'material_color' => $snapshot['material_color'] ?? null,
                    'qty' => $snapshot['qty'] ?? null,
                    'delivery_term' => $snapshot['delivery_term'] ?? null,
                    'payment_term' => $snapshot['payment_term'] ?? null,
                    'ship_mode' => $snapshot['ship_mode'] ?? null,
                    'forwarder' => $snapshot['forwarder'] ?? null,
                    'committed_etd' => $snapshot['committed_etd'] ?? null,
                    'committed_eta' => $snapshot['committed_eta'] ?? null,
                    'remarks' => $snapshot['remarks'] ?? null,
                    'data' => $snapshot,
                ]);
            }

            return $paymentRequest->fresh(['items', 'createdBy']);
        });

        return redirect()
            ->route('supply_chain.payment_requests.show', $paymentRequest)
            ->with('success', 'Payment Request Approval created: ' . $paymentRequest->request_no);
    }

    public function show(PaymentRequest $paymentRequest)
    {
        abort_if(! auth()->user()?->hasRole('supply_chain'), 403);

        $paymentRequest->load(['items', 'createdBy', 'checkedBy', 'approvedBy']);
        $summary = $this->summaryFromItems($paymentRequest->items);

        return view('supply-chain.payment-requests.show', compact('paymentRequest', 'summary'));
    }

    public function downloadPdf(PaymentRequest $paymentRequest)
    {
        abort_if(! auth()->user()?->hasRole('supply_chain'), 403);

        $paymentRequest->load(['items', 'createdBy', 'checkedBy', 'approvedBy']);
        $summary = $this->summaryFromItems($paymentRequest->items);

        if (class_exists('Barryvdh\\DomPDF\\Facade\\Pdf')) {
            $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('supply-chain.payment-requests.approval_pdf', [
                'paymentRequest' => $paymentRequest,
                'summary' => $summary,
                'isPdf' => true,
            ])->setPaper('a4', 'landscape');

            return $pdf->stream('PAYMENT_REQUEST_APPROVAL_' . $paymentRequest->request_no . '.pdf', ['Attachment' => false]);
        }

        return view('supply-chain.payment-requests.approval_pdf', [
            'paymentRequest' => $paymentRequest,
            'summary' => $summary,
            'isPdf' => false,
        ]);
    }

    public function downloadExcel(PaymentRequest $paymentRequest)
    {
        abort_if(! auth()->user()?->hasRole('supply_chain'), 403);

        $paymentRequest->load(['items', 'createdBy', 'checkedBy', 'approvedBy']);
        $summary = $this->summaryFromItems($paymentRequest->items);
        $fileName = 'PAYMENT_REQUEST_APPROVAL_' . $paymentRequest->request_no;

        if (class_exists('PhpOffice\\PhpSpreadsheet\\Spreadsheet')) {
            return $this->downloadXlsx($paymentRequest, $summary, $fileName . '.xlsx');
        }

        return response()
            ->view('supply-chain.payment-requests.approval_excel', compact('paymentRequest', 'summary'))
            ->header('Content-Type', 'application/vnd.ms-excel; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="' . $fileName . '.xls"')
            ->header('Pragma', 'no-cache')
            ->header('Expires', '0');
    }

    protected function pendingPaymentRows(Request $request): array
    {
        $baseRows = BookingPo::query()
            ->with(['excelFile.uploader', 'excelRow.cells.header', 'generatedBy'])
            ->whereNotNull('generated_at')
            ->latest('generated_at')
            ->limit(5000)
            ->get()
            ->map(fn (BookingPo $bookingPo) => $this->sourceService->paymentSnapshot($bookingPo))
            ->filter(fn (array $row) => (bool) ($row['eligible_for_payment_request'] ?? false))
            ->values();

        $filterOptions = $this->filterOptions($baseRows);
        $filteredRows = $this->applyFilters($baseRows, $request)->values();
        $kpis = $this->summaryFromSnapshots($filteredRows);
        $activeFilters = $this->activeFilters($request);

        $perPage = 25;
        $page = LengthAwarePaginator::resolveCurrentPage();
        $items = $filteredRows->forPage($page, $perPage)->values();

        $pendingRows = new LengthAwarePaginator(
            $items,
            $filteredRows->count(),
            $perPage,
            $page,
            [
                'path' => $request->url(),
                'query' => $request->query(),
            ]
        );

        return [$pendingRows, $filterOptions, $kpis, $activeFilters];
    }

    protected function applyFilters($rows, Request $request): \Illuminate\Support\Collection
    {
        return $rows->filter(function (array $row) use ($request) {
            foreach ($this->textFilterMap() as $input => $key) {
                $needle = trim((string) $request->input($input));
                if ($needle !== '' && ! Str::contains(Str::lower((string) ($row[$key] ?? '')), Str::lower($needle))) {
                    return false;
                }
            }

            foreach ($this->dateFilterMap() as $prefix => $key) {
                $date = $this->sourceService->dateValue($row[$key] ?? null);
                $from = $this->sourceService->dateValue($request->input($prefix . '_from'));
                $to = $this->sourceService->dateValue($request->input($prefix . '_to'));

                if ($from && (! $date || $date->lt($from->startOfDay()))) {
                    return false;
                }

                if ($to && (! $date || $date->gt($to->endOfDay()))) {
                    return false;
                }
            }

            return true;
        });
    }

    protected function textFilterMap(): array
    {
        return [
            'shipment_month' => 'shipment_month',
            'vendor_type' => 'vendor_type',
            'final_status' => 'final_status',
            'payment_term' => 'payment_term',
            'payment_status' => 'payment_status',
            'supplier' => 'supplier_name',
            'buyer' => 'buyer_name',
            'season' => 'season_name',
            'po_no' => 'po_no',
            'pi_number' => 'pi_number',
            'pi_status' => 'pi_status',
            'material_type' => 'material_type',
        ];
    }

    protected function dateFilterMap(): array
    {
        return [
            'contract_shipment' => 'contract_shipment',
            'committed_ex_mill' => 'committed_ex_mill',
            'pcd_required' => 'pcd_required',
            'payment_required_date' => 'payment_required_date',
            'committed_etd' => 'committed_etd',
            'committed_eta' => 'committed_eta',
        ];
    }

    protected function filterOptions($rows): array
    {
        return [
            'shipment_months' => $this->optionValues($rows, 'shipment_month'),
            'vendor_types' => $this->optionValues($rows, 'vendor_type'),
            'final_statuses' => $this->optionValues($rows, 'final_status'),
            'payment_terms' => $this->optionValues($rows, 'payment_term'),
            'payment_statuses' => $this->optionValues($rows, 'payment_status'),
            'suppliers' => $this->optionValues($rows, 'supplier_name'),
            'buyers' => $this->optionValues($rows, 'buyer_name'),
            'seasons' => $this->optionValues($rows, 'season_name'),
            'po_numbers' => $this->optionValues($rows, 'po_no'),
            'pi_numbers' => $this->optionValues($rows, 'pi_number'),
            'pi_statuses' => $this->optionValues($rows, 'pi_status'),
            'material_types' => $this->optionValues($rows, 'material_type'),
        ];
    }

    protected function optionValues($rows, string $key)
    {
        return $rows->pluck($key)
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->unique(fn ($value) => Str::lower($value))
            ->sort()
            ->values();
    }

    protected function activeFilters(Request $request): array
    {
        $labels = [
            'shipment_month' => 'Shipment Month',
            'vendor_type' => 'Vendor Type',
            'final_status' => 'Final Status',
            'payment_term' => 'Payment Term',
            'payment_status' => 'Payment Status',
            'supplier' => 'Supplier / Vendor',
            'buyer' => 'Buyer',
            'season' => 'Season',
            'po_no' => 'PO Number',
            'pi_number' => 'PI Number',
            'pi_status' => 'PI Status',
            'material_type' => 'Material Type',
        ];

        $active = [];
        foreach ($labels as $input => $label) {
            $value = trim((string) $request->input($input));
            if ($value !== '') {
                $active[$label] = $value;
            }
        }

        $dateLabels = [
            'contract_shipment' => 'Contract Shipment',
            'committed_ex_mill' => 'Committed Ex Mill',
            'pcd_required' => 'PCD Required',
            'payment_required_date' => 'Payment Required Date',
        ];

        foreach ($dateLabels as $prefix => $label) {
            $from = trim((string) $request->input($prefix . '_from'));
            $to = trim((string) $request->input($prefix . '_to'));
            if ($from !== '' || $to !== '') {
                $active[$label] = trim(($from ?: '...') . ' to ' . ($to ?: '...'));
            }
        }

        return $active;
    }

    protected function summaryFromSnapshots($rows): array
    {
        return [
            'total_pi_amount' => $rows->sum(fn (array $row) => (float) ($row['pi_amount'] ?? 0)),
            'total_budget' => $rows->sum(fn (array $row) => (float) ($row['budget'] ?? 0)),
            'total_savings' => $rows->sum(fn (array $row) => (float) ($row['savings'] ?? 0)),
            'total_po_count' => $rows->pluck('po_no')->filter()->unique()->count(),
            'pending_payment_count' => $rows->filter(fn (array $row) => $this->sourceService->normalize($row['payment_status'] ?? '') === 'pmt_pending')->count(),
            'earliest_payment_required_date' => $rows->pluck('payment_required_date')->filter()->sort()->first(),
            'payment_status_summary' => $rows->countBy('payment_status')->all(),
            'buyers' => $this->optionValues($rows, 'buyer_name')->all(),
            'seasons' => $this->optionValues($rows, 'season_name')->all(),
            'suppliers' => $this->optionValues($rows, 'supplier_name')->all(),
            'shipment_months' => $this->optionValues($rows, 'shipment_month')->all(),
            'vendor_types' => $this->optionValues($rows, 'vendor_type')->all(),
            'final_statuses' => $this->optionValues($rows, 'final_status')->all(),
            'payment_terms' => $this->optionValues($rows, 'payment_term')->all(),
            'payment_statuses' => $this->optionValues($rows, 'payment_status')->all(),
            'material_types' => $this->optionValues($rows, 'material_type')->all(),
            'pi_numbers' => $this->optionValues($rows, 'pi_number')->all(),
        ];
    }

    protected function summaryFromItems($items): array
    {
        return [
            'total_pi_amount' => $items->sum(fn ($item) => (float) $item->pi_amount),
            'total_budget' => $items->sum(fn ($item) => (float) data_get($item->data, 'budget', 0)),
            'total_savings' => $items->sum(fn ($item) => (float) data_get($item->data, 'savings', 0)),
            'total_po_count' => $items->pluck('po_no')->filter()->unique()->count(),
            'pending_payment_count' => $items->filter(fn ($item) => $this->sourceService->normalize($item->payment_status ?? '') === 'pmt_pending')->count(),
            'earliest_payment_required_date' => $items->pluck('payment_required_date')->filter()->sort()->first(),
            'payment_status_summary' => $items->countBy('payment_status')->all(),
            'pi_numbers' => $items->pluck('pi_number')->filter()->unique()->values()->all(),
            'payment_terms' => $items->pluck('payment_term')->filter()->unique()->values()->all(),
            'delivery_terms' => $items->pluck('delivery_term')->filter()->unique()->values()->all(),
            'buyers' => $items->pluck('buyer_name')->filter()->unique()->values()->all(),
            'seasons' => $items->pluck('season_name')->filter()->unique()->values()->all(),
            'suppliers' => $items->pluck('supplier_name')->filter()->unique()->values()->all(),
            'shipment_months' => $this->uniqueItemData($items, 'shipment_month'),
            'vendor_types' => $this->uniqueItemData($items, 'vendor_type'),
            'final_statuses' => $this->uniqueItemData($items, 'final_status'),
            'payment_statuses' => $items->pluck('payment_status')->filter()->unique()->values()->all(),
            'material_types' => $this->uniqueItemData($items, 'material_type'),
        ];
    }

    protected function uniqueItemData($items, string $key): array
    {
        return $items->map(fn ($item) => trim((string) data_get($item->data, $key, '')))
            ->filter()
            ->unique(fn ($value) => Str::lower($value))
            ->sort()
            ->values()
            ->all();
    }

    protected function approvalFiltersFromSnapshots($snapshots): array
    {
        return [
            'Shipment Month' => $this->optionValues($snapshots, 'shipment_month')->all(),
            'Vendor Type' => $this->optionValues($snapshots, 'vendor_type')->all(),
            'Final Status' => $this->optionValues($snapshots, 'final_status')->all(),
            'Payment Term' => $this->optionValues($snapshots, 'payment_term')->all(),
            'Payment Status' => $this->optionValues($snapshots, 'payment_status')->all(),
        ];
    }

    protected function nextRequestNo(): string
    {
        $prefix = 'PR-' . now()->format('Ymd') . '-';
        $last = PaymentRequest::query()
            ->where('request_no', 'like', $prefix . '%')
            ->orderByDesc('request_no')
            ->value('request_no');

        $next = 1;
        if ($last && preg_match('/(\d+)$/', $last, $matches)) {
            $next = ((int) $matches[1]) + 1;
        }

        do {
            $requestNo = $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
            $next++;
        } while (PaymentRequest::where('request_no', $requestNo)->exists());

        return $requestNo;
    }

    protected function uniqueText($rows, string $key): ?string
    {
        $values = $rows->pluck($key)
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->unique(fn ($value) => Str::lower($value))
            ->values();

        if ($values->isEmpty()) {
            return null;
        }

        return $values->take(5)->implode(', ') . ($values->count() > 5 ? ' +' . ($values->count() - 5) : '');
    }

    protected function shortList(array $values, string $fallback = '-'): string
    {
        $values = collect($values)->map(fn ($value) => trim((string) $value))->filter()->values();

        if ($values->isEmpty()) {
            return $fallback;
        }

        return $values->take(4)->implode(', ') . ($values->count() > 4 ? ' +' . ($values->count() - 4) : '');
    }

    protected function itemReportValue(PaymentRequestItem $item, string $key, mixed $fallback = ''): mixed
    {
        $value = data_get($item->data, $key);

        return ($value === null || trim((string) $value) === '') ? $fallback : $value;
    }

    protected function downloadXlsx(PaymentRequest $paymentRequest, array $summary, string $fileName)
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Payment Approval');
        $sheet->getSheetView()->setShowGridLines(false);

        $lastColumn = 'L';
        $list = fn ($values, $fallback = '-') => collect($values ?? [])->map(fn ($value) => trim((string) $value))->filter()->take(5)->implode(', ') ?: $fallback;
        $paymentRequired = $summary['earliest_payment_required_date'] ? optional($summary['earliest_payment_required_date'])->format('jS M-Y') : '-';
        $filters = [
            'Final Status' => $this->shortList($summary['final_statuses'] ?? []),
            'Vendor Type' => $this->shortList($summary['vendor_types'] ?? []),
            'Payment Term' => $this->shortList($summary['payment_terms'] ?? []),
            'Payment Status' => $this->shortList($summary['payment_statuses'] ?? []),
            'Vendor Name' => $this->shortList($summary['suppliers'] ?? [], $paymentRequest->supplier_name ?: '-'),
        ];

        $logoPath = public_path('images/humana-logo.png');
        if (file_exists($logoPath)) {
            $drawing = new \PhpOffice\PhpSpreadsheet\Worksheet\Drawing();
            $drawing->setName('Logo');
            $drawing->setPath($logoPath);
            $drawing->setHeight(56);
            $drawing->setCoordinates('A1');
            $drawing->setOffsetX(8);
            $drawing->setOffsetY(6);
            $drawing->setWorksheet($sheet);
        }

        $sheet->mergeCells('A1:C2');
        $sheet->mergeCells('D1:I1');
        $sheet->mergeCells('D2:I2');
        $sheet->mergeCells('J1:L2');
        $sheet->setCellValue('A1', 'Humana');
        $sheet->setCellValue('D1', 'Payment Request Approval');
        $sheet->setCellValue('D2', $paymentRequest->request_no);
        $sheet->setCellValue('J1', "Date: " . optional($paymentRequest->created_at)->format('jS M-Y') . "
Payment Require Date: " . $paymentRequired);

        $sheet->getStyle('A1:L2')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FF111827']],
            'alignment' => ['vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ]);
        $sheet->getStyle('D1')->getFont()->setSize(16);
        $sheet->getStyle('D1:I1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('D2:I2')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('D2')->getFont()->setSize(10)->getColor()->setARGB('FF1D4ED8');
        $sheet->getStyle('J1:L2')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);
        $sheet->getRowDimension(1)->setRowHeight(30);
        $sheet->getRowDimension(2)->setRowHeight(24);

        $sheet->mergeCells('A3:D3');
        $sheet->mergeCells('E3:H3');
        $sheet->mergeCells('I3:L3');
        $sheet->setCellValue('A3', 'Buyer: ' . $list($summary['buyers'] ?? [], $paymentRequest->buyer_name ?: '-'));
        $sheet->setCellValue('E3', 'Season: ' . $list($summary['seasons'] ?? [], $paymentRequest->season_name ?: '-'));
        $sheet->setCellValue('I3', 'Total PI Amount: $' . number_format((float) ($summary['total_pi_amount'] ?? 0), 2));
        $sheet->getStyle('A3:L3')->applyFromArray([
            'font' => ['bold' => true, 'size' => 11],
            'alignment' => ['vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getStyle('E3:H3')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('I3:L3')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);

        $filterTitleRow = 5;
        $filterValueRow = 6;
        $ranges = ['A:B', 'C:D', 'E:F', 'G:H', 'I:J'];
        $i = 0;
        foreach ($filters as $label => $value) {
            [$from, $to] = explode(':', $ranges[$i]);
            $sheet->mergeCells($from . $filterTitleRow . ':' . $to . $filterTitleRow);
            $sheet->mergeCells($from . $filterValueRow . ':' . $to . $filterValueRow);
            $sheet->setCellValue($from . $filterTitleRow, $label);
            $sheet->setCellValue($from . $filterValueRow, $value ?: '-');
            $sheet->getStyle($from . $filterTitleRow . ':' . $to . $filterValueRow)->applyFromArray([
                'borders' => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['argb' => 'FFB8C2CC']]],
                'alignment' => ['wrapText' => true, 'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER],
            ]);
            $sheet->getStyle($from . $filterTitleRow . ':' . $to . $filterTitleRow)->applyFromArray([
                'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFE5E7EB']],
                'font' => ['bold' => true, 'size' => 9],
            ]);
            $i++;
        }
        $sheet->mergeCells('K5:L5');
        $sheet->mergeCells('K6:L6');
        $sheet->setCellValue('K5', 'Request No');
        $sheet->setCellValue('K6', $paymentRequest->request_no);
        $sheet->getStyle('K5:L6')->applyFromArray([
            'borders' => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['argb' => 'FFB8C2CC']]],
            'alignment' => ['wrapText' => true, 'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getStyle('K5:L5')->applyFromArray([
            'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFE5E7EB']],
            'font' => ['bold' => true, 'size' => 9],
        ]);

        $headers = ['Vendor Name', 'Style', 'PCD Required', 'Payment Term', 'Material PO Number', 'Season', 'Material PI Number', 'Material Type', 'Payment Status', 'Contract Shipment', 'Committed Ex Mill', 'PI Amount'];
        $tableStart = 8;
        $sheet->fromArray($headers, null, 'A' . $tableStart);
        $sheet->getStyle('A' . $tableStart . ':' . $lastColumn . $tableStart)->applyFromArray([
            'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFD9DEE8']],
            'font' => ['bold' => true, 'size' => 9, 'color' => ['argb' => 'FF111827']],
            'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER, 'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER, 'wrapText' => true],
            'borders' => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['argb' => 'FF9CA3AF']]],
        ]);
        $sheet->getRowDimension($tableStart)->setRowHeight(24);

        $rowNo = $tableStart + 1;
        foreach ($paymentRequest->items as $item) {
            $sheet->fromArray([
                $item->supplier_name ?: '-',
                $item->style_name ?: '-',
                $this->itemReportValue($item, 'pcd_required', '-'),
                $item->payment_term ?: '-',
                $item->po_no ?: '-',
                $item->season_name ?: '-',
                $item->pi_number ?: '-',
                $this->itemReportValue($item, 'material_type', '-'),
                $item->payment_status ?: '-',
                $this->itemReportValue($item, 'contract_shipment', '-'),
                $this->itemReportValue($item, 'committed_ex_mill', '-'),
                (float) $item->pi_amount,
            ], null, 'A' . $rowNo);
            $sheet->getStyle('A' . $rowNo . ':' . $lastColumn . $rowNo)->applyFromArray([
                'font' => ['size' => 9],
                'alignment' => ['vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP, 'wrapText' => true],
                'borders' => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['argb' => 'FFD1D5DB']]],
            ]);
            if (($rowNo % 2) === 0) {
                $sheet->getStyle('A' . $rowNo . ':' . $lastColumn . $rowNo)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFF8FAFC');
            }
            $rowNo++;
        }

        $sheet->setCellValue('A' . $rowNo, 'Grand Total');
        $sheet->setCellValue('L' . $rowNo, (float) ($summary['total_pi_amount'] ?? 0));
        $sheet->mergeCells('A' . $rowNo . ':K' . $rowNo);
        $sheet->getStyle('A' . $rowNo . ':' . $lastColumn . $rowNo)->applyFromArray([
            'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFEEF2F7']],
            'font' => ['bold' => true, 'size' => 10],
            'borders' => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['argb' => 'FF9CA3AF']]],
        ]);
        $sheet->getStyle('L' . ($tableStart + 1) . ':L' . $rowNo)->getNumberFormat()->setFormatCode('$#,##0.00;[Red]($#,##0.00)');
        $sheet->getStyle('L' . ($tableStart + 1) . ':L' . $rowNo)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);
        $sheet->getStyle('C' . ($tableStart + 1) . ':C' . $rowNo)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('J' . ($tableStart + 1) . ':K' . $rowNo)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

        $rowNo += 3;
        foreach ([['A', 'D', 'Prepared By', optional($paymentRequest->createdBy)->name], ['E', 'H', 'Checked By', optional($paymentRequest->checkedBy)->name], ['I', 'L', 'Approved By', optional($paymentRequest->approvedBy)->name]] as [$from, $to, $title, $name]) {
            $sheet->mergeCells($from . $rowNo . ':' . $to . ($rowNo + 1));
            $sheet->setCellValue($from . $rowNo, $title . "
Name: " . ($name ?: ''));
            $sheet->getStyle($from . $rowNo . ':' . $to . ($rowNo + 1))->applyFromArray([
                'font' => ['bold' => true, 'size' => 9],
                'alignment' => ['vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP, 'wrapText' => true],
                'borders' => ['top' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['argb' => 'FF111827']]],
            ]);
        }

        $rowNo += 3;
        $sheet->mergeCells('A' . $rowNo . ':G' . ($rowNo + 2));
        $sheet->mergeCells('H' . $rowNo . ':L' . ($rowNo + 2));
        $sheet->setCellValue('A' . $rowNo, "Buyer nominated supplier.
No excess quantity has been booked.");
        $sheet->setCellValue('H' . $rowNo, "OCR Checked: ☐ Yes   ☐ No
Nominated Supplier: ☐ Yes   ☐ No
Checker Name: __________________   Date: __________________");
        $sheet->getStyle('A' . $rowNo . ':L' . ($rowNo + 2))->getAlignment()->setWrapText(true);

        $columnWidths = ['A' => 19, 'B' => 15, 'C' => 13, 'D' => 15, 'E' => 18, 'F' => 12, 'G' => 18, 'H' => 14, 'I' => 15, 'J' => 15, 'K' => 16, 'L' => 13];
        foreach ($columnWidths as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }

        $sheet->getPageMargins()->setTop(0.25);
        $sheet->getPageMargins()->setBottom(0.25);
        $sheet->getPageMargins()->setLeft(0.25);
        $sheet->getPageMargins()->setRight(0.25);
        $sheet->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE);
        $sheet->getPageSetup()->setFitToWidth(1);
        $sheet->getPageSetup()->setFitToHeight(0);
        $sheet->freezePane('A9');

        $tmp = tempnam(sys_get_temp_dir(), 'payment_request_');
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save($tmp);

        return response()->download($tmp, $fileName)->deleteFileAfterSend(true);
    }
}
