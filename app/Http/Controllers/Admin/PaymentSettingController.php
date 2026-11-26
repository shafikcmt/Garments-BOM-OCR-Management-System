<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\PraApprover;
use App\Support\PaymentRequestSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PaymentSettingController extends Controller
{
    public function edit()
    {
        $officers = [];
        foreach (PaymentRequestSettings::OFFICERS as $slug => $title) {
            $path = PaymentRequestSettings::officerImagePath($slug);

            $officers[$slug] = [
                'title' => $title,
                'name' => PaymentRequestSettings::officerName($slug),
                'designation' => PaymentRequestSettings::officerDesignation($slug),
                'enabled' => PaymentRequestSettings::officerEnabled($slug),
                'image_url' => $path ? Storage::disk('public')->url($path) : null,
            ];
        }

        return view('admin.payment-settings.edit', [
            'workingDays' => PaymentRequestSettings::workingDays(),
            'officers' => $officers,
            'departmentalFlowEnabled' => PaymentRequestSettings::departmentalFlowEnabled(),
            'mailEnabled' => \App\Support\PraApprovalSettings::mailEnabled(),
            // Turning the chain OFF hands new PRAs back to the legacy flow,
            // which routes to hand-picked people from the approver pool. An
            // empty pool means those PRAs would be created with no approval
            // flow at all — the pre-existing legacy behaviour, but a nasty
            // surprise for whoever flips the switch expecting a fallback.
            'activeApproverCount' => PraApprover::where('is_active', true)->count(),
            'activeCheckerCount' => PraApprover::where('is_active', true)->where('can_check', true)->count(),
        ]);
    }

    public function update(Request $request)
    {
        $officerSlugs = array_keys(PaymentRequestSettings::OFFICERS);

        $rules = [
            'pra_working_days' => ['required', 'integer', 'min:1', 'max:60'],
        ];

        foreach ($officerSlugs as $slug) {
            $rules["officers.$slug.name"] = ['nullable', 'string', 'max:120'];
            $rules["officers.$slug.designation"] = ['nullable', 'string', 'max:120'];
            $rules["officers.$slug.enabled"] = ['nullable', 'boolean'];
            $rules["officers.$slug.image"] = ['nullable', 'image', 'mimes:png,jpg,jpeg', 'max:2048'];
        }

        $validated = $request->validate($rules);

        AppSetting::put(PaymentRequestSettings::KEY_WORKING_DAYS, (string) $validated['pra_working_days']);

        AppSetting::put(
            \App\Support\PraApprovalSettings::KEY_MAIL_ENABLED,
            $request->boolean('pra_approval_mail_enabled') ? '1' : '0'
        );

        // Affects NEW PRAs only. Nothing already created is rewritten — a PRA's
        // flow is fixed on its own row at creation.
        AppSetting::put(
            PaymentRequestSettings::KEY_DEPARTMENTAL_FLOW,
            $request->boolean('pra_departmental_flow_enabled') ? '1' : '0'
        );

        foreach ($officerSlugs as $slug) {
            AppSetting::put("pra_sign_{$slug}_name", $request->input("officers.$slug.name"));
            AppSetting::put("pra_sign_{$slug}_designation", $request->input("officers.$slug.designation"));
            AppSetting::put("pra_sign_{$slug}_enabled", $request->boolean("officers.$slug.enabled") ? '1' : '0');

            $existingPath = PaymentRequestSettings::officerImagePath($slug);

            // Remove signature image when requested.
            if ($request->boolean("officers.$slug.remove_image")) {
                if ($existingPath) {
                    Storage::disk('public')->delete($existingPath);
                }
                AppSetting::put("pra_sign_{$slug}_image", null);
                $existingPath = null;
            }

            // Replace with a newly uploaded signature image.
            if ($request->hasFile("officers.$slug.image")) {
                if ($existingPath) {
                    Storage::disk('public')->delete($existingPath);
                }

                $path = $request->file("officers.$slug.image")->store('signatures', 'public');
                AppSetting::put("pra_sign_{$slug}_image", $path);
            }
        }

        return redirect()
            ->route('admin.payment-settings.edit')
            ->with('success', 'PI / PRA settings updated successfully.');
    }
}
