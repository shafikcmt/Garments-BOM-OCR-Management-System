<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\ExcelCell;
use App\Models\ExcelFile;
use App\Models\ExcelHeader;
use App\Models\PaymentRequest;
use App\Services\HeaderAliasResolver;
use App\Services\PraPaymentReferenceWriter;
use App\Support\Concerns\ScopesToAssignedBuyers;
use Illuminate\Http\Request;

/**
 * Accounts' payment follow-up: which approved payment requests are still
 * unpaid, and a one-step way to record the payment reference on all their lines.
 *
 * "Paid" is read from the workspace itself — a line is paid when its Pmt Doc No
 * is filled — so a payment typed straight into the sheet counts exactly like one
 * recorded here. Recording goes through PraPaymentReferenceWriter, the same
 * writer the departmental payment step uses.
 */
class PaymentController extends Controller
{
    use ScopesToAssignedBuyers;

    /** Statuses a PRA can be in once it is approved and heading for payment. */
    private const PAYABLE = [
        PaymentRequest::STATUS_APPROVED,
        PaymentRequest::STATUS_PENDING_COMPLETION,
        PaymentRequest::STATUS_COMPLETED,
    ];

    public function index(Request $request)
    {
        $tab = $request->query('tab') === 'paid' ? 'paid' : 'due';
        $term = trim((string) $request->query('q', ''));

        $query = PaymentRequest::query()
            ->whereIn('status', self::PAYABLE)
            ->withCount('items')
            ->withSum('items as lines_amount', 'pi_amount')
            ->withMin('items as required_date', 'payment_required_date')
            ->tap(fn ($q) => $this->applyBuyerScopeToPaymentRequests($q))
            ->when($term !== '', fn ($q) => $q->where(
                fn ($w) => $w->whereLike('request_no', "%{$term}%")->orWhereLike('supplier_name', "%{$term}%")
            ))
            ->latest('id');

        $all = $query->limit(500)->get();
        $paidLines = $this->paidLineCounts($all);

        $all->each(function (PaymentRequest $pra) use ($paidLines) {
            $paid = $paidLines[$pra->id] ?? 0;
            $pra->setAttribute('paid_lines', $paid);
            $pra->setAttribute('payment_state', match (true) {
                filled($pra->data['payment_reference'] ?? null) || ($pra->items_count > 0 && $paid >= $pra->items_count) => 'paid',
                $paid > 0 => 'partial',
                default => 'due',
            });
        });

        $due = $all->where('payment_state', '!=', 'paid');
        $paid = $all->where('payment_state', 'paid');

        $stats = [
            'due_count' => $due->count(),
            'due_amount' => (float) $due->sum(fn ($p) => $p->total_pi_amount ?: $p->lines_amount),
            'paid_count' => $paid->count(),
            'waiting_step' => $due->where('status', PaymentRequest::STATUS_PENDING_COMPLETION)->count(),
        ];

        $requests = ($tab === 'paid' ? $paid : $due)->values();

        return view('account.payments', compact('requests', 'stats', 'tab', 'term'));
    }

    public function record(Request $request, PaymentRequest $paymentRequest)
    {
        $inScope = PaymentRequest::query()
            ->whereKey($paymentRequest->id)
            ->tap(fn ($q) => $this->applyBuyerScopeToPaymentRequests($q))
            ->exists();
        abort_unless($inScope, 404);

        $validated = $request->validate([
            'payment_reference' => ['required', 'string', 'max:100'],
        ], [
            'payment_reference.required' => 'Enter the payment reference no.',
        ]);

        // A departmental PRA still waiting on its payment step is paid through
        // that step, so the approval chain is never bypassed from here.
        if (! in_array($paymentRequest->status, [PaymentRequest::STATUS_APPROVED, PaymentRequest::STATUS_COMPLETED], true)) {
            return back()->with('warning', 'This payment request is still in its approval flow. Record the payment on its payment step.');
        }

        $user = $request->user();
        $headerId = app(HeaderAliasResolver::class)->resolveHeaderId('pmt_doc_no');
        $header = $headerId ? ExcelHeader::find($headerId) : null;

        // Same rule as editing the column in the workspace: admin, the role
        // that owns it, or a role Admin added as an extra editor.
        if ($header && ! $user->hasRole('admin') && ! $header->isEditableByRoleIds($user->roles->pluck('id'))) {
            return back()->with('warning', 'Your role cannot edit the Pmt Doc No column.');
        }

        $locked = ExcelFile::whereIn('id', $paymentRequest->items()->pluck('excel_file_id')->unique())
            ->get()
            ->contains(fn (ExcelFile $file) => $file->isLockedForUser($user));

        if ($locked) {
            return back()->with('warning', 'A file in this payment request is locked by admin. Ask admin to unlock it first.');
        }

        $lines = app(PraPaymentReferenceWriter::class)->write($paymentRequest, $validated['payment_reference'], $user);

        return back()->with('success', 'Payment reference saved for ' . $paymentRequest->request_no
            . ($lines > 0 ? ' on ' . $lines . ' line(s).' : '.'));
    }

    /**
     * Lines with a Pmt Doc No filled in, per PRA.
     *
     * @return array<int, int>
     */
    private function paidLineCounts($requests): array
    {
        $headerId = app(HeaderAliasResolver::class)->resolveHeaderId('pmt_doc_no');

        if (! $headerId || $requests->isEmpty()) {
            return [];
        }

        $rowsByPra = \App\Models\PaymentRequestItem::whereIn('payment_request_id', $requests->pluck('id'))
            ->whereNotNull('excel_row_id')
            ->get(['payment_request_id', 'excel_row_id'])
            ->groupBy('payment_request_id');

        $filled = ExcelCell::where('header_id', $headerId)
            ->whereIn('row_id', $rowsByPra->flatten(1)->pluck('excel_row_id')->unique())
            ->whereNotNull('value')
            ->where('value', '!=', '')
            ->pluck('row_id')
            ->flip();

        return $rowsByPra->map(
            fn ($items) => $items->pluck('excel_row_id')->unique()->filter(fn ($id) => $filled->has($id))->count()
        )->all();
    }
}
