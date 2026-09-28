<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * History rows for requisitions raised before the history existed, read from
 * the stamp columns already on each sheet.
 *
 * Add-only: no requisition is touched, and a sheet that already has history
 * is skipped. Every row carries meta.backfilled = true, and down() removes
 * exactly those. Only the LAST round of each stamp survives on the sheet, so
 * an earlier return-and-resubmit cycle cannot be recovered; the detail page
 * says history is complete only from 28 Sep 2026.
 */
return new class extends Migration
{
    public function up(): void
    {
        $names = DB::table('users')->pluck('name', 'id');

        DB::table('purchase_requisitions')->orderBy('id')->chunk(200, function ($sheets) use ($names) {
            $rows = [];

            foreach ($sheets as $sheet) {
                if (DB::table('purchase_requisition_actions')->where('purchase_requisition_id', $sheet->id)->exists()) {
                    continue;
                }

                $add = function (string $action, ?string $stage, ?string $toStatus, $userId, $at, ?string $comment = null) use (&$rows, $sheet, $names) {
                    $rows[] = [
                        'purchase_requisition_id' => $sheet->id,
                        'requisition_no' => $sheet->requisition_no,
                        'action' => $action,
                        'stage' => $stage,
                        'from_status' => null,
                        'to_status' => $toStatus,
                        'user_id' => $userId,
                        'user_name' => $userId ? ($names[$userId] ?? null) : null,
                        'comment' => $comment,
                        'cycle' => 1,
                        'meta' => json_encode(['backfilled' => true]),
                        'created_at' => $at,
                    ];
                };

                if ($sheet->submitted_at) {
                    $add('submitted', 'raiser', 'submitted', $sheet->created_by, $sheet->submitted_at);
                } else {
                    $add('created', 'raiser', $sheet->status, $sheet->created_by, $sheet->created_at);
                }

                if (in_array($sheet->department_review_status, ['approved', 'rejected'], true)) {
                    $section = $sheet->review_level === 'section';
                    $by = $section ? $sheet->section_checked_by : $sheet->department_checked_by;
                    $at = $section ? $sheet->section_checked_at : $sheet->department_checked_at;

                    if ($at) {
                        $add(
                            $sheet->department_review_status === 'approved' ? 'head_approved' : 'head_returned',
                            $section ? 'section_head' : 'department_head',
                            'submitted',
                            $by,
                            $at,
                            $sheet->department_review_status === 'rejected' ? $sheet->department_review_note : null,
                        );
                    }
                }

                if ($sheet->store_ack_at) {
                    $add('received', 'store', 'store_reviewed', $sheet->store_ack_by, $sheet->store_ack_at);
                }

                if ($sheet->checked_at) {
                    $add('corrected', 'store', null, $sheet->checked_by, $sheet->checked_at);
                }

                if ($sheet->approved_at) {
                    $add('approved', 'final_approval', 'approved', $sheet->approved_by, $sheet->approved_at);
                }
            }

            if ($rows !== []) {
                DB::table('purchase_requisition_actions')->insert($rows);
            }
        });
    }

    public function down(): void
    {
        DB::table('purchase_requisition_actions')->where('meta->backfilled', true)->delete();
    }
};
