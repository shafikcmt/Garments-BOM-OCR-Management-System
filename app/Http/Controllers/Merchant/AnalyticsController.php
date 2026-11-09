<?php

namespace App\Http\Controllers\Merchant;

use App\Http\Controllers\Controller;
use App\Models\Buyer;
use App\Services\MerchantAnalytics;
use Illuminate\Http\Request;

/** Merchandising analytics: order value, margin and inquiry win rate. Read-only; buyer-scoped. */
class AnalyticsController extends Controller
{
    public function index(Request $request, MerchantAnalytics $analytics)
    {
        $user = $request->user();
        $buyers = Buyer::active()
            ->when($user->isBuyerConfined(), fn ($q) => $q->whereIn('id', $user->merchantBuyerIds()))
            ->orderBy('buyer_name')->get();
        $buyer = $buyers->firstWhere('id', (int) $request->query('buyer'));
        $ids = $buyers->pluck('id')->all();

        return view('merchant.analytics', [
            'buyers' => $buyers,
            'buyer' => $buyer,
            'data' => $analytics->build($ids, $buyer?->id),
            'inquiries' => $analytics->inquiryResults($ids, $buyer?->id),
        ]);
    }
}
