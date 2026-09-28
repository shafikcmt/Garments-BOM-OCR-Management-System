<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\RequisitionSignatureBlock;
use App\Models\User;
use App\Services\RequisitionHeaderResolver;
use App\Services\RequisitionSignatureResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Admin > Settings > Requisition Signatures.
 *
 * The signature boxes printed on each level of Purchase Requisition — how
 * many, their captions, the role or person behind each, and which system
 * step (if any) fills in the signed name and date.
 *
 * Who heads each department and section — what the "Requester's Department
 * Head" box and the Review queue resolve to — lives on Admin > Settings >
 * Departments (DepartmentController), so heads are named in one place.
 *
 * Saving replaces each level's boxes wholesale. They are configuration with
 * nothing pointing at them — a requisition never stores a block id, the
 * resolver reads whatever is configured at print time — so there is nothing
 * to preserve across a save and no orphan to worry about.
 */
class RequisitionSignatureSettingController extends Controller
{
    public function edit()
    {
        $blocks = RequisitionSignatureBlock::query()
            ->orderBy('level')->orderBy('sequence')->orderBy('id')
            ->get()
            ->groupBy('level');

        return view('admin.requisition-signatures.edit', [
            'levels' => RequisitionSignatureBlock::levelLabels(),
            'blocksByLevel' => $blocks,
            'assigneeTypes' => RequisitionSignatureBlock::assigneeTypeLabels(),
            'stampSources' => RequisitionSignatureBlock::stampSourceLabels(),
            'roles' => RequisitionSignatureResolver::roleLabels(),
            // Active users only for the pickers; a person already chosen but
            // since deactivated is still shown by the view so the admin can
            // see who the box points at and replace them.
            'users' => User::query()->where('status', 1)->orderBy('name')->get(),
            // The document header: Name of Unit, and the two people the
            // header names. Read by RequisitionHeaderResolver.
            'header' => [
                'unit_name' => AppSetting::get(RequisitionHeaderResolver::SETTING_UNIT_NAME, ''),
                'store_personnel' => (int) AppSetting::get(RequisitionHeaderResolver::SETTING_STORE_PERSONNEL, 0) ?: null,
                'contact_person' => (int) AppSetting::get(RequisitionHeaderResolver::SETTING_CONTACT_PERSON, 0) ?: null,
            ],
            'companyName' => config('stock.company_name'),
        ]);
    }

    public function update(Request $request)
    {
        $levels = array_keys(RequisitionSignatureBlock::levelLabels());
        $roles = array_keys(RequisitionSignatureResolver::roleLabels());

        $data = $request->validate([
            'blocks' => ['required', 'array'],
            'blocks.*' => ['nullable', 'array'],
            'blocks.*.*.label' => ['required', 'string', 'max:80'],
            'blocks.*.*.title' => ['nullable', 'string', 'max:80'],
            'blocks.*.*.assignee_type' => ['required', Rule::in(array_keys(RequisitionSignatureBlock::assigneeTypeLabels()))],
            'blocks.*.*.role_name' => ['nullable', Rule::in($roles)],
            'blocks.*.*.user_id' => ['nullable', 'integer', 'exists:users,id'],
            'blocks.*.*.stamp_source' => ['nullable', Rule::in(array_filter(array_keys(RequisitionSignatureBlock::stampSourceLabels())))],
            'header_unit_name' => ['nullable', 'string', 'max:255'],
            'header_store_personnel' => ['nullable', 'integer', 'exists:users,id'],
            'header_contact_person' => ['nullable', 'integer', 'exists:users,id'],
        ], [
            'blocks.*.*.label.required' => 'Every signature box needs a label.',
        ]);

        // A level with no boxes at all would print a document nobody signs.
        foreach ($levels as $level) {
            if (empty($data['blocks'][$level])) {
                return back()->withInput()->withErrors([
                    'blocks' => RequisitionSignatureBlock::levelLabels()[$level].' must keep at least one signature box.',
                ]);
            }
        }

        DB::transaction(function () use ($data, $levels) {
            foreach ($levels as $level) {
                RequisitionSignatureBlock::query()->where('level', $level)->delete();

                foreach (array_values($data['blocks'][$level]) as $i => $row) {
                    $type = $row['assignee_type'];

                    RequisitionSignatureBlock::create([
                        'level' => $level,
                        'sequence' => $i + 1,
                        'label' => trim($row['label']),
                        'title' => trim((string) ($row['title'] ?? '')) ?: null,
                        'assignee_type' => $type,
                        // Only the field the type uses is kept, so a box
                        // switched from role to user does not carry a stale
                        // role along with it.
                        'role_name' => $type === RequisitionSignatureBlock::ASSIGNEE_ROLE ? ($row['role_name'] ?? null) : null,
                        'user_id' => $type === RequisitionSignatureBlock::ASSIGNEE_USER ? ($row['user_id'] ?? null) : null,
                        'stamp_source' => ($row['stamp_source'] ?? '') !== '' ? $row['stamp_source'] : null,
                    ]);
                }
            }

            // The document header settings. Empty clears a setting, and the
            // resolver then falls back (company name / Store head / preparer).
            AppSetting::put(RequisitionHeaderResolver::SETTING_UNIT_NAME, trim((string) ($data['header_unit_name'] ?? '')));
            AppSetting::put(RequisitionHeaderResolver::SETTING_STORE_PERSONNEL, (string) ($data['header_store_personnel'] ?? ''));
            AppSetting::put(RequisitionHeaderResolver::SETTING_CONTACT_PERSON, (string) ($data['header_contact_person'] ?? ''));
        });

        return redirect()
            ->route('admin.requisition-signatures.edit')
            ->with('success', 'Requisition signature settings saved.');
    }
}
