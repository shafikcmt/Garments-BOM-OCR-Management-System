<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\RequisitionDepartmentHead;
use App\Models\RequisitionSignatureBlock;
use App\Models\User;
use App\Services\RequisitionSignatureResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Admin > Settings > Requisition Signatures.
 *
 * Two things on one screen, because they are read together:
 *
 *   - the signature boxes printed on each level of Purchase Requisition —
 *     how many, their captions, the role or person behind each, and which
 *     system step (if any) fills in the signed name and date;
 *   - who heads each department, which is what the department-level
 *     "Checked by — Department Head" box and the Department Review queue
 *     resolve to.
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
            'departments' => RequisitionDepartmentHead::departmentOptions(),
            'heads' => RequisitionDepartmentHead::query()->pluck('user_id', 'role_name')->all(),
            // Active users only for the pickers; a person already chosen but
            // since deactivated is still shown by the view so the admin can
            // see who the box points at and replace them.
            'users' => User::query()->where('status', 1)->orderBy('name')->get(),
        ]);
    }

    public function update(Request $request)
    {
        $levels = array_keys(RequisitionSignatureBlock::levelLabels());
        $roles = array_keys(RequisitionSignatureResolver::roleLabels());
        $departments = array_keys(RequisitionDepartmentHead::departmentOptions());

        $data = $request->validate([
            'blocks' => ['required', 'array'],
            'blocks.*' => ['nullable', 'array'],
            'blocks.*.*.label' => ['required', 'string', 'max:80'],
            'blocks.*.*.title' => ['nullable', 'string', 'max:80'],
            'blocks.*.*.assignee_type' => ['required', Rule::in(array_keys(RequisitionSignatureBlock::assigneeTypeLabels()))],
            'blocks.*.*.role_name' => ['nullable', Rule::in($roles)],
            'blocks.*.*.user_id' => ['nullable', 'integer', 'exists:users,id'],
            'blocks.*.*.stamp_source' => ['nullable', Rule::in(array_filter(array_keys(RequisitionSignatureBlock::stampSourceLabels())))],
            'heads' => ['nullable', 'array'],
            'heads.*' => ['nullable', 'integer', 'exists:users,id'],
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

        DB::transaction(function () use ($data, $levels, $departments) {
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

            foreach ($departments as $role) {
                $userId = $data['heads'][$role] ?? null;

                if ($userId) {
                    RequisitionDepartmentHead::updateOrCreate(['role_name' => $role], ['user_id' => $userId]);
                } else {
                    RequisitionDepartmentHead::query()->where('role_name', $role)->delete();
                }
            }
        });

        return redirect()
            ->route('admin.requisition-signatures.edit')
            ->with('success', 'Requisition signature settings saved.');
    }
}
