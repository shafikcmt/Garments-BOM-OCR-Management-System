<?php

namespace App\Services;

use App\Http\Controllers\SupplyChain\BookingController;
use App\Models\AppNotification;
use App\Models\BookingPo;
use App\Models\ExcelFile;
use App\Models\PaymentRequestItem;
use App\Models\User;

/**
 * Tells Supply Chain when a Merchant's workspace edit makes a booking PO stale.
 *
 * Merchandising owns the order data; Supply Chain's PO / PI / PRA are built
 * from it. This is the hand-off: the PO is flagged "Source changed" (the
 * existing needs_regenerate control) and Supply Chain gets ONE notification
 * naming the POs and the fields, plus any payment request the PO is already in.
 *
 * It only notifies for POs whose change set is NEW in this save, so a PO that
 * is still waiting to be regenerated does not re-alert on every later save.
 */
class BookingPoChangeNotifier
{
    /**
     * @param  list<int>  $changedRowIds
     * @return int  number of POs reported
     */
    public function notify(ExcelFile $file, array $changedRowIds, User $actor): int
    {
        $changedRowIds = array_values(array_unique(array_map('intval', $changedRowIds)));

        $before = BookingPo::whereIn('excel_row_id', $changedRowIds)->get()
            ->mapWithKeys(fn (BookingPo $po) => [$po->id => $this->changeSignature($po)]);

        if ($before->isEmpty()) {
            return 0;
        }

        $flagged = app(BookingController::class)
            ->refreshSourceControlForRows($changedRowIds)
            ->filter(fn (BookingPo $po) => $this->changeSignature($po) !== ($before[$po->id] ?? null))
            ->values();

        if ($flagged->isEmpty()) {
            return 0;
        }

        $inRequests = PaymentRequestItem::whereIn('booking_po_id', $flagged->pluck('id'))
            ->whereHas('paymentRequest', fn ($q) => $q->where('status', '!=', 'preview'))
            ->with('paymentRequest:id,request_no')
            ->get()
            ->map(fn ($item) => $item->paymentRequest?->request_no)
            ->filter()
            ->unique()
            ->values();

        $poNumbers = $flagged->pluck('po_no')->filter()->unique()->values();
        $fields = $flagged
            ->flatMap(fn (BookingPo $po) => collect($po->booking_data['source_change_log'] ?? [])->pluck('label'))
            ->unique()
            ->values();

        $message = sprintf(
            '%s changed %s on %d PO(s): %s. Regenerate before sending.',
            $actor->name,
            $fields->take(4)->implode(', ') ?: 'order details',
            $flagged->count(),
            $poNumbers->take(5)->implode(', ') . ($poNumbers->count() > 5 ? ' …' : '')
        );

        if ($inRequests->isNotEmpty()) {
            $message .= ' Already in payment request ' . $inRequests->implode(', ') . '.';
        }

        $recipients = User::where('id', '!=', $actor->id)
            ->whereHas('roles', fn ($q) => $q->where('name', 'supply_chain'))
            ->get()
            ->reject(fn (User $user) => $file->isBuyerLockedForUser($user));

        foreach ($recipients as $user) {
            AppNotification::create([
                'user_id' => $user->id,
                'actor_id' => $actor->id,
                'excel_file_id' => $file->id,
                'type' => 'po_source_changed',
                'title' => 'PO source changed',
                'message' => $message,
                'url' => route('supply_chain.bookings.index'),
                'data' => [
                    'booking_po_ids' => $flagged->pluck('id')->all(),
                    'po_numbers' => $poNumbers->all(),
                    'fields' => $fields->all(),
                    'payment_requests' => $inRequests->all(),
                ],
            ]);
        }

        return $flagged->count();
    }

    private function changeSignature(BookingPo $po): string
    {
        return md5(json_encode($po->booking_data['source_change_log'] ?? []));
    }
}
