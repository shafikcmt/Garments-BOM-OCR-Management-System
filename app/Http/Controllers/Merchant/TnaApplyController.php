<?php

namespace App\Http\Controllers\Merchant;

use App\Http\Controllers\Controller;
use App\Models\MotherStyle;
use App\Models\TnaTemplate;
use App\Services\TnaPlans;
use Illuminate\Http\Request;

/** Applies a TnA template to a style from the style page. */
class TnaApplyController extends Controller
{
    public function store(Request $request, MotherStyle $mother, TnaPlans $plans)
    {
        abort_unless($request->user()->hasBuyerAccess($mother->buyer_id), 403);

        $data = $request->validate(['tna_template_id' => ['required', 'integer']], ['tna_template_id.required' => 'Choose a TnA template.']);
        $template = TnaTemplate::forBuyer($mother->buyer_id)->findOrFail($data['tna_template_id']);

        $result = $plans->apply($mother, $template, $request->user());

        $redirect = back()->with($result['created'] > 0 ? 'success' : 'info', $result['created'] > 0
            ? $result['created'] . ' TnA task(s) made from "' . $template->name . '".'
            : 'No new task: every task of "' . $template->name . '" is already on this style, or could not be made.');

        return $result['skipped'] !== [] ? $redirect->with('warning', 'Not made: ' . implode('; ', $result['skipped']) . '.') : $redirect;
    }
}
