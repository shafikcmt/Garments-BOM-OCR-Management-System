<?php

namespace App\Http\Controllers\Merchant;

use App\Http\Controllers\Controller;
use App\Models\Buyer;
use App\Models\Costing;
use App\Models\Inquiry;
use App\Services\Inquiries;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Buyer inquiries and quotations. Merchandising and Admin work them for the
 * buyers they hold; Management reads. Rules are in App\Services\Inquiries.
 */
class InquiryController extends Controller
{
    public function index(Request $request, Inquiries $inquiries)
    {
        $user = $request->user();
        $buyers = Buyer::active()
            ->when($user->isBuyerConfined(), fn ($q) => $q->whereIn('id', $user->merchantBuyerIds()))
            ->orderBy('buyer_name')->get();
        $buyer = $buyers->firstWhere('id', (int) $request->query('buyer'));
        $status = $request->query('status');
        $status = array_key_exists($status, Inquiry::STATUSES) || $status === 'all' ? $status : 'open';

        $base = Inquiry::query()->with(['buyer:id,buyer_name', 'motherStyle:id,style_no'])
            ->whereIn('buyer_id', $buyer ? [$buyer->id] : $buyers->pluck('id'));

        $list = (clone $base)
            ->when($status === 'open', fn ($q) => $q->whereIn('status', Inquiry::OPEN))
            ->when(! in_array($status, ['open', 'all'], true), fn ($q) => $q->where('status', $status))
            ->orderByRaw('case when quote_due_date is null then 1 else 0 end')->orderBy('quote_due_date')->orderByDesc('id')
            ->limit(300)->get();

        return view('merchant.inquiries', [
            'inquiries' => $list,
            'kpis' => $inquiries->kpis((clone $base)->get()),
            'buyers' => $buyers,
            'buyer' => $buyer,
            'status' => $status,
            'canEdit' => $user->hasAnyRole(['merchant', 'admin']),
            'today' => Carbon::today(),
        ]);
    }

    public function store(Request $request, Inquiries $inquiries)
    {
        abort_unless($request->user()->hasAnyRole(['merchant', 'admin']), 403);
        $data = $request->validate([
            'buyer_id' => ['required', 'integer', 'exists:buyers,id'],
            'style_ref' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:255'],
            'product_group' => ['nullable', 'string', 'max:60'],
            'est_qty' => ['nullable', 'integer', 'min:1', 'max:99999999'],
            'target_price' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'currency' => ['required', Rule::in(Costing::CURRENCIES)],
            'received_date' => ['required', 'date'],
            'quote_due_date' => ['nullable', 'date', 'after_or_equal:received_date'],
            'note' => ['nullable', 'string', 'max:2000'],
        ], ['style_ref.required' => 'Type the buyer\'s style or design number.', 'quote_due_date.after_or_equal' => 'The quote date cannot be before the date received.']);
        abort_unless($request->user()->hasBuyerAccess((int) $data['buyer_id']), 403);

        $inquiry = $inquiries->create($data, $request->user());

        return redirect()->route('merchant.inquiries.show', $inquiry)->with('success', 'Inquiry ' . $inquiry->reference . ' added.');
    }

    public function show(Request $request, Inquiry $inquiry, Inquiries $inquiries)
    {
        $this->authorizeView($request, $inquiry);
        $inquiry->load(['buyer', 'season', 'motherStyle', 'creator:id,name']);

        return view('merchant.inquiry', [
            'inquiry' => $inquiry,
            'seasons' => $inquiry->buyer->seasons()->orderByDesc('id')->get(),
            'suggested' => $inquiries->suggestedPrice($inquiry),
            'canEdit' => $request->user()->hasAnyRole(['merchant', 'admin']),
            'today' => Carbon::today(),
        ]);
    }

    public function update(Request $request, Inquiry $inquiry)
    {
        $this->authorizeEdit($request, $inquiry);
        $data = $request->validate([
            'style_ref' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:255'],
            'product_group' => ['nullable', 'string', 'max:60'],
            'est_qty' => ['nullable', 'integer', 'min:1', 'max:99999999'],
            'target_price' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'currency' => ['required', Rule::in(Costing::CURRENCIES)],
            'quote_due_date' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:2000'],
            'payment_terms' => ['nullable', 'string', 'max:255'],
            'delivery_terms' => ['nullable', 'string', 'max:255'],
            'validity_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'quote_remark' => ['nullable', 'string', 'max:2000'],
        ]);
        $inquiry->update($data + ['updated_by' => $request->user()->id]);

        return back()->with('success', 'Inquiry updated.');
    }

    /** The printed quotation: A4 portrait, the same page for preview and download. */
    public function quotationPdf(Request $request, Inquiry $inquiry)
    {
        $this->authorizeView($request, $inquiry);
        if ($inquiry->quoted_price === null) {
            return back()->with('warning', 'Record the quotation (price) first, then download the PDF.');
        }
        $inquiry->load(['buyer', 'creator:id,name']);
        $date = $inquiry->quoted_date ?? Carbon::today();

        return \Barryvdh\DomPDF\Facade\Pdf::loadView('merchant.quotation-pdf', [
            'inquiry' => $inquiry,
            'date' => $date,
            'validDays' => $inquiry->validity_days ?: (int) config('quotation.validity_days', 30),
            'company' => config('quotation.company_name'),
            'address' => config('quotation.address', []),
            'logo' => ($logo = config('quotation.logo')) && is_file(public_path($logo)) ? public_path($logo) : null,
            'paymentTerms' => $inquiry->payment_terms ?: config('quotation.payment_terms'),
            'deliveryTerms' => $inquiry->delivery_terms ?: config('quotation.delivery_terms'),
        ])->setPaper('a4', 'portrait')->stream('Quotation-' . str_replace(['/', '\\'], '-', (string) $inquiry->reference) . '.pdf');
    }

    public function startCosting(Request $request, Inquiry $inquiry, Inquiries $inquiries)
    {
        $this->authorizeEdit($request, $inquiry);
        $data = $request->validate(['season_id' => ['nullable', 'integer'], 'new_season' => ['nullable', 'string', 'max:50']]);

        try {
            $mother = $inquiries->startCosting($inquiry, $data['season_id'] ?? null, $data['new_season'] ?? null, $request->user());
        } catch (\DomainException $e) {
            return back()->with('warning', $e->getMessage());
        }

        return redirect()->route('costing.show', [$mother, 'mode' => 'buyer'])->with('success', 'Style ' . $mother->style_no . ' is ready. Fill the pre-costing, then record the quotation on the inquiry.');
    }

    public function quote(Request $request, Inquiry $inquiry, Inquiries $inquiries)
    {
        $this->authorizeEdit($request, $inquiry);
        $data = $request->validate([
            'quoted_price' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'currency' => ['required', Rule::in(Costing::CURRENCIES)],
            'quoted_date' => ['nullable', 'date'],
            'price_basis' => ['nullable', Rule::in(array_keys(Inquiry::BASES))],
        ], ['quoted_price.required' => 'Type the price quoted to the buyer.']);

        return $this->run(fn () => $inquiries->markQuoted($inquiry, (float) $data['quoted_price'], $data['currency'], $data['quoted_date'] ?? null, $request->user(), $data['price_basis'] ?? 'pc'), 'Quotation recorded.');
    }

    public function won(Request $request, Inquiry $inquiry, Inquiries $inquiries)
    {
        $this->authorizeEdit($request, $inquiry);

        return $this->run(fn () => $inquiries->markWon($inquiry, $request->user()), 'Marked won. When the buyer\'s order comes, import it on the same style.');
    }

    public function lost(Request $request, Inquiry $inquiry, Inquiries $inquiries)
    {
        $this->authorizeEdit($request, $inquiry);
        $data = $request->validate(['lost_reason' => ['required', 'string', 'max:255']], ['lost_reason.required' => 'Write why the quotation was lost.']);

        return $this->run(fn () => $inquiries->markLost($inquiry, $data['lost_reason'], $request->user()), 'Marked lost.');
    }

    public function cancel(Request $request, Inquiry $inquiry, Inquiries $inquiries)
    {
        $this->authorizeEdit($request, $inquiry);

        return $this->run(fn () => $inquiries->cancel($inquiry, $request->user()), 'Inquiry cancelled.');
    }

    public function reopen(Request $request, Inquiry $inquiry, Inquiries $inquiries)
    {
        $this->authorizeEdit($request, $inquiry);

        return $this->run(fn () => $inquiries->reopen($inquiry, $request->user()), 'Inquiry reopened.');
    }

    private function run(\Closure $action, string $done)
    {
        try {
            $action();
        } catch (\DomainException $e) {
            return back()->with('warning', $e->getMessage());
        }

        return back()->with('success', $done);
    }

    private function authorizeView(Request $request, Inquiry $inquiry): void
    {
        abort_unless($request->user()->hasBuyerAccess((int) $inquiry->buyer_id), 403);
    }

    private function authorizeEdit(Request $request, Inquiry $inquiry): void
    {
        abort_unless($request->user()->hasAnyRole(['merchant', 'admin']) && $request->user()->hasBuyerAccess((int) $inquiry->buyer_id), 403);
    }
}
