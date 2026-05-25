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

    public function preview(Request $request)
    {
        abort_if(! auth()->user()?->hasRole('supply_chain'), 403);

        $validated = $request->validate([
            'booking_po_ids' => ['required', 'array', 'min:1'],
            'booking_po_ids.*' => ['integer', 'exists:booking_pos,id'],
            'payment_required_date' => ['nullable', 'date'],
        ]);

        $selectedIds = collect($validated['booking_po_ids'])
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $paymentRequiredInput = $validated['payment_required_date'] ?? now()->addDays(7)->toDateString();
        $snapshots = $this->snapshotsForSelectedBookingPos($selectedIds)
            ->map(fn (array $snapshot) => array_replace($snapshot, [
                'payment_required_date' => $paymentRequiredInput,
            ]))
            ->values();

        if ($snapshots->isEmpty()) {
            return redirect()
                ->route('supply_chain.payment_requests.index')
                ->with('warning', 'No eligible PI received / payment pending row was selected. Please check PI Number, PI Status and Payment Status.');
        }

        $paymentRequest = new PaymentRequest([
            'request_no' => 'PREVIEW-' . now()->format('Ymd'),
            'supplier_name' => $this->uniqueText($snapshots, 'supplier_name'),
            'buyer_name' => $this->uniqueText($snapshots, 'buyer_name'),
            'season_name' => $this->uniqueText($snapshots, 'season_name'),
            'total_pi_amount' => $snapshots->sum(fn (array $row) => (float) ($row['pi_amount'] ?? 0)),
            'status' => 'preview',
            'created_by' => auth()->id(),
            'data' => [
                'source' => 'supply_chain_payment_request_preview',
            ],
        ]);
        $paymentRequest->created_at = now();

        $approvalRows = $this->approvalRowsFromSnapshots($snapshots);
        $summary = $this->summaryFromApprovalRows($approvalRows);
        $isPreview = true;
        $bookingPoIds = $selectedIds->all();

        return view('supply-chain.payment-requests.show', compact('paymentRequest', 'summary', 'approvalRows', 'isPreview', 'bookingPoIds', 'paymentRequiredInput'));
    }

    public function store(Request $request)
    {
        abort_if(! auth()->user()?->hasRole('supply_chain'), 403);

        $validated = $request->validate([
            'booking_po_ids' => ['required', 'array', 'min:1'],
            'booking_po_ids.*' => ['integer', 'exists:booking_pos,id'],
            'payment_required_date' => ['nullable', 'date'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $selectedIds = collect($validated['booking_po_ids'])
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $paymentRequiredDate = $validated['payment_required_date'] ?? now()->addDays(7)->toDateString();
        $snapshots = $this->snapshotsForSelectedBookingPos($selectedIds)
            ->map(fn (array $snapshot) => array_replace($snapshot, [
                'payment_required_date' => $paymentRequiredDate,
            ]))
            ->values();

        if ($snapshots->isEmpty()) {
            return back()->with('warning', 'No eligible PI received / payment pending row was selected. Please check PI Number, PI Status and Payment Status.');
        }

        $paymentRequest = DB::transaction(function () use ($snapshots, $validated, $paymentRequiredDate) {
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
                    'payment_required_date' => $paymentRequiredDate,
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
        $approvalRows = $this->approvalRowsFromItems($paymentRequest->items);
        $summary = $this->summaryFromApprovalRows($approvalRows);

        return view('supply-chain.payment-requests.show', compact('paymentRequest', 'summary', 'approvalRows'));
    }

    public function downloadPdf(PaymentRequest $paymentRequest)
    {
        abort_if(! auth()->user()?->hasRole('supply_chain'), 403);

        $paymentRequest->load(['items', 'createdBy', 'checkedBy', 'approvedBy']);
        $approvalRows = $this->approvalRowsFromItems($paymentRequest->items);
        $summary = $this->summaryFromApprovalRows($approvalRows);

        if (class_exists('Barryvdh\\DomPDF\\Facade\\Pdf')) {
            $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('supply-chain.payment-requests.approval_pdf', [
                'paymentRequest' => $paymentRequest,
                'summary' => $summary,
                'approvalRows' => $approvalRows,
                'isPdf' => true,
            ])->setPaper('a4', 'landscape');

            return $pdf->stream('PAYMENT_REQUEST_APPROVAL_' . $paymentRequest->request_no . '.pdf', ['Attachment' => false]);
        }

        return view('supply-chain.payment-requests.approval_pdf', [
            'paymentRequest' => $paymentRequest,
            'summary' => $summary,
            'approvalRows' => $approvalRows,
            'isPdf' => false,
        ]);
    }

    public function downloadExcel(PaymentRequest $paymentRequest)
    {
        abort_if(! auth()->user()?->hasRole('supply_chain'), 403);

        $paymentRequest->load(['items', 'createdBy', 'checkedBy', 'approvedBy']);
        $approvalRows = $this->approvalRowsFromItems($paymentRequest->items);
        $summary = $this->summaryFromApprovalRows($approvalRows);
        $fileName = 'PAYMENT_REQUEST_APPROVAL_' . $paymentRequest->request_no;

        if (class_exists('PhpOffice\\PhpSpreadsheet\\Spreadsheet')) {
            return $this->downloadXlsx($paymentRequest, $summary, $fileName . '.xlsx', $approvalRows);
        }

        return response()
            ->view('supply-chain.payment-requests.approval_excel', compact('paymentRequest', 'summary', 'approvalRows'))
            ->header('Content-Type', 'application/vnd.ms-excel; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="' . $fileName . '.xls"')
            ->header('Pragma', 'no-cache')
            ->header('Expires', '0');
    }

    protected function snapshotsForSelectedBookingPos($selectedIds): \Illuminate\Support\Collection
    {
        $selectedIds = collect($selectedIds)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $selectedPoNumbers = BookingPo::query()
            ->whereIn('id', $selectedIds)
            ->pluck('po_no')
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->unique(fn ($value) => Str::lower($value))
            ->values();

        return BookingPo::query()
            ->with(['excelFile.uploader', 'excelRow.cells.header', 'generatedBy'])
            ->where(function ($query) use ($selectedIds, $selectedPoNumbers) {
                $query->whereIn('id', $selectedIds);

                if ($selectedPoNumbers->isNotEmpty()) {
                    $query->orWhereIn('po_no', $selectedPoNumbers);
                }
            })
            ->whereNotNull('generated_at')
            ->get()
            ->map(fn (BookingPo $bookingPo) => $this->sourceService->paymentSnapshot($bookingPo))
            ->filter(fn (array $row) => (bool) ($row['eligible_for_payment_request'] ?? false))
            ->values();
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

    protected function approvalRowsFromSnapshots($snapshots): \Illuminate\Support\Collection
    {
        $items = collect($snapshots)->map(function (array $snapshot) {
            return new PaymentRequestItem([
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
        });

        return $this->approvalRowsFromItems($items);
    }

    protected function approvalRowsFromItems($items): \Illuminate\Support\Collection
    {
        return collect($items)
            ->map(fn (PaymentRequestItem $item) => $this->approvalRowFromItem($item))
            ->groupBy('merge_key')
            ->map(fn ($rows) => $this->mergeApprovalRows($rows))
            ->sortBy([
                ['vendor_name', 'asc'],
                ['style', 'asc'],
                ['material_type', 'asc'],
                ['material_po_number', 'asc'],
            ])
            ->values();
    }

    protected function approvalRowFromItem(PaymentRequestItem $item): array
    {
        $itemIdentity = collect([
            data_get($item->data, 'material_description'),
            $item->material_description,
            data_get($item->data, 'description'),
            $item->sap_code,
            $item->material_color,
            data_get($item->data, 'material_type'),
            $item->material_type,
        ])->map(fn ($value) => trim((string) $value))->filter()->implode('|');

        $style = $item->style_name ?: data_get($item->data, 'style_name');

        return [
            'merge_key' => $this->mergeKey($style, $itemIdentity, $item->supplier_name, $item->po_no, $item->pi_number),
            'booking_po_ids' => collect([$item->booking_po_id])->filter()->values()->all(),
            'vendor_name' => $item->supplier_name ?: '-',
            'style' => $style ?: '-',
            'pcd_required' => $this->reportDate(data_get($item->data, 'pcd_required')),
            'payment_term' => $item->payment_term ?: data_get($item->data, 'payment_term') ?: '-',
            'material_po_number' => $item->po_no ?: data_get($item->data, 'po_no') ?: '-',
            'material_pi_number' => $item->pi_number ?: data_get($item->data, 'pi_number') ?: '-',
            'material_type' => data_get($item->data, 'material_type') ?: $item->material_description ?: $item->material_type ?: '-',
            'contract_shipment' => $this->reportDate(data_get($item->data, 'contract_shipment')),
            'committed_ex_mill' => $this->reportDate(data_get($item->data, 'committed_ex_mill')),
            'comments' => $item->remarks ?: data_get($item->data, 'remarks') ?: data_get($item->data, 'comments') ?: '(blank)',
            'pi_amount' => (float) $item->pi_amount,
            'payment_required_date' => $item->payment_required_date ?: data_get($item->data, 'payment_required_date'),
            'buyer_name' => $item->buyer_name,
            'season_name' => $item->season_name,
            'payment_status' => $item->payment_status,
            'final_status' => data_get($item->data, 'final_status'),
            'vendor_type' => data_get($item->data, 'vendor_type'),
            'shipment_month' => data_get($item->data, 'shipment_month'),
            'delivery_term' => $item->delivery_term,
            'source_count' => 1,
        ];
    }

    protected function mergeApprovalRows($rows): array
    {
        $first = $rows->first();
        $paymentRequiredDate = $rows
            ->map(fn ($row) => $this->sourceService->dateValue($row['payment_required_date'] ?? null))
            ->filter()
            ->sortBy(fn ($date) => $date->timestamp)
            ->first();

        return [
            'booking_po_ids' => $rows->flatMap(fn ($row) => $row['booking_po_ids'] ?? [])->filter()->unique()->values()->all(),
            'vendor_name' => $this->joinUnique($rows->pluck('vendor_name')), 
            'style' => $this->joinUnique($rows->pluck('style')), 
            'pcd_required' => $this->joinUnique($rows->pluck('pcd_required')), 
            'payment_term' => $this->joinUnique($rows->pluck('payment_term')), 
            'material_po_number' => $this->joinUnique($rows->pluck('material_po_number')), 
            'material_pi_number' => $this->joinUnique($rows->pluck('material_pi_number')), 
            'material_type' => $this->joinUnique($rows->pluck('material_type')), 
            'contract_shipment' => $this->joinUnique($rows->pluck('contract_shipment')), 
            'committed_ex_mill' => $this->joinUnique($rows->pluck('committed_ex_mill')), 
            'comments' => $this->joinUnique($rows->pluck('comments'), '(blank)', '; '), 
            'pi_amount' => $rows->sum(fn ($row) => (float) ($row['pi_amount'] ?? 0)),
            'payment_required_date' => $paymentRequiredDate ?: ($first['payment_required_date'] ?? null),
            'buyer_name' => $this->joinUnique($rows->pluck('buyer_name')), 
            'season_name' => $this->joinUnique($rows->pluck('season_name')), 
            'payment_status' => $this->joinUnique($rows->pluck('payment_status')), 
            'final_status' => $this->joinUnique($rows->pluck('final_status')), 
            'vendor_type' => $this->joinUnique($rows->pluck('vendor_type')), 
            'shipment_month' => $this->joinUnique($rows->pluck('shipment_month')), 
            'delivery_term' => $this->joinUnique($rows->pluck('delivery_term')), 
            'source_count' => $rows->sum(fn ($row) => (int) ($row['source_count'] ?? 1)),
        ];
    }

    protected function summaryFromApprovalRows($rows): array
    {
        $rows = collect($rows);
        $dateValues = $rows->map(fn ($row) => $this->sourceService->dateValue($row['payment_required_date'] ?? null))->filter();
        $earliestPaymentRequired = $dateValues->sortBy(fn ($date) => $date->timestamp)->first();

        return [
            'total_pi_amount' => $rows->sum(fn (array $row) => (float) ($row['pi_amount'] ?? 0)),
            'total_budget' => 0,
            'total_savings' => 0,
            'total_po_count' => $rows->pluck('material_po_number')->flatMap(fn ($value) => explode(',', (string) $value))->map(fn ($value) => trim($value))->filter()->unique(fn ($value) => Str::lower($value))->count(),
            'pending_payment_count' => $rows->filter(fn (array $row) => $this->sourceService->normalize($row['payment_status'] ?? '') === 'pmt_pending')->count(),
            'earliest_payment_required_date' => $earliestPaymentRequired,
            'payment_status_summary' => $rows->countBy('payment_status')->all(),
            'pi_numbers' => $this->uniqueRowValues($rows, 'material_pi_number'),
            'payment_terms' => $this->uniqueRowValues($rows, 'payment_term'),
            'delivery_terms' => $this->uniqueRowValues($rows, 'delivery_term'),
            'buyers' => $this->uniqueRowValues($rows, 'buyer_name'),
            'seasons' => $this->uniqueRowValues($rows, 'season_name'),
            'suppliers' => $this->uniqueRowValues($rows, 'vendor_name'),
            'shipment_months' => $this->uniqueRowValues($rows, 'shipment_month'),
            'vendor_types' => $this->uniqueRowValues($rows, 'vendor_type'),
            'final_statuses' => $this->uniqueRowValues($rows, 'final_status'),
            'payment_statuses' => $this->uniqueRowValues($rows, 'payment_status'),
            'material_types' => $this->uniqueRowValues($rows, 'material_type'),
        ];
    }

    protected function uniqueRowValues($rows, string $key): array
    {
        return collect($rows)
            ->pluck($key)
            ->flatMap(fn ($value) => explode(',', (string) $value))
            ->map(fn ($value) => trim((string) $value))
            ->reject(fn ($value) => $value === '' || $value === '-')
            ->unique(fn ($value) => Str::lower($value))
            ->sort()
            ->values()
            ->all();
    }

    protected function joinUnique($values, string $fallback = '-', string $glue = ', '): string
    {
        $values = collect($values)
            ->map(fn ($value) => trim((string) $value))
            ->reject(fn ($value) => $value === '' || $value === '-')
            ->unique(fn ($value) => Str::lower($value))
            ->values();

        return $values->isEmpty() ? $fallback : $values->implode($glue);
    }

    protected function firstFilled(array $values): string
    {
        foreach ($values as $value) {
            $value = trim((string) $value);
            if ($value !== '' && $value !== '-') {
                return $value;
            }
        }

        return '';
    }

    protected function mergeKey(...$values): string
    {
        return collect($values)
            ->map(fn ($value) => $this->sourceService->normalize($value ?? ''))
            ->implode('|');
    }

    protected function reportDate($value): string
    {
        $date = $this->sourceService->dateValue($value);

        return $date ? $date->format('d-M-y') : (trim((string) $value) ?: '-');
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

    protected function downloadXlsx(PaymentRequest $paymentRequest, array $summary, string $fileName, $approvalRows = null)
    {
        $approvalRows = $approvalRows ? collect($approvalRows) : $this->approvalRowsFromItems($paymentRequest->items);

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Payment Approval');
        $sheet->getSheetView()->setShowGridLines(false);

        $lastColumn = 'K';
        $list = fn ($values, $fallback = '-') => collect($values ?? [])->map(fn ($value) => trim((string) $value))->filter()->take(5)->implode(', ') ?: $fallback;
        $moneyFormat = '$#,##0.00;[Red]($#,##0.00)';
        $paymentRequiredDate = $summary['earliest_payment_required_date'] ?? null;
        $paymentRequired = $paymentRequiredDate ? optional($paymentRequiredDate)->format('d / m / Y') : '-';
        $paymentRequiredParts = $paymentRequiredDate
            ? [optional($paymentRequiredDate)->format('d'), optional($paymentRequiredDate)->format('m'), optional($paymentRequiredDate)->format('Y')]
            : ['-', '-', '-'];

        $logoPath = public_path('images/humana-logo.png');
        if (file_exists($logoPath)) {
            $drawing = new \PhpOffice\PhpSpreadsheet\Worksheet\Drawing();
            $drawing->setName('Logo');
            $drawing->setPath($logoPath);
            $drawing->setHeight(44);
            $drawing->setCoordinates('A1');
            $drawing->setOffsetX(8);
            $drawing->setOffsetY(4);
            $drawing->setWorksheet($sheet);
        }

        $sheet->mergeCells('A1:B3');
        $sheet->mergeCells('D1:H1');
        $sheet->mergeCells('D2:H2');
        $sheet->mergeCells('I1:K1');
        $sheet->setCellValue('A1', 'HUMANA' . "\n" . 'APPARELS PVT. LTD.');
        $sheet->setCellValue('D1', 'Payment Request Approval');
        $sheet->setCellValue('D2', $paymentRequest->request_no);
        $sheet->setCellValue('I1', 'Date:  ' . optional($paymentRequest->created_at)->format('jS M-Y'));
        $sheet->getStyle('A1:K3')->getFont()->getColor()->setARGB('FF000B6F');
        $sheet->getStyle('A1:B3')->getFont()->setBold(true)->setSize(12);
        $sheet->getStyle('A1:B3')->getAlignment()->setWrapText(true)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
        $sheet->getStyle('D1')->getFont()->setBold(true)->setSize(20);
        $sheet->getStyle('D2')->getFont()->setSize(10);
        $sheet->getStyle('D1:H2')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('I1:K1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);

        $sheet->setCellValue('H3', 'Payment Require Date:');
        $sheet->setCellValue('I3', $paymentRequiredParts[0]);
        $sheet->setCellValue('J3', $paymentRequiredParts[1]);
        $sheet->setCellValue('K3', $paymentRequiredParts[2]);
        $sheet->getStyle('H3:K3')->getFont()->setBold(true)->getColor()->setARGB('FF000B6F');
        $sheet->getStyle('I3:K3')->applyFromArray([
            'borders' => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['argb' => 'FF8EA0D4']]],
            'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER],
        ]);

        $sheet->setCellValue('A5', 'Buyer');
        $sheet->setCellValue('B5', ':');
        $sheet->setCellValue('C5', $list($summary['buyers'] ?? [], $paymentRequest->buyer_name ?: '-'));
        $sheet->setCellValue('A6', 'Season');
        $sheet->setCellValue('B6', ':');
        $sheet->setCellValue('C6', $list($summary['seasons'] ?? [], $paymentRequest->season_name ?: '-'));
        $sheet->mergeCells('C5:E5');
        $sheet->mergeCells('C6:E6');
        $sheet->getStyle('A5:C6')->getFont()->setBold(true)->getColor()->setARGB('FF000B6F');

        $sheet->mergeCells('I5:K8');
        $sheet->setCellValue('I5', "OCR Checked:     ☐ Yes      ☐ No\n\nChecker Name     : ______________________\n\nDate             : ______________________");
        $sheet->getStyle('I5:K8')->applyFromArray([
            'font' => ['bold' => true, 'size' => 9, 'color' => ['argb' => 'FF000B6F']],
            'alignment' => ['wrapText' => true, 'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP],
            'borders' => ['outline' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['argb' => 'FF4B5FAE']]],
        ]);

        $sheet->mergeCells('A8:E9');
        $sheet->setCellValue('A8', "* Buyer nominated supplier.\n  No excess quantity has been booked.");
        $sheet->getStyle('A8:E9')->getFont()->getColor()->setARGB('FF000B6F');
        $sheet->getStyle('A8:E9')->getAlignment()->setWrapText(true);

        $sheet->mergeCells('I10:K10');
        $sheet->setCellValue('I10', 'Total PI Amount: $ ' . number_format((float) ($summary['total_pi_amount'] ?? 0), 2));
        $sheet->getStyle('I10:K10')->getFont()->setBold(true)->setSize(11)->getColor()->setARGB('FF000B6F');
        $sheet->getStyle('I10:K10')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);

        $headers = ['Vendor Name', 'Style', 'PCD Required', 'Payment Term', 'Material PO Number', 'Material PI Number', 'Material Type', 'Contract Shipment', 'Committed Ex Mill', 'Comments', 'PI Amount (USD)'];
        $tableStart = 11;
        $sheet->fromArray($headers, null, 'A' . $tableStart);
        $sheet->getStyle('A' . $tableStart . ':' . $lastColumn . $tableStart)->applyFromArray([
            'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF000B6F']],
            'font' => ['bold' => true, 'size' => 8, 'color' => ['argb' => 'FFFFFFFF']],
            'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER, 'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER, 'wrapText' => true],
            'borders' => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['argb' => 'FF4253A8']]],
        ]);
        $sheet->getRowDimension($tableStart)->setRowHeight(26);

        $rowNo = $tableStart + 1;
        foreach ($approvalRows as $row) {
            $sheet->fromArray([
                $row['vendor_name'] ?: '-',
                $row['style'] ?: '-',
                $row['pcd_required'] ?: '-',
                $row['payment_term'] ?: '-',
                $row['material_po_number'] ?: '-',
                $row['material_pi_number'] ?: '-',
                $row['material_type'] ?: '-',
                $row['contract_shipment'] ?: '-',
                $row['committed_ex_mill'] ?: '-',
                $row['comments'] ?: '(blank)',
                (float) ($row['pi_amount'] ?? 0),
            ], null, 'A' . $rowNo);
            $sheet->getStyle('A' . $rowNo . ':' . $lastColumn . $rowNo)->applyFromArray([
                'font' => ['size' => 8],
                'alignment' => ['vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER, 'wrapText' => true],
                'borders' => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['argb' => 'FFE4E7EF']]],
            ]);
            $rowNo++;
        }

        $sheet->setCellValue('A' . $rowNo, 'Grand Total');
        $sheet->setCellValue('K' . $rowNo, (float) ($summary['total_pi_amount'] ?? 0));
        $sheet->mergeCells('A' . $rowNo . ':J' . $rowNo);
        $sheet->getStyle('A' . $rowNo . ':' . $lastColumn . $rowNo)->applyFromArray([
            'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFEAF0FB']],
            'font' => ['bold' => true, 'size' => 10, 'color' => ['argb' => 'FF000B6F']],
            'borders' => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['argb' => 'FFE4E7EF']]],
        ]);
        $sheet->getStyle('K' . ($tableStart + 1) . ':K' . $rowNo)->getNumberFormat()->setFormatCode($moneyFormat);
        $sheet->getStyle('K' . ($tableStart + 1) . ':K' . $rowNo)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);
        $sheet->getStyle('C' . ($tableStart + 1) . ':C' . $rowNo)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('H' . ($tableStart + 1) . ':I' . $rowNo)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

        $rowNo += 4;
        $signatureBlocks = [
            ['A', 'C', 'Prepared By'],
            ['E', 'G', 'Checked By'],
            ['I', 'K', 'Approved By'],
        ];

        foreach ($signatureBlocks as [$from, $to, $title]) {
            $sheet->mergeCells($from . $rowNo . ':' . $to . $rowNo);
            $sheet->mergeCells($from . ($rowNo + 2) . ':' . $to . ($rowNo + 2));
            $sheet->setCellValue($from . $rowNo, $title);
            $sheet->setCellValue($from . ($rowNo + 2), 'Signature & Date');
            $sheet->getStyle($from . $rowNo . ':' . $to . ($rowNo + 3))->getFont()->getColor()->setARGB('FF000B6F');
            $sheet->getStyle($from . $rowNo . ':' . $to . $rowNo)->getFont()->setBold(true);
            $sheet->getStyle($from . ($rowNo + 3) . ':' . $to . ($rowNo + 3))->applyFromArray([
                'borders' => ['bottom' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['argb' => 'FF000B6F']]],
            ]);
        }

        $columnWidths = ['A' => 20, 'B' => 16, 'C' => 14, 'D' => 16, 'E' => 18, 'F' => 19, 'G' => 14, 'H' => 16, 'I' => 16, 'J' => 14, 'K' => 15];
        foreach ($columnWidths as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }

        foreach (range(1, $rowNo + 4) as $row) {
            $sheet->getRowDimension($row)->setRowHeight($row === $tableStart ? 26 : 22);
        }

        $sheet->getPageMargins()->setTop(0.25);
        $sheet->getPageMargins()->setBottom(0.25);
        $sheet->getPageMargins()->setLeft(0.25);
        $sheet->getPageMargins()->setRight(0.25);
        $sheet->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE);
        $sheet->getPageSetup()->setFitToWidth(1);
        $sheet->getPageSetup()->setFitToHeight(0);
        $sheet->freezePane('A12');

        $tmp = tempnam(sys_get_temp_dir(), 'payment_request_');
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save($tmp);

        return response()->download($tmp, $fileName)->deleteFileAfterSend(true);
    }
}
