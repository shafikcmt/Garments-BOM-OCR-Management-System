<?php

namespace App\Http\Controllers;

use App\Models\PaymentRequest;
use App\Models\PaymentRequestItem;
use App\Services\PraWorkflow;
use App\Support\Concerns\ScopesToAssignedBuyers;
use Illuminate\Support\Facades\Storage;

/**
 * Serves the supporting documents (PI copy, etc.) attached to a PRA.
 *
 * Files live on the private disk, so every download is authorised here: the
 * creator, admin, buyer-scoped Supply Chain users, anyone who is or was a
 * reviewer on the PRA, and the actors of the current departmental step.
 */
class PraDocumentController extends Controller
{
    use ScopesToAssignedBuyers;

    public function __construct(protected PraWorkflow $workflow)
    {
    }

    public function download(PaymentRequest $paymentRequest, int $index)
    {
        $this->authorizeView($paymentRequest);

        $doc = $paymentRequest->attachments()[$index] ?? null;
        abort_if(! $doc || ! Storage::disk('local')->exists($doc['path']), 404);

        return Storage::disk('local')->download($doc['path'], $doc['name']);
    }

    /**
     * Open a document in the browser instead of saving it: PDFs and images are
     * shown inline (the page opens it in a new tab); any other type falls back
     * to a download, since a browser cannot display it.
     */
    public function view(PaymentRequest $paymentRequest, int $index)
    {
        $this->authorizeView($paymentRequest);

        $doc = $paymentRequest->attachments()[$index] ?? null;
        abort_if(! $doc || ! Storage::disk('local')->exists($doc['path']), 404);

        $mime = match (strtolower(pathinfo($doc['name'], PATHINFO_EXTENSION))) {
            'pdf' => 'application/pdf',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            default => null,
        };

        if ($mime === null) {
            return Storage::disk('local')->download($doc['path'], $doc['name']);
        }

        return Storage::disk('local')->response($doc['path'], $doc['name'], ['Content-Type' => $mime], 'inline');
    }

    protected function authorizeView(PaymentRequest $paymentRequest): void
    {
        $user = auth()->user();
        abort_if(! $user, 403);

        if ($user->hasRole('admin') || (int) $paymentRequest->created_by === (int) $user->id) {
            return;
        }

        $paymentRequest->loadMissing('approvals', 'items.excelFile');

        if ($paymentRequest->approvals->contains('approver_id', $user->id)) {
            return;
        }

        if ($paymentRequest->isDepartmentalFlow()) {
            $stage = $this->workflow->currentStage($paymentRequest);

            if ($stage && $stage->includes($user)) {
                return;
            }
        }

        if ($user->hasRole('supply_chain')) {
            if (! $this->buyerScopeViewer()?->isBuyerConfined()) {
                return;
            }

            $reachable = $paymentRequest->items->isEmpty()
                || $paymentRequest->items->contains(
                    fn (PaymentRequestItem $item) => $this->allowsBuyerAccess(
                        $item->excelFile?->buyer_id === null ? null : (int) $item->excelFile->buyer_id
                    )
                );

            if ($reachable) {
                return;
            }
        }

        abort(403);
    }
}
