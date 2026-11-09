<?php

namespace App\Http\Controllers\SupplyChain;

use App\Http\Controllers\Controller;
use App\Models\BookingPo;
use App\Services\SupplierDelivery;
use App\Support\Concerns\ScopesToAssignedBuyers;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Supplier delivery follow-up: promised date against what arrived, per PO and
 * per supplier. Read-only. Supply Chain, Admin and Management; buyer-scoped.
 */
class SupplierDeliveryController extends Controller
{
    use ScopesToAssignedBuyers;

    public function index(Request $request, SupplierDelivery $service)
    {
        $query = BookingPo::query()->where('status', '!=', 'preview')->with('excelFile:id,buyer_id');
        $this->applyBuyerScope($query);
        $data = $service->build($query->limit(5000)->get());

        $status = in_array($request->query('status'), ['overdue', 'no_date', 'open', 'delivered_late', 'on_time'], true) ? $request->query('status') : 'overdue';
        $term = trim((string) $request->query('q', ''));

        $lines = $data['lines']
            ->when($status, fn ($c) => $c->where('status', $status))
            ->when($term !== '', fn ($c) => $c->filter(fn ($l) => collect([$l['vendor'], $l['po']->po_no, $l['po']->style_name, $l['po']->item_name, $l['po']->description])
                ->contains(fn ($v) => $v !== null && mb_stripos((string) $v, $term) !== false)))
            ->sortByDesc('days_late')->values();

        return view('supply-chain.supplier-delivery', [
            'kpis' => $data['kpis'],
            'suppliers' => $data['suppliers'],
            'lines' => $lines->take(300),
            'linesTotal' => $lines->count(),
            'status' => $status,
            'term' => $term,
            'today' => Carbon::today(),
        ]);
    }
}
