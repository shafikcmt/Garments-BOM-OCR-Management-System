<?php

namespace App\Services;

use App\Mail\PiMissingAlertMail;
use App\Models\AppNotification;
use App\Models\BookingPo;
use App\Models\ExcelCell;
use App\Models\ExcelHeader;
use App\Models\ExcelRow;
use App\Models\User;
use App\Support\PiAlertSettings;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class BookingPoSourceService
{
    protected array $headerIdCache = [];

    protected ?Collection $poNumberHeaderIdsCache = null;

    /** @var array<string, Collection<int, int>> */
    protected array $headerIdsByGroup = [];

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

        // A generated PO can span several worksheet rows (multiple styles/lines)
        // that all share the same PO number, but only the primary row owns a
        // BookingPo record. Aggregate every sibling row that carries this same
        // PO number so PI Amount, Budget, Savings and Qty reflect the WHOLE PO
        // (matching the Booking Format total) instead of only the primary line.
        // Single-row bookings have no siblings, so their output is unchanged.
        $style = $this->sourceValueForBookingPo($bookingPo, 'style') ?: $bookingPo->style_name;
        $sapCode = $this->sourceValueForBookingPo($bookingPo, 'sap_code') ?: $bookingPo->supplier_article;
        $materialType = $this->sourceValueForBookingPo($bookingPo, 'material_type') ?: $bookingPo->item_type;
        $materialDescription = $this->sourceValueForBookingPo($bookingPo, 'material_description') ?: $bookingPo->description ?: $bookingPo->item_name;

        foreach ($this->siblingSourceRows($bookingPo) as $siblingRow) {
            $rowMaterials = $this->numericValue(
                $this->sourceValueForRow($siblingRow, 'materials_ordered')
                ?? $this->sourceValueForRow($siblingRow, 'qty')
            );
            $rowRate = $this->numericValue($this->sourceValueForRow($siblingRow, 'pi_rate')) ?? 0.0;
            $rowPiAmount = $this->numericValue($this->sourceValueForRow($siblingRow, 'pi_amount'))
                ?? ($rowRate * ($rowMaterials ?? 0));
            $piAmount += $rowPiAmount;

            $rowBudget = $this->numericValue($this->sourceValueForRow($siblingRow, 'budget'));
            if ($rowBudget !== null) {
                $budget = ($budget ?? 0) + $rowBudget;
            }

            $rowSavings = $this->numericValue($this->sourceValueForRow($siblingRow, 'savings'));
            if ($rowSavings !== null) {
                $sourceSavings = ($sourceSavings ?? 0) + $rowSavings;
            }

            if ($rowMaterials !== null) {
                $materialsOrdered = ($materialsOrdered ?? 0) + $rowMaterials;
            }

            $piNumber = $this->joinDistinct([$piNumber, $this->sourceValueForRow($siblingRow, 'pi_number')]) ?: $piNumber;
            $style = $this->joinDistinct([$style, $this->sourceValueForRow($siblingRow, 'style')]) ?: $style;
            $sapCode = $this->joinDistinct([$sapCode, $this->sourceValueForRow($siblingRow, 'sap_code')]) ?: $sapCode;
            $materialType = $this->joinDistinct([$materialType, $this->sourceValueForRow($siblingRow, 'material_type')]) ?: $materialType;
            $materialDescription = $this->joinDistinct([$materialDescription, $this->sourceValueForRow($siblingRow, 'material_description')]) ?: $materialDescription;
        }

        $savings = $sourceSavings ?? ($budget !== null ? ($budget - $piAmount) : null);

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
            'style_name' => $style,
            'contract_shipment' => $this->formatDate($contractShipment),
            'final_status' => $this->sourceValueForBookingPo($bookingPo, 'final_status'),
            'material_type' => $materialType,
            'material_description' => $materialDescription,
            'sap_code' => $sapCode,
            'material_color' => $this->sourceValueForBookingPo($bookingPo, 'material_color') ?: $bookingPo->color,
            'size' => $this->sourceValueForBookingPo($bookingPo, 'size') ?: $bookingPo->size_width,
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

    public function notifyPiMissingForBookingPo(BookingPo $bookingPo, ?int $thresholdDays = null): int
    {
        $thresholdDays = $thresholdDays !== null ? max(1, $thresholdDays) : PiAlertSettings::days();

        $bookingPo->loadMissing(['excelFile.uploader', 'excelRow.cells.header', 'generatedBy']);

        $data = is_array($bookingPo->booking_data) ? $bookingPo->booking_data : [];
        $data['pi_alert_last_checked_at'] = now()->format('Y-m-d H:i:s');

        if ($this->bookingPoHasPiReceived($bookingPo)) {
            $data['pi_alert_resolved_at'] = $data['pi_alert_resolved_at'] ?? now()->format('Y-m-d H:i:s');
            $bookingPo->booking_data = $data;
            $bookingPo->save();

            return 0;
        }

        if (! $bookingPo->generated_at || $bookingPo->generated_at->gt(now()->subDays($thresholdDays))) {
            $bookingPo->booking_data = $data;
            $bookingPo->save();

            return 0;
        }

        if (! empty($data['pi_alert_sent_at'])) {
            $bookingPo->booking_data = $data;
            $bookingPo->save();

            return 0;
        }

        $recipients = $this->piAlertRecipientUsers($bookingPo);

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
        foreach ($recipients as $user) {
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

        $this->sendPiMissingMail($recipients, $notificationData);

        $data['pi_alert_sent_at'] = now()->format('Y-m-d H:i:s');
        $data['pi_alert_sent_to'] = $recipients->pluck('id')->all();
        $bookingPo->booking_data = $data;
        $bookingPo->save();

        return $created;
    }

    /**
     * Resolve the users who should receive the PI missing alert, based on the
     * admin-configured department visibility setting. Falls back to the PO
     * creator and file uploader when no department is configured.
     *
     * @return Collection<int, User>
     */
    protected function piAlertRecipientUsers(BookingPo $bookingPo): Collection
    {
        $departments = PiAlertSettings::departments();

        if (! empty($departments)) {
            return User::whereHas('roles', function ($query) use ($departments) {
                    $query->whereIn('name', $departments);
                })
                ->get()
                ->unique('id')
                ->values();
        }

        $fallbackIds = collect([
                $bookingPo->generated_by,
                optional($bookingPo->excelFile)->uploaded_by,
            ])
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($fallbackIds->isEmpty()) {
            return collect();
        }

        return User::whereIn('id', $fallbackIds)->get();
    }

    /**
     * Send the PI missing alert email when enabled by the admin.
     *
     * @param Collection<int, User> $recipients
     * @param array<string, mixed> $notificationData
     */
    protected function sendPiMissingMail(Collection $recipients, array $notificationData): void
    {
        if (! PiAlertSettings::mailEnabled()) {
            return;
        }

        if (PiAlertSettings::mailRecipientsMode() === 'specific') {
            $emails = PiAlertSettings::mailEmails();
        } else {
            $emails = $recipients
                ->pluck('email')
                ->filter(fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL))
                ->unique()
                ->values()
                ->all();
        }

        if (empty($emails)) {
            return;
        }

        try {
            Mail::to($emails)->send(new PiMissingAlertMail($notificationData));
        } catch (\Throwable $e) {
            Log::error('PI missing alert mail failed: ' . $e->getMessage(), [
                'po_no' => $notificationData['po_no'] ?? null,
            ]);
        }
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

    /**
     * Every worksheet line belonging to this PO: the BookingPo's own primary row
     * plus the sibling rows that share its PO number, ordered as they appear in
     * the sheet.
     *
     * One PO number routinely covers several styles/materials, but only the
     * primary line owns a BookingPo record — so callers that need to present or
     * receive against "all items under this PO" (Store's Material Receiving)
     * must work from ExcelRow, not from booking_pos. Item-level values are then
     * read per row via sourceValueForRow().
     *
     * @return Collection<int, ExcelRow>
     */
    public function itemRowsForBookingPo(BookingPo $bookingPo): Collection
    {
        $bookingPo->loadMissing('excelRow.cells.header');

        $rows = collect();

        if ($bookingPo->excelRow) {
            $rows->push($bookingPo->excelRow);
        }

        return $rows->merge($this->siblingSourceRows($bookingPo))
            ->unique('id')
            ->sortBy('row_number')
            ->values();
    }

    /**
     * All worksheet rows that share this BookingPo's PO number, excluding the
     * BookingPo's own primary row. These are the extra booking lines (other
     * styles/materials) that were merged into the same PO at generation time
     * but never received their own BookingPo record. Returns an empty
     * collection for single-row bookings or a not-yet-generated PO number.
     */
    protected function siblingSourceRows(BookingPo $bookingPo): Collection
    {
        $bookingPo->loadMissing('excelRow');
        $primaryRowId = $bookingPo->excel_row_id;
        $poNo = trim((string) $bookingPo->po_no);

        if ($poNo === '' || ! $this->hasRealPoNumber($poNo) || ! $bookingPo->excel_file_id) {
            return collect();
        }

        $headerIds = $this->poNumberHeaderIds();
        if ($headerIds->isEmpty()) {
            return collect();
        }

        $rowIds = ExcelCell::query()
            ->whereIn('header_id', $headerIds->all())
            ->whereRaw('TRIM(value) = ?', [$poNo])
            ->pluck('row_id')
            ->reject(fn ($id) => (int) $id === (int) $primaryRowId)
            ->unique()
            ->values();

        if ($rowIds->isEmpty()) {
            return collect();
        }

        return ExcelRow::query()
            ->whereIn('id', $rowIds->all())
            ->where('excel_file_id', $bookingPo->excel_file_id)
            ->with('cells.header')
            ->orderBy('row_number')
            ->get();
    }

    /**
     * Header ids whose key/name matches a PO-number alias, resolved once per
     * request. Used to locate every worksheet row carrying a given PO number.
     */
    protected function poNumberHeaderIds(): Collection
    {
        return $this->poNumberHeaderIdsCache ??= $this->headerIdsForGroup('po_no');
    }

    /**
     * Header ids whose key/name matches any alias of the given group, resolved
     * once per request per group.
     */
    protected function headerIdsForGroup(string $group): Collection
    {
        if (isset($this->headerIdsByGroup[$group])) {
            return $this->headerIdsByGroup[$group];
        }

        $aliases = collect($this->headerAliases($group))
            ->map(fn ($alias) => $this->normalize($alias))
            ->filter()
            ->unique();

        if ($aliases->isEmpty()) {
            return $this->headerIdsByGroup[$group] = collect();
        }

        return $this->headerIdsByGroup[$group] = ExcelHeader::query()
            ->get(['id', 'header_key', 'header_name'])
            ->filter(fn ($header) => $aliases->contains($this->normalize($header->header_key))
                || $aliases->contains($this->normalize($header->header_name)))
            ->pluck('id')
            ->values();
    }

    /**
     * Booking POs reachable by a partial, case-insensitive search on one field.
     *
     * Store knows a delivery by different handles depending on the paperwork in
     * front of them — the PO number, the vendor's PI number, or the SAP code of
     * the material. All three resolve to the same booking record here.
     *
     * PO number is a booking_pos column, so it matches directly. SAP code and PI
     * number live in the BOM cells, so the match runs over ExcelCell and is then
     * mapped back to the owning PO — either because the matched row IS a PO's
     * primary row, or because it carries that PO's number (a sibling line).
     *
     * A value may legitimately reach more than one PO (one SAP code can appear
     * under several POs), so this returns a list and the caller decides.
     *
     * @return Collection<int, BookingPo>
     */
    public function bookingPosMatching(string $group, string $term, int $limit = 50): Collection
    {
        $term = trim($term);

        if ($term === '') {
            return collect();
        }

        $like = '%'.mb_strtolower($term).'%';

        if ($group === 'po_no') {
            return BookingPo::query()
                ->whereRaw('LOWER(po_no) LIKE ?', [$like])
                ->orderByDesc('id')
                ->limit($limit)
                ->get();
        }

        $headerIds = $this->headerIdsForGroup($group);

        $rowIds = $headerIds->isEmpty() ? collect() : ExcelCell::query()
            ->whereIn('header_id', $headerIds->all())
            ->whereRaw('LOWER(TRIM(value)) LIKE ?', [$like])
            ->pluck('row_id')
            ->unique()
            ->values();

        // PO numbers carried by the matched rows — this is what links a sibling
        // line back to the PO that owns it.
        $poNos = $rowIds->isEmpty() ? collect() : ExcelCell::query()
            ->whereIn('row_id', $rowIds->all())
            ->whereIn('header_id', $this->poNumberHeaderIds()->all())
            ->pluck('value')
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->unique()
            ->values();

        $query = BookingPo::query();
        $matched = false;

        if ($rowIds->isNotEmpty()) {
            $query->whereIn('excel_row_id', $rowIds->all());
            $matched = true;
        }

        if ($poNos->isNotEmpty()) {
            $matched
                ? $query->orWhereIn('po_no', $poNos->all())
                : $query->whereIn('po_no', $poNos->all());
            $matched = true;
        }

        // SAP code also has a dedicated booking_pos column, so a primary line
        // still matches when its BOM cell is blank.
        if ($group === 'sap_code') {
            $matched
                ? $query->orWhereRaw('LOWER(supplier_article) LIKE ?', [$like])
                : $query->whereRaw('LOWER(supplier_article) LIKE ?', [$like]);
            $matched = true;
        }

        if (! $matched) {
            return collect();
        }

        return $query->orderByDesc('id')->limit($limit)->get();
    }

    /**
     * Comma-join distinct, non-blank values (case-insensitive). Returns null
     * when nothing is left, so callers can fall back to the original value.
     */
    protected function joinDistinct(array $values): ?string
    {
        $joined = collect($values)
            ->map(fn ($value) => trim((string) $value))
            ->reject(fn ($value) => $value === '')
            ->unique(fn ($value) => mb_strtolower($value))
            ->values();

        return $joined->isEmpty() ? null : $joined->implode(', ');
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
            'material_name' => $bookingPo->item_name ?: $bookingPo->description,
            'sap_code' => $bookingPo->supplier_article,
            'art_no' => $bookingPo->supplier_article,
            // No dedicated GMTS-colour column on booking_pos — the material
            // colour is the closest available value.
            'material_color', 'color', 'gmts_color' => $bookingPo->color,
            'size' => $bookingPo->size_width,
            'uom' => $bookingPo->uom,
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
            'material_name' => str_contains($header, 'material_name') || str_contains($header, 'item_name'),
            'art_no' => str_contains($header, 'art_no') || str_contains($header, 'article_no') || str_contains($header, 'artical_no') || str_contains($header, 'supplier_article'),
            // Garment colour only — must not swallow a plain "Material Color" header.
            'gmts_color' => str_contains($header, 'gmts_color') || str_contains($header, 'gmts_colour') || str_contains($header, 'garments_color') || str_contains($header, 'garment_color'),
            'material_color' => str_contains($header, 'material_color') || str_contains($header, 'gmts_color') || str_contains($header, 'color') || str_contains($header, 'colour'),
            'size' => (str_contains($header, 'size') || str_contains($header, 'gmts_size')) && ! str_contains($header, 'width') && ! str_contains($header, 'size_width'),
            'uom' => $header === 'uom' || $header === 'unit' || str_contains($header, 'unit_of_measure'),
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
            'budget' => str_contains($header, 'budget') || str_contains($header, 'buget'),
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
            'material_name' => ['material_name', 'item_name', 'material', 'Material Name', 'Item Name'],
            'sap_code' => ['sap_code', 'supplier_article', 'SAP Code'],
            'material_color' => ['material_color', 'gmts_color_name', 'gmts_colour_name', 'color', 'colour', 'Material Color'],
            // Garment (GMTS) colour, kept separate from the material's own colour —
            // the Receiving sheet carries both columns side by side.
            'gmts_color' => ['gmts_color_name', 'gmts_colour_name', 'gmts_color', 'gmts_colour', 'garments_color', 'garments_colour', 'garment_color', 'GMTS Color Name', 'Garments Color'],
            'art_no' => ['art_no', 'article_no', 'artical_no', 'art_number', 'article_number', 'supplier_article', 'supplier_article_no', 'Art. No', 'Art No', 'Article No'],
            'size' => ['size', 'item_size', 'material_size', 'gmts_size', 'Size', 'Item Size', 'Material Size'],
            // No UOM column exists in the current workbooks — these aliases are
            // here so a future file that carries one resolves per row; until
            // then it falls back to the booking_pos.uom column.
            'uom' => ['uom', 'unit', 'unit_of_measure', 'unit_of_measurement', 'UOM', 'Unit'],
            'qty' => ['materials_to_be_ordered', 'material_to_be_ordered', 'materials_ordered', 'material_ordered', 'materials_to_be_order', 'booking_qty', 'booking_quantity', 'qty'],
            'materials_ordered' => ['materials_ordered', 'material_ordered', 'materials_to_be_ordered', 'material_to_be_ordered', 'materials_order_qty', 'material_order_qty', 'Materials Ordered'],
            'pi_number' => ['material_pi_number', 'pi_number', 'vendor_pi_number', 'Material PI Number', 'PI Number', 'Vendor PI Number'],
            // Both departments' invoice columns are searched: Store knows a
            // delivery by whichever invoice number reached it, and SCM and
            // Commercial each record their own on the same BOM line.
            'invoice_no' => ['invoice_number_scm', 'invoice_number_commercial', 'invoice_number', 'invoice_no', 'Invoice Number(SCM)', 'Invoice Number(Commercial)', 'Invoice Number', 'Invoice No'],
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
            'budget' => ['budget', 'buget', 'budget_amount', 'buget_amount', 'Budget', 'Buget', 'Budget Amount', 'Buget Amount'],
            'savings' => ['savings', 'saving', 'savings_amount', 'saving_amount', 'Savings', 'Savings Amount'],
            'comments' => ['comments', 'Comments'],
            'remarks' => ['remarks', 'comments', 'merchant_remarks', 'supply_chain_remarks', 'Supply Chain Remarks', 'Comments'],
            default => [],
        };
    }
}
