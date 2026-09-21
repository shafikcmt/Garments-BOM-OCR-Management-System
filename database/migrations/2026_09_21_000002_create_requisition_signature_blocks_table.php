<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The signature footer of a Purchase Requisition, as data.
 *
 * Until now the footer was three columns typed into the PDF and Excel views —
 * Prepared by / Checked by / Approved by — and the header block's Store
 * Acknowledgement / Accounts Acknowledgement / Approved By were typed there
 * too. The company's paper form has since settled on THREE boxes for a
 * department's sheet and FIVE for the merged one, and the labels and the
 * people behind them are the sort of thing that changes when a head of
 * department does. So they live here, and Admin > Settings edits them.
 *
 * One row = one box. `level` says which sheet it prints on, `sequence` the
 * left-to-right order, `label` the caption over the box.
 *
 * `assignee_type` says whose name is printed under the line:
 *
 *   requester        whoever raised the requisition
 *   department_head  the configured head of the requester's department
 *                    (requisition_department_heads)
 *   role             everyone holding `role_name` — prints the role's label
 *   user             one named person, `user_id`
 *
 * `stamp_source` says which system action, if any, fills in the signed name
 * and date. Everything else is signed on paper:
 *
 *   NULL             paper only — prints the configured name, no date
 *   prepared         created_by / submitted_at
 *   department_head  department_checked_by / department_checked_at
 *   store_head       store_ack_by / store_ack_at   (the "Received" button)
 *   approved         approved_by / approved_at     (the "Approve" button)
 *
 * The defaults seeded below reproduce the paper form as it stands today. They
 * are inserted only into an EMPTY table, so re-running this on a database
 * where an admin has already edited the blocks changes nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('requisition_signature_blocks', function (Blueprint $table) {
            $table->id();
            $table->string('level', 20)->index();
            $table->unsignedSmallInteger('sequence')->default(1);
            $table->string('label', 80);
            // The designation printed under the name — "HR Head", "Operation
            // Head". Optional; when the box has no named person yet this is
            // what identifies it on paper.
            $table->string('title', 80)->nullable();
            $table->string('assignee_type', 30)->default('role');
            $table->string('role_name', 60)->nullable();
            // Cascade: a deleted user cannot remain the named signatory of
            // anything. The box survives — it simply has nobody's name under
            // it until the admin picks someone else.
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('stamp_source', 30)->nullable();
            $table->timestamps();

            $table->index(['level', 'sequence']);
        });

        $this->seedDefaults();
    }

    public function down(): void
    {
        Schema::dropIfExists('requisition_signature_blocks');
    }

    private function seedDefaults(): void
    {
        if (DB::table('requisition_signature_blocks')->exists()) {
            return;
        }

        $now = now();
        $row = fn (string $level, int $seq, string $label, string $title, string $type, ?string $role, ?string $stamp) => [
            'level' => $level,
            'sequence' => $seq,
            'label' => $label,
            'title' => $title,
            'assignee_type' => $type,
            'role_name' => $role,
            'user_id' => null,
            'stamp_source' => $stamp,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        DB::table('requisition_signature_blocks')->insert([
            // A department's own sheet: three boxes.
            $row('department', 1, 'Prepared by', 'Requesting Department', 'requester', null, 'prepared'),
            $row('department', 2, 'Checked by', 'Department Head', 'department_head', null, 'department_head'),
            $row('department', 3, 'Checked by', 'Store Head', 'role', 'store', 'store_head'),

            // The merged Purchase Requisition the Store raises: five boxes.
            // The three heads are paper signatures — nobody clicks for them —
            // so they carry no stamp source, and the admin names the person.
            $row('final', 1, 'Prepared by', 'Store', 'role', 'store', 'prepared'),
            $row('final', 2, 'Checked by', 'Store Head', 'role', 'store', 'store_head'),
            $row('final', 3, 'Audit by', 'HR Head', 'user', null, null),
            $row('final', 4, 'Checked by', 'Finance & Commercial Head', 'user', null, null),
            $row('final', 5, 'Approved by', 'Operation Head', 'user', null, 'approved'),
        ]);
    }
};
