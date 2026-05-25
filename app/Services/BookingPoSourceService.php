<?php

namespace App\Services;

use App\Models\AppNotification;
use App\Models\BookingPo;
use App\Models\ExcelHeader;
use App\Models\ExcelRow;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class BookingPoSourceService
{
    protected array $headerIdCache = [];

    public function sourceValueForBookingPo(BookingPo $bookingPo, string $group): ?string
    {
        $bookingPo->loadMissing(['excelRow.cells.header']);

        if ($bookingPo->excelRow) {
            $value = $this->sourceValueForRow($bookingPo->excelRow, $group);

            if (! $this->isBlankValue($value)) {
                return $value;
            }
        }

        return $this->fallbackValueFromBookingPo($bookingPo, $group);
    }

    public function sourceValueForRow(ExcelRow $row, string $group): ?string
    {
        $row->loadMissing(['cells.header']);

        $aliases = collect($this->headerAliases($group))
            ->map(fn ($alias) => $this->normalize($alias))
            ->filter()
            ->unique()
            ->values()
            ->all();

        foreach ($row->cells as $cell) {
            if (! $cell->header) {
                continue;
            }

            $value = trim((string) $cell->value);
            if ($this->isBlankValue($value)) {
                continue;
            }

            $headerKey = $this->normalize($cell->header->header_key);
            $headerName = $this->normalize($cell->header->header_name);
            $formulaKey = $this->normalize($cell->header->formula_key ?? '');

            if (in_array($headerKey, $aliases, true) || in_array($headerName, $aliases, true) || in_array($formulaKey, $aliases, true)) {
                return $value;
            }
        }

        foreach ($row->cells as $cell) {
            if (! $cell->header) {
                continue;
            }

            $value = trim((string) $cell->value);
            if ($this->isBlankValue($value)) {
                continue;
            }

            $headerKey = $this->normalize($cell->header->header_key);
            $headerName = $this->normalize($cell->header->header_name);

            if ($this->fallbackMatch($group, $headerKey) || $this->fallbackMatch($group, $headerName)) {
                return $value;
            }
        }

        return null;
    }

    public function bookingPoPiStatus(BookingPo $bookingPo): string
    {
        $status = trim((string) $this->sourceValueForBookingPo($bookingPo, 'pi_status'));
        $piNumber = $this->sourceValueForBookingPo($bookingPo, 'pi_number');

        if ($this->normalizeStatus($status) === 'pi_received' || ! $this->isBlankValue($piNumber)) {
            return 'PI Received';
        }

        if ($this->hasRealPoNumber($bookingPo->po_no)) {
            return 'PI Pending';
        }

        return 'Waiting for PO';
    }

    public function bookingPoHasPiReceived(BookingPo $bookingPo): bool
    {
        return $this->bookingPoPiStatus($bookingPo) === 'PI Received';
    }

    public function bookingPoPaymentStatus(BookingPo $bookingPo): string
    {
        $status = trim((string) $this->sourceValueForBookingPo($bookingPo, 'payment_status'));
        $pmtDocNo = $this->sourceValueForBookingPo($bookingPo, 'pmt_doc_no');

        if (! $this->bookingPoHasPiReceived($bookingPo)) {
            return $this->bookingPoPiStatus($bookingPo);
        }

        if (! $this->isBlankValue($status) && ! in_array($this->normalizeStatus($status), ['pi_pending', 'waiting_for_po'], true)) {
            return $status;
        }

        return $this->isBlankValue($pmtDocNo) ? 'Pmt Pending' : 'Pmt Done';
    }

    public function paymentSnapshot(BookingPo $bookingPo): array
    {
        $bookingPo->loadMissing(['excelFile.uploader', 'excelRow.cells.header', 'generatedBy']);

        $materialsOrdered = $this->numericValue(
            $this->sourceValueForBookingPo($bookingPo, 'materials_ordered')
            ?? $this->sourceValueForBookingPo($bookingPo, 'qty')
            ?? $bookingPo->qty
        );

        $piRate = $this->numericValue($this->sourceValueForBookingPo($bookingPo, 'pi_rate')) ?? 0.0;
        $sourcePiAmount = $this->numericValue($this->sourceValueForBookingPo($bookingPo, 'pi_amount'));
        $piAmount = $sourcePiAmount ?? ($piRate * ($materialsOrdered ?? 0));
        $budget = $this->numericValue($this->sourceValueForBookingPo($bookingPo, 'budget'));
        $sourceSavings = $this->numericValue($this->sourceValueForBookingPo($bookingPo, 'savings'));
        $savings = $sourceSavings ?? ($budget !== null ? ($budget - $piAmount) : null);

        $paymentRequiredDate = $this->paymentRequiredDateForBookingPo($bookingPo);
        $contractShipment = $this->dateValue($this->sourceValueForBookingPo($bookingPo, 'contract_shipment'));
        $committedExMill = $this->dateValue($this->sourceValueForBookingPo($bookingPo, 'committed_ex_mill'));
        $pcdRequired = $this->dateValue($this->sourceValueForBookingPo($bookingPo, 'pcd_required'));
        $committedEtd = $this->dateValue($this->sourceValueForBookingPo($bookingPo, 'committed_etd'));
        $committedEta = $this->dateValue($this->sourceValueForBookingPo($bookingPo, 'committed_eta'));
        $paymentStatus = $this->bookingPoPaymentStatus($bookingPo);
        $piNumber = $this->sourceValueForBookingPo($bookingPo, 'pi_number');
        $comments = $this->sourceValueForBookingPo($bookingPo, 'comments')
            ?: $this->sourceValueForBookingPo($bookingPo, 'remarks')
            ?: $bookingPo->remarks;

        $shipmentMonth = $this->sourceValueForBookingPo($bookingPo, 'shipment_month');
        if ($this->isBlankValue($shipmentMonth)) {
            $shipmentMonth = $this->shipmentMonthFromDate($contractShipment ?? $committedExMill ?? $committedEtd);
        }

        $snapshot = [
            'booking_po_id' => $bookingPo->id,
            'excel_file_id' => $bookingPo->excel_file_id,
            'excel_row_id' => $bookingPo->excel_row_id,
            'po_no' => $bookingPo->po_no,
            'supplier_name' => $this->sourceValueForBookingPo($bookingPo, 'vendor') ?: $bookingPo->vendor_name,
            'vendor_type' => $this->sourceValueForBookingPo($bookingPo, 'vendor_type'),
            'buyer_name' => $this->sourceValueForBookingPo($bookingPo, 'buyer') ?: $bookingPo->buyer_name,
            'season_name' => $this->sourceValueForBookingPo($bookingPo, 'season') ?: $bookingPo->season_name,
            'shipment_month' => $shipmentMonth,
            'style_name' => $this->sourceValueForBookingPo($bookingPo, 'style') ?: $bookingPo->style_name,
            'contract_shipment' => $this->formatDate($contractShipment),
            'final_status' => $this->sourceValueForBookingPo($bookingPo, 'final_status'),
            'material_type' => $this->sourceValueForBookingPo($bookingPo, 'material_type') ?: $bookingPo->item_type,
            'material_description' => $this->sourceValueForBookingPo($bookingPo, 'material_description') ?: $bookingPo->description ?: $bookingPo->item_name,
            'sap_code' => $this->sourceValueForBookingPo($bookingPo, 'sap_code') ?: $bookingPo->supplier_article,
            'material_color' => $this->sourceValueForBookingPo($bookingPo, 'material_color') ?: $bookingPo->color,
            'qty' => $materialsOrdered ?? $this->numericValue($bookingPo->qty),
            'pi_number' => $piNumber,
            'pi_status' => $this->bookingPoPiStatus($bookingPo),
            'pi_rate' => $piRate,
            'pi_amount' => $piAmount,
            'budget' => $budget,
            'savings' => $savings,
            'delivery_term' => $this->sourceValueForBookingPo($bookingPo, 'delivery_term'),
            'payment_term' => $this->sourceValueForBookingPo($bookingPo, 'payment_term'),
            'ship_mode' => $this->sourceValueForBookingPo($bookingPo, 'ship_mode'),
            'forwarder' => $this->sourceValueForBookingPo($bookingPo, 'forwarder'),
            'committed_ex_mill' => $this->formatDate($committedExMill),
            'committed_etd' => $this->formatDate($committedEtd),
            'committed_eta' => $this->formatDate($committedEta),
            'pcd_required' => $this->formatDate($pcdRequired),
            'payment_required_date' => $this->formatDate($paymentRequiredDate),
            'payment_status' => $paymentStatus,
            'payment_doc_no' => $this->sourceValueForBookingPo($bookingPo, 'pmt_doc_no'),
            'comments' => $comments,
            'remarks' => $comments,
            'generated_at' => optional($bookingPo->generated_at)->format('Y-m-d H:i:s'),
            'created_by_name' => optional($bookingPo->generatedBy)->name,
        ];

        $snapshot['eligible_for_payment_request'] = $this->isEligibleForPaymentRequestSnapshot($snapshot, $bookingPo);

        return $snapshot;
    }

    public function isEligibleForPaymentRequest(BookingPo $bookingPo): bool
    {
        return $this->isEligibleForPaymentRequestSnapshot($this->paymentSnapshot($bookingPo), $bookingPo);
    }

    public function notifyPiMissingForBookingPo(BookingPo $bookingPo): int
    {
        $bookingPo->loadMissing(['excelFile.uploader', 'excelRow.cells.header', 'generatedBy']);

        $data = is_array($bookingPo->booking_data) ? $bookingPo->booking_data : [];
        $data['pi_alert_last_checked_at'] = now()->format('Y-m-d H:i:s');

        if ($this->bookingPoHasPiReceived($bookingPo)) {
            $data['pi_alert_resolved_at'] = $data['pi_alert_resolved_at'] ?? now()->format('Y-m-d H:i:s');
            $bookingPo->booking_data = $data;
            $bookingPo->save();

            return 0;
        }

        if (! $bookingPo->generated_at || $bookingPo->generated_at->gt(now()->subDays(3))) {
            $bookingPo->booking_data = $data;
            $bookingPo->save();

            return 0;
        }

        if (! empty($data['pi_alert_sent_at'])) {
            $bookingPo->booking_data = $data;
            $bookingPo->save();

            return 0;
        }

        $recipients = collect([
                $bookingPo->generated_by,
                optional($bookingPo->excelFile)->uploaded_by,
            ])
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($recipients->isEmpty()) {
            $bookingPo->booking_data = $data;
            $bookingPo->save();

            return 0;
        }

        $snapshot = $this->paymentSnapshot($bookingPo);
        $daysOverdue = max(0, (int) $bookingPo->generated_at->diffInDays(now()));
        $notificationData = [
            'booking_po_id' => $bookingPo->id,
            'po_no' => $bookingPo->po_no,
            'generated_at' => optional($bookingPo->generated_at)->format('Y-m-d H:i:s'),
            'days_overdue' => $daysOverdue,
            'buyer' => $snapshot['buyer_name'] ?? null,
            'season' => $snapshot['season_name'] ?? null,
            'vendor' => $snapshot['supplier_name'] ?? null,
            'style' => $snapshot['style_name'] ?? null,
        ];

        $created = 0;
        foreach ($recipients as $userId) {
            $user = User::find($userId);
            if (! $user) {
                continue;
            }

            AppNotification::create([
                'user_id' => $user->id,
                'actor_id' => $bookingPo->generated_by,
                'excel_file_id' => $bookingPo->excel_file_id,
                'type' => 'pi_missing_alert',
                'title' => 'PI Missing Alert: ' . $bookingPo->po_no,
                'message' => 'PI has not been received yet for PO ' . $bookingPo->po_no . '. Please follow up.',
                'url' => $this->piMissingAlertUrl($user, $bookingPo),
                'data' => $notificationData,
            ]);

            $created++;
        }

        $data['pi_alert_sent_at'] = now()->format('Y-m-d H:i:s');
        $data['pi_alert_sent_to'] = $recipients->all();
        $bookingPo->booking_data = $data;
        $bookingPo->save();

        return $created;
    }

    public function paymentRequiredDateForBookingPo(BookingPo $bookingPo): ?Carbon
    {
        $stored = $this->dateValue($this->sourceValueForBookingPo($bookingPo, 'payment_reqd_date'));
        if ($stored) {
            return $stored;
        }

        $committedExMill = $this->dateValue($this->sourceValueForBookingPo($bookingPo, 'committed_ex_mill'));

        return $committedExMill ? $committedExMill->copy()->subDays(7) : null;
    }

    public function numericValue($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = trim((string) $value);
        if ($this->isBlankValue($value)) {
            return null;
        }

        $isNegativeAccounting = preg_match('/^\(.*\)$/', $value) === 1;
        $value = str_replace(["\xC2\xA0", ',', ' ', '%', '(', ')'], '', $value);

        if (! is_numeric($value)) {
            return null;
        }

        $number = (float) $value;

        return $isNegativeAccounting ? -1 * $number : $number;
    }

    public function dateValue($value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value->copy();
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }

        if ($this->isBlankValue($value)) {
            return null;
        }

        if (is_numeric($value)) {
            try {
                return Carbon::create(1899, 12, 30)->addDays((int) $value);
            } catch (\Throwable $e) {
                return null;
            }
        }

        $value = trim((string) $value);

        if ($value === '' || $value === '-' || preg_match('/^[mdy\/-]+$/i', $value)) {
            return null;
        }

        $formats = [
            'Y-m-d', 'Y/m/d', 'd-m-Y', 'd/m/Y', 'm-d-Y', 'm/d/Y',
            'd-M-Y', 'd M Y', 'M d, Y', 'm/d/y', 'd/m/y', 'd.m.Y', 'Y.m.d',
        ];

        foreach ($formats as $format) {
            try {
                $date = Carbon::createFromFormat($format, $value);
                if ($date !== false) {
                    return $date;
                }
            } catch (\Throwable $e) {
                // Try the next format.
            }
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function shipmentMonthFromDate(?Carbon $date): ?string
    {
        return $date ? $date->format('M') : null;
    }

    public function formatDate(?Carbon $date): ?string
    {
        return $date ? $date->format('Y-m-d') : null;
    }

    public function normalize($value): string
    {
        $value = strtolower(trim((string) $value));
        $value = str_replace(['&', '+'], ' and ', $value);
        $value = str_replace(["'", '’'], '', $value);
        $value = preg_replace('/[^a-z0-9]+/', '_', $value);
        $value = preg_replace('/_+/', '_', $value);

        return trim($value, '_');
    }

    protected function isEligibleForPaymentRequestSnapshot(array $snapshot, BookingPo $bookingPo): bool
    {
        $status = $this->normalizeStatus($snapshot['payment_status'] ?? '');

        if (! $this->hasRealPoNumber($snapshot['po_no'] ?? $bookingPo->po_no)) {
            return false;
        }

        if ($this->isBlankValue($snapshot['pi_number'] ?? null) || $this->normalizeStatus($snapshot['pi_status'] ?? '') !== 'pi_received') {
            return false;
        }

        if (! $this->isBlankValue($snapshot['payment_doc_no'] ?? null)) {
            return false;
        }

        return ! in_array($status, ['pmt_done', 'paid', 'payment_done', 'done', 'completed', 'complete'], true);
    }

    protected function fallbackValueFromBookingPo(BookingPo $bookingPo, string $group): ?string
    {
        return match ($group) {
            'po_no' => $bookingPo->po_no,
            'buyer' => $bookingPo->buyer_name,
            'season' => $bookingPo->season_name,
            'vendor' => $bookingPo->vendor_name,
            'style' => $bookingPo->style_name,
            'material_type' => $bookingPo->item_type,
            'material_description', 'item' => $bookingPo->description ?: $bookingPo->item_name,
            'sap_code' => $bookingPo->supplier_article,
            'material_color', 'color' => $bookingPo->color,
            'qty', 'materials_ordered' => $bookingPo->qty !== null ? (string) $bookingPo->qty : null,
            'remarks' => $bookingPo->remarks,
            default => null,
        };
    }

    protected function piMissingAlertUrl(User $user, BookingPo $bookingPo): string
    {
        if ($user->hasRole('supply_chain')) {
            return route('supply_chain.bookings.show', $bookingPo);
        }

        if ($user->hasRole('merchant') && $bookingPo->excel_file_id) {
            return route('uploaded-files.show', $bookingPo->excel_file_id);
        }

        return route('dashboard');
    }

    protected function hasRealPoNumber($value): bool
    {
        if ($this->isBlankValue($value)) {
            return false;
        }

        return ! in_array($this->normalizeStatus($value), [
            'na', 'n_a', 'none', 'nil', 'no', 'not_available', 'pending', 'po_pending',
            'waiting_for_po', 'wait_for_po', 'blank', 'empty', '0', '-', '--', '.',
        ], true);
    }

    protected function isBlankValue($value): bool
    {
        if ($value === null) {
            return true;
        }

        $value = trim((string) $value);
        if ($value === '') {
            return true;
        }

        $normalized = $this->normalizeStatus($value);

        if ($normalized === '') {
            return true;
        }

        return in_array($normalized, [
            'na', 'n_a', 'n/a', 'none', 'nil', 'no', 'not_available', 'blank', 'empty',
            '0', '-', '--', '.', 'mm/dd/yyyy', 'dd/mm/yyyy', 'yyyy_mm_dd', 'yyyy-mm-dd',
        ], true);
    }

    protected function normalizeStatus($value): string
    {
        return $this->normalize($value);
    }

    protected function fallbackMatch(string $group, string $header): bool
    {
        return match ($group) {
            'buyer' => str_contains($header, 'buyer') && ! str_contains($header, 'liability') && ! str_contains($header, 'value'),
            'season' => str_contains($header, 'season'),
            'vendor' => str_contains($header, 'vendor') || str_contains($header, 'supplier'),
            'style' => str_contains($header, 'style') || str_contains($header, 'contract_number') || str_contains($header, 'sales_contract'),
            'shipment_month' => str_contains($header, 'shipment_month') || str_contains($header, 'ship_month'),
            'vendor_type' => str_contains($header, 'vendor_type') || str_contains($header, 'supplier_type'),
            'final_status' => str_contains($header, 'final_status'),
            'material_type' => str_contains($header, 'material_type') || str_contains($header, 'item_type'),
            'material_description' => str_contains($header, 'material_description') || $header === 'description' || str_contains($header, 'description'),
            'sap_code' => str_contains($header, 'sap_code'),
            'material_color' => str_contains($header, 'material_color') || str_contains($header, 'gmts_color') || str_contains($header, 'color') || str_contains($header, 'colour'),
            'materials_ordered' => str_contains($header, 'materials_ordered') || str_contains($header, 'material_ordered') || str_contains($header, 'materials_to_be_ordered'),
            'qty' => str_contains($header, 'qty') || str_contains($header, 'quantity'),
            'pi_number' => str_contains($header, 'pi_number') || str_contains($header, 'vendor_pi_number'),
            'pi_status' => str_contains($header, 'pi_status'),
            'pi_rate' => str_contains($header, 'pi_rate') || str_contains($header, 'invoiced_rate_scm') || $header === 'invoiced_rate',
            'pi_amount' => str_contains($header, 'pi_amount'),
            'payment_reqd_date' => str_contains($header, 'payment_reqd_date') || str_contains($header, 'payment_required_date') || str_contains($header, 'payment_due_date'),
            'pcd_required' => str_contains($header, 'pcd_required') || str_contains($header, 'pcd_req') || str_contains($header, 'pcd_date'),
            'payment_status' => str_contains($header, 'payment_status'),
            'pmt_doc_no' => str_contains($header, 'pmt_doc_no') || str_contains($header, 'payment_doc_no') || str_contains($header, 'payment_reference_number') || str_contains($header, 'payment_ref_no'),
            'delivery_term' => str_contains($header, 'delivery_term'),
            'payment_term' => str_contains($header, 'payment_term'),
            'ship_mode' => str_contains($header, 'ship_mode'),
            'forwarder' => str_contains($header, 'forwarder'),
            'contract_shipment' => str_contains($header, 'contract_shipment') || str_contains($header, 'shipment_date'),
            'committed_etd' => str_contains($header, 'committed_etd') || str_contains($header, 'commited_etd'),
            'committed_eta' => str_contains($header, 'committed_eta'),
            'committed_ex_mill' => str_contains($header, 'committed_ex_mill') || str_contains($header, 'committed_x_fty') || str_contains($header, 'committed_ex_fty') || str_contains($header, 'committed_exmill'),
            'budget' => str_contains($header, 'budget'),
            'savings' => str_contains($header, 'saving'),
            'comments' => $header === 'comments' || str_contains($header, 'comments'),
            'remarks' => str_contains($header, 'remarks') || str_contains($header, 'comments'),
            default => false,
        };
    }

    protected function headerAliases(string $group): array
    {
        return match ($group) {
            'po_no' => ['material_po_number', 'material_po_no', 'material_purchase_order_number', 'material_purchase_order_no', 'PO Number'],
            'buyer' => ['buyer_name', 'buyer', 'Buyer Name'],
            'season' => ['season_name', 'season', 'Season Name'],
            'vendor' => ['vendor_name', 'supplier_name', 'supplier', 'vendor', 'Vendor Name'],
            'style' => ['style_name', 'style_no', 'style_order', 'order_style_no', 'order_style', 'initial_contract_number', 'contract_number', 'sales_contract', 'Style Name'],
            'shipment_month' => ['shipment_month', 'shipment_mth', 'ship_month', 'Shipment Month'],
            'vendor_type' => ['vendor_type', 'supplier_type', 'Vendor Type', 'Supplier Type'],
            'final_status' => ['final_status', 'Final Status'],
            'material_type' => ['material_type', 'item_type', 'material_category', 'Material Type', 'Item Type'],
            'item' => ['material_type', 'item_name', 'item', 'material_description', 'description'],
            'material_description' => ['material_description', 'description', 'Material Description', 'Description'],
            'sap_code' => ['sap_code', 'supplier_article', 'SAP Code'],
            'material_color' => ['material_color', 'gmts_color_name', 'gmts_colour_name', 'color', 'colour', 'Material Color'],
            'qty' => ['materials_to_be_ordered', 'material_to_be_ordered', 'materials_ordered', 'material_ordered', 'materials_to_be_order', 'booking_qty', 'booking_quantity', 'qty'],
            'materials_ordered' => ['materials_ordered', 'material_ordered', 'materials_to_be_ordered', 'material_to_be_ordered', 'materials_order_qty', 'material_order_qty', 'Materials Ordered'],
            'pi_number' => ['material_pi_number', 'pi_number', 'vendor_pi_number', 'Material PI Number', 'PI Number', 'Vendor PI Number'],
            'pi_status' => ['pi_status', 'PI Status'],
            'pi_rate' => ['pi_rate', 'invoiced_rate_scm', 'invoiced_rate', 'PI Rate', 'Invoiced Rate(SCM)', 'Invoiced Rate'],
            'pi_amount' => ['pi_amount', 'PI Amount'],
            'payment_reqd_date' => ['payment_reqd_date', 'payment_req_d_date', 'payment_required_date', 'payment_due_date', "Payment Req'd Date", 'Payment Reqd Date', 'Payment Required Date', 'Payment Due Date'],
            'pcd_required' => ['pcd_required', 'pcd_required_date', 'pcd_req_date', 'pcd_date', 'PCD Required', 'PCD Required Date'],
            'payment_status' => ['payment_status', 'Payment Status'],
            'pmt_doc_no' => ['pmt_doc_no', 'payment_doc_no', 'payment_reference_number', 'payment_ref_no', 'Pmt Doc No', 'Payment Doc No', 'Payment Reference Number'],
            'delivery_term' => ['delivery_term', 'Delivery Term'],
            'payment_term' => ['payment_term', 'Payment Term'],
            'ship_mode' => ['ship_mode', 'Ship Mode'],
            'forwarder' => ['forwarder', 'Forwarder'],
            'contract_shipment' => ['contract_shipment', 'contract_shipment_date', 'shipment_date', 'Contract Shipment', 'Contract Shipment Date'],
            'committed_etd' => ['committed_etd', 'commited_etd', 'Committed ETD', 'Commited ETD'],
            'committed_eta' => ['committed_eta', 'Committed ETA'],
            'committed_ex_mill' => ['committed_ex_mill', 'committed_x_fty_date', 'committed_ex_fty_date', 'committed_x_fty', 'committed_ex_fty', 'Committed Ex Mill', 'Committed Ex-Mill'],
            'budget' => ['budget', 'Budget'],
            'savings' => ['savings', 'saving', 'Savings'],
            'comments' => ['comments', 'Comments'],
            'remarks' => ['remarks', 'comments', 'merchant_remarks', 'supply_chain_remarks', 'Supply Chain Remarks', 'Comments'],
            default => [],
        };
    }
}
