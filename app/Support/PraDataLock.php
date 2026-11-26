<?php

namespace App\Support;

use App\Models\AppSetting;
use App\Models\PaymentRequest;
use App\Models\PaymentRequestItem;

/**
 * Keeps the Workspace figures of a row steady once a Payment Request Approval
 * (PRA) already uses that row.
 *
 *   PRA submitted (waiting for check / approval)
 *       Materials Ordered, Materials to be Ordered (and the inputs that
 *       calculate them) can no longer be changed.
 *   PRA approved (or paid)
 *       the above, plus PI Rate and PI Amount, are frozen.
 *
 * A PRA that is still a preview/draft, was rejected (its creator may correct and
 * resubmit) or was cancelled does not lock anything. Taking a line off a PRA
 * deletes its item, so that row is released automatically.
 *
 * Admin switches each rule on or off (PRA Approval Steps screen). Admin users
 * themselves are never blocked.
 */
class PraDataLock
{
    public const KEY_AFTER_SUBMIT = 'pra_lock_qty_after_submit';

    public const KEY_AFTER_APPROVAL = 'pra_lock_price_after_approval';

    public const PHASE_SUBMITTED = 'submitted';

    public const PHASE_APPROVED = 'approved';

    /** Header keys that make up the order quantity, directly or by calculation. */
    public const QTY_KEYS = [
        'materials_ordered', 'material_ordered',
        'materials_to_be_ordered', 'material_to_be_ordered',
        'bom_quantity', 'bom_qty', 'bom_qnty',
        'customer_contract_quantity', 'customer_po_quantity', 'order_qty',
        'booking_consumption_from_cad', 'initial_consumption', 'booking_yy',
        'wastage_for_ordering_percent', 'waste_percent', 'wastage_percent',
        'consumption_incl_yy', 'consumption_based_on_which_materials_order_including_yy',
    ];

    public const PRICE_KEYS = ['pi_rate', 'pi_amount'];

    public static function submitLockEnabled(): bool
    {
        return (string) AppSetting::get(self::KEY_AFTER_SUBMIT, '1') !== '0';
    }

    public static function approvalLockEnabled(): bool
    {
        return (string) AppSetting::get(self::KEY_AFTER_APPROVAL, '1') !== '0';
    }

    /**
     * @param  array<int, int|string>  $excelRowIds
     * @return array<int, array{pra_no:string,phase:string,keys:array<int,string>}>
     */
    public static function forRows(array $excelRowIds): array
    {
        $excelRowIds = array_values(array_filter(array_map('intval', $excelRowIds)));
        $submitOn = self::submitLockEnabled();
        $approvalOn = self::approvalLockEnabled();

        if ($excelRowIds === [] || (! $submitOn && ! $approvalOn)) {
            return [];
        }

        $approved = [
            PaymentRequest::STATUS_APPROVED,
            PaymentRequest::STATUS_PENDING_COMPLETION,
            PaymentRequest::STATUS_COMPLETED,
        ];
        $submitted = [PaymentRequest::STATUS_PENDING_CHECK, PaymentRequest::STATUS_PENDING_APPROVAL];

        $items = PaymentRequestItem::query()
            ->whereIn('excel_row_id', $excelRowIds)
            ->whereHas('paymentRequest', fn ($q) => $q->whereIn('status', array_merge($submitted, $approved)))
            ->with('paymentRequest:id,request_no,status')
            ->get(['id', 'excel_row_id', 'payment_request_id']);

        $locks = [];

        foreach ($items as $item) {
            $pra = $item->paymentRequest;
            if (! $pra) {
                continue;
            }

            $isApproved = in_array($pra->status, $approved, true);
            $keys = [];

            if ($isApproved ? ($submitOn || $approvalOn) : $submitOn) {
                $keys = self::QTY_KEYS;
            }
            if ($isApproved && $approvalOn) {
                $keys = array_merge($keys, self::PRICE_KEYS);
            }
            if ($keys === []) {
                continue;
            }

            $rowId = (int) $item->excel_row_id;
            // The strictest PRA wins when a row sits on more than one.
            if (isset($locks[$rowId]) && count($locks[$rowId]['keys']) >= count($keys)) {
                continue;
            }

            $locks[$rowId] = [
                'pra_no' => (string) ($pra->request_no ?: 'PRA #' . $pra->id),
                'phase' => $isApproved ? self::PHASE_APPROVED : self::PHASE_SUBMITTED,
                'keys' => $keys,
            ];
        }

        return $locks;
    }
}
