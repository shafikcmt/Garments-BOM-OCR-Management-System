<?php

namespace App\Http\Controllers\Merchant;

use App\Http\Controllers\Controller;
use App\Models\MotherStyle;
use App\Services\PostCosting;
use Illuminate\Http\Request;

/** Material post-costing of a style (final costing vs what Store received and issued). Read-only. */
class PostCostingController extends Controller
{
    public function show(Request $request, MotherStyle $mother, PostCosting $post)
    {
        abort_unless($request->user()->hasBuyerAccess((int) $mother->buyer_id), 403);
        $mother->load(['buyer', 'season']);

        return view('merchant.post-costing', [
            'mother' => $mother,
            'data' => $post->forStyle($mother),
            'backUrl' => $request->user()->hasRole('merchant') ? route('merchant.styles.mothers.show', $mother) : route('merchant.status.style', $mother),
        ]);
    }
}
