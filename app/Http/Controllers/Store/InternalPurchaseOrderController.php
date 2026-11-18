<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\GsComparativeStatement;
use App\Models\GsPurchaseOrder;
use App\Services\PurchaseOrderService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

/**
 * General Stock purchasing: the Internal PO ("booking" with an internal PO
 * number). Made from an approved Comparative Statement; its number is what
 * Store picks on Record Receiving.
 */
class InternalPurchaseOrderController extends Controller
{
    public function __construct(private readonly PurchaseOrderService $service)
    {
    }

    public function index(Request $request)
    {
        $filters = $request->validate([
            'status' => ['nullable', 'string', 'max:30'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $orders = GsPurchaseOrder::query()
            ->with(['statement:id,cs_no', 'creator:id,name'])
            ->withCount('items')
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['search'] ?? null, function ($q, $s) {
                $q->where(fn ($w) => $w->whereLike('po_no', "%{$s}%")
                    ->orWhereLike('supplier_name', "%{$s}%")
                    ->orWhereHas('items', fn ($i) => $i->whereLike('item_name', "%{$s}%")));
            })
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('store.purchasing.po-index', [
            'orders' => $orders,
            'filters' => $filters,
            'statusLabels' => GsPurchaseOrder::statusLabels(),
        ]);
    }

    /** One draft PO per supplier chosen on the approved CS. */
    public function storeFromStatement(Request $request, GsComparativeStatement $cs)
    {
        abort_unless($request->user()->can('store.purchase_po.create'), 403);

        $supplierId = $request->validate(['supplier' => ['nullable', 'integer']])['supplier'] ?? null;
        $orders = $this->service->createFromStatement($cs, $request->user(), $supplierId !== null ? (int) $supplierId : null);

        return $orders->count() === 1
            ? redirect()->route('store.purchasing.po.show', $orders->first())->with('success', $orders->first()->po_no.' created as a draft. It needs to be checked, then authorised.')
            : redirect()->route('store.purchasing.po.index', ['search' => $cs->cs_no])->with('success', $orders->count().' purchase orders created as drafts, one per supplier.');
    }

    public function show(Request $request, GsPurchaseOrder $order)
    {
        $order->load(['items', 'statement', 'creator:id,name', 'checkedBy:id,name', 'authorisedBy:id,name']);

        return view('store.purchasing.po-show', [
            'order' => $order,
            'allowed' => $this->service->allowed($order, $request->user()),
            'received' => $this->service->receivedByLine($order),
        ]);
    }

    public function edit(Request $request, GsPurchaseOrder $order)
    {
        abort_unless(isset($this->service->allowed($order, $request->user())['edit']), 403, 'This purchase order can no longer be edited.');
        $order->load('items');

        return view('store.purchasing.po-edit', ['order' => $order]);
    }

    public function update(Request $request, GsPurchaseOrder $order)
    {
        abort_unless(isset($this->service->allowed($order, $request->user())['edit']), 403, 'This purchase order can no longer be edited.');

        // The edit form posts Terms of Delivery and Remarks one line per box.
        foreach (['delivery_lines' => 'delivery_terms', 'remark_lines' => 'remarks'] as $lines => $field) {
            if (is_array($request->input($lines))) {
                $kept = array_values(array_filter(array_map(fn ($l) => trim((string) $l), $request->input($lines)), fn ($l) => $l !== ''));
                $request->merge([$field => $kept === [] ? null : implode("\n", $kept)]);
            }
        }

        $data = $request->validate([
            'po_date' => ['required', 'date'],
            'reference_no' => ['nullable', 'string', 'max:100'],
            'invoice_to' => ['nullable', 'string', 'max:1000'],
            'consignee' => ['nullable', 'string', 'max:1000'],
            'dispatched_through' => ['nullable', 'string', 'max:150'],
            'destination' => ['nullable', 'string', 'max:255'],
            'payment_terms' => ['nullable', 'string', 'max:1000'],
            'delivery_terms' => ['nullable', 'string', 'max:1000'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        // Terms of Delivery print in a fixed box: five lines at most.
        $deliveryLines = array_filter(preg_split('/\r\n|\r|\n/', (string) ($data['delivery_terms'] ?? '')), fn ($l) => trim($l) !== '');
        if (count($deliveryLines) > 5) {
            return back()->withInput()->withErrors(['delivery_terms' => 'Terms of delivery can have up to 5 lines.']);
        }

        $this->service->update($order, $data);

        return redirect()->route('store.purchasing.po.show', $order)->with('success', $order->po_no.' saved.');
    }

    public function check(Request $request, GsPurchaseOrder $order)
    {
        abort_unless(isset($this->service->allowed($order, $request->user())['check']), 403, 'You cannot check this purchase order.');
        $this->service->check($order, $request->user());

        return back()->with('success', $order->po_no.' checked. It now waits for the Authorised Signatory.');
    }

    public function sendBack(Request $request, GsPurchaseOrder $order)
    {
        abort_unless(isset($this->service->allowed($order, $request->user())['send_back']), 403, 'You cannot send this purchase order back.');
        $this->service->sendBack($order);

        return back()->with('success', $order->po_no.' sent back to draft.');
    }

    public function authorise(Request $request, GsPurchaseOrder $order)
    {
        abort_unless(isset($this->service->allowed($order, $request->user())['authorise']), 403, 'You cannot authorise this purchase order.');
        $this->service->authorise($order, $request->user());

        return back()->with('success', $order->po_no.' authorised. Store can now receive goods against it.');
    }

    public function cancel(Request $request, GsPurchaseOrder $order)
    {
        abort_unless(isset($this->service->allowed($order, $request->user())['cancel']), 403, 'This purchase order cannot be cancelled.');
        $reason = $request->validate(['reason' => ['required', 'string', 'max:1000']], ['reason.required' => 'Write why it is being cancelled.'])['reason'];
        $this->service->cancel($order, $reason);

        return back()->with('success', $order->po_no.' cancelled.');
    }

    /** The printed PO: A4 portrait. Only an authorised PO prints as final; a draft or checked one is marked. */
    public function pdf(GsPurchaseOrder $order)
    {
        $order->load(['items', 'creator:id,name', 'checkedBy:id,name', 'authorisedBy:id,name']);

        return Pdf::loadView('store.purchasing.po-pdf', ['order' => $order])
            ->setPaper('a4', 'portrait')
            ->stream('PO-'.str_replace(['/', '\\'], '-', $order->po_no).'.pdf');
    }
}
