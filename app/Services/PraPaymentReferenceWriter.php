<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\ExcelCell;
use App\Models\ExcelFileChangeLog;
use App\Models\ExcelRow;
use App\Models\PaymentRequest;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Carries a completed PRA's payment reference into the BOM workspace.
 *
 * The last step of the departmental PRA chain is the payment itself, but the
 * reference of that payment used to live nowhere: Accounts would finish the PRA
 * and then type the same Pmt Doc No into every worksheet line by hand before
 * Payment Status could turn to "Pmt Done". One PRA covers many lines, so this
 * writes the reference once to all of them.
 *
 * Writing the cell is enough for the status: Payment Status is calculated from
 * Pmt Doc No, and SupplyChainWorkspaceSync recalculates the file after the
 * request. Each written cell is logged exactly like a manual edit.
 */
class PraPaymentReferenceWriter
{
    public function __construct(private HeaderAliasResolver $headers)
    {
    }

    /**
     * @return int number of worksheet lines updated
     */
    public function write(PaymentRequest $paymentRequest, string $reference, User $actor): int
    {
        $reference = trim($reference);

        if ($reference === '') {
            return 0;
        }

        // Kept on the PRA itself, so the reference survives even where the
        // workspace has no Pmt Doc No column to receive it.
        $data = $paymentRequest->data ?: [];
        $data['payment_reference'] = $reference;
        $paymentRequest->data = $data;
        $paymentRequest->save();

        $headerId = $this->headers->resolveHeaderId('pmt_doc_no');

        if (! $headerId) {
            return 0;
        }

        $header = \App\Models\ExcelHeader::find($headerId);
        $fileIds = [];
        $batchId = (string) Str::uuid();
        $written = 0;

        $rowIds = $paymentRequest->items()->whereNotNull('excel_row_id')->pluck('excel_row_id')->unique();

        foreach (ExcelRow::whereIn('id', $rowIds)->get() as $row) {
            $cell = ExcelCell::firstOrNew(['row_id' => $row->id, 'header_id' => $headerId]);
            $old = $cell->exists ? $cell->value : null;

            if ((string) $old === $reference) {
                continue;
            }

            $cell->value = $reference;
            $cell->updated_by = $actor->id;
            $cell->save();

            ActivityLog::create([
                'excel_file_id' => $row->excel_file_id,
                'row_id' => $row->id,
                'header_id' => $headerId,
                'old_value' => $old,
                'new_value' => $reference,
                'action' => $old === null ? 'created' : 'updated',
                'user_id' => $actor->id,
            ]);

            ExcelFileChangeLog::create([
                'excel_file_id' => $row->excel_file_id,
                'excel_row_id' => $row->id,
                'excel_header_id' => $headerId,
                'row_number' => $row->row_number,
                'header_name' => $header?->header_name,
                'old_value' => $old,
                'new_value' => $reference,
                'changed_by' => $actor->id,
                'batch_id' => $batchId,
            ]);

            $fileIds[$row->excel_file_id] = true;
            $written++;
        }

        // Payment Status is calculated from Pmt Doc No, so the files whose
        // cells just changed are recalculated (once each, after the request).
        foreach (array_keys($fileIds) as $fileId) {
            app(SupplyChainWorkspaceSync::class)->queueFile((int) $fileId, $actor->id);
        }

        return $written;
    }
}
