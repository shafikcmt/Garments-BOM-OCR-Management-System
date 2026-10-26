<?php

namespace App\Services;

use App\Http\Controllers\Shared\ExcelFileController;
use App\Models\BookingPo;
use App\Models\ExcelFile;
use App\Models\PaymentRequest;
use App\Models\PaymentRequestItem;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Keeps the BOM workspace in step with Supply Chain's PO and PRA.
 *
 * The workspace's PI Status / PI Amount / Payment Required Date / Payment
 * Status / order-status cells are calculated from the booking PO and payment
 * request (ExcelFileController::recalculateFile). That only ran when someone
 * saved the workspace, so a PO or PRA raised in Supply Chain stayed invisible to
 * Merchandising, Store and Accounts until then.
 *
 * Here a PO or PRA that is created, changed or removed queues its workspace
 * file, and the file is recalculated once, after the response has been sent.
 * The WHOLE file is recalculated rather than single rows because the formulas
 * depend on row order (a repeated contract quantity is zeroed on the rows
 * after the first), which a row subset would get wrong.
 *
 * Failure here never affects the Supply Chain action that triggered it.
 */
class SupplyChainWorkspaceSync
{
    /** @var array<int, ?int> file id => user the recalculation is attributed to */
    private array $pending = [];

    private bool $scheduled = false;

    public static function observe(): void
    {
        $sync = fn () => app(self::class);

        BookingPo::saved(function (BookingPo $po) use ($sync) {
            if (($po->status ?? null) === 'preview') {
                return;
            }

            if ($po->wasRecentlyCreated || $po->wasChanged(['po_no', 'status', 'vendor_name', 'qty'])) {
                $sync()->queueFile($po->excel_file_id, $po->generated_by);
            }
        });

        BookingPo::deleted(fn (BookingPo $po) => $sync()->queueFile($po->excel_file_id, $po->generated_by));

        PaymentRequest::saved(function (PaymentRequest $request) use ($sync) {
            if ($request->status === 'preview' || ! ($request->wasRecentlyCreated || $request->wasChanged('status'))) {
                return;
            }

            foreach ($request->items()->pluck('excel_file_id')->unique() as $fileId) {
                $sync()->queueFile($fileId, $request->created_by);
            }
        });

        PaymentRequestItem::created(function (PaymentRequestItem $item) use ($sync) {
            if ($item->paymentRequest && $item->paymentRequest->status !== 'preview') {
                $sync()->queueFile($item->excel_file_id, $item->paymentRequest->created_by);
            }
        });

        PaymentRequestItem::deleted(function (PaymentRequestItem $item) use ($sync) {
            $sync()->queueFile($item->excel_file_id, null);
        });
    }

    public function enabled(): bool
    {
        return (bool) config('stock.sync_supply_chain_cells', true);
    }

    public function queueFile(?int $fileId, ?int $userId = null): void
    {
        if (! $fileId || ! $this->enabled()) {
            return;
        }

        $this->pending[$fileId] ??= $userId;

        if (! $this->scheduled) {
            $this->scheduled = true;
            app()->terminating(fn () => $this->flush());
        }
    }

    /**
     * Recalculate every queued file once.
     *
     * @return int number of files recalculated
     */
    public function flush(): int
    {
        $pending = $this->pending;
        $this->pending = [];
        $done = 0;

        foreach ($pending as $fileId => $userId) {
            $file = ExcelFile::find($fileId);
            $actor = $userId ?: auth()->id() ?: $this->systemUserId();

            if (! $file || ! $actor) {
                continue;
            }

            try {
                app(ExcelFileController::class)->recalculateFile($file, (int) $actor);
                $done++;
            } catch (\Throwable $e) {
                Log::warning('Supply Chain workspace sync failed for file ' . $fileId . ': ' . $e->getMessage());
            }
        }

        return $done;
    }

    private function systemUserId(): ?int
    {
        $email = config('stock.system_user_email');

        return $email ? User::where('email', $email)->value('id') : null;
    }
}
