<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
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
