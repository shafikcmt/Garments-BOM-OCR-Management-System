<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\GsComparativeStatement;
use Illuminate\Http\Request;

/**
 * Admin > Settings > CS Signatures.
 *
 * The signature boxes a NEW Comparative Statement starts with. Each sheet
 * keeps its own copy from the moment it is saved, so changing this never
 * alters a sheet already made; the maker can still adjust one sheet's boxes
 * on its form.
 */
class CsSignatureSettingController extends Controller
{
    public function edit()
    {
        return view('admin.cs-signatures.edit', [
            'boxes' => old('boxes', GsComparativeStatement::defaultSignatories()),
            'roles' => GsComparativeStatement::SIGN_ROLES,
            'max' => GsComparativeStatement::MAX_SIGNATORIES,
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'boxes' => ['nullable', 'array', 'max:'.GsComparativeStatement::MAX_SIGNATORIES],
            'boxes.*.label' => ['nullable', 'string', 'max:60'],
            'boxes.*.role' => ['nullable', 'in:'.implode(',', array_keys(GsComparativeStatement::SIGN_ROLES))],
        ]);

        $boxes = GsComparativeStatement::cleanSignatories($data['boxes'] ?? []);

        if ($request->boolean('reset') || $boxes === []) {
            AppSetting::put(GsComparativeStatement::SIGN_SETTING, '');

            return redirect()->route('admin.cs-signatures.edit')->with('success', 'Back to the standard three signature boxes.');
        }

        AppSetting::put(GsComparativeStatement::SIGN_SETTING, json_encode($boxes));

        return redirect()->route('admin.cs-signatures.edit')->with('success', 'Signature boxes saved. New Comparative Statements will start with them.');
    }
}
