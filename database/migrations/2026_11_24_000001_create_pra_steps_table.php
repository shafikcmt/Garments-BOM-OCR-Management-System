<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * THE STEPS AND SIGNATURE BOXES OF A PRA, AS DATA.
 *
 * The departmental PRA chain used to be five fixed steps (2..6) written in
 * PraStageResolver, with four fixed signature boxes. Admin can now define them on
 * Settings > PRA Approval Steps: how many steps there are, their order, who each
 * is for (a department's Team or Head, or only the people Admin names, per buyer)
 * and which steps print a signature box and with what title.
 *
 * `sequence` is the step's permanent id (pra_approvals.sequence, assignments and
 * pra_step_approvers all point at it), `position` is its place in the chain. A
 * step is never deleted, only switched off (`is_active`), so the history of PRAs
 * that went through it still reads correctly.
 *
 * Seeded with exactly today's chain, so nothing changes until Admin edits it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pra_steps')) {
            Schema::create('pra_steps', function (Blueprint $table) {
                $table->id();
                $table->unsignedSmallInteger('sequence')->unique();
                $table->unsignedSmallInteger('position')->default(0);
                $table->string('label', 120);
                // A department key (see DepartmentRoles), or null = only the people Admin names.
                $table->string('department', 40)->nullable();
                $table->string('actor_kind', 10)->default('team'); // team | head
                $table->string('stage', 10)->default('check');     // check | approve | complete
                $table->boolean('buyer_scoped')->default(false);
                // Title of the printed signature box for this step; null = no box.
                $table->string('signature_label', 120)->nullable();
                // Which step fills the printed "OCR Checked" box / the "Paid / Completed by" line.
                $table->boolean('prints_ocr_check')->default(false);
                $table->boolean('prints_completion')->default(false);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (DB::table('pra_steps')->exists()) {
            return;
        }

        $setting = function (string $key, string $default) {
            $value = Schema::hasTable('app_settings') ? DB::table('app_settings')->where('key', $key)->value('value') : null;

            return trim((string) $value) !== '' ? trim((string) $value) : $default;
        };

        $now = now();
        $rows = [
            ['sequence' => 2, 'label' => 'Merchant Team Check', 'department' => 'merchandising', 'actor_kind' => 'team', 'stage' => 'check', 'buyer_scoped' => true,
                'signature_label' => $setting('pra_flow2_label_checked', 'Checked By'), 'prints_ocr_check' => false, 'prints_completion' => false],
            ['sequence' => 3, 'label' => 'Merchant Head Approval', 'department' => 'merchandising', 'actor_kind' => 'head', 'stage' => 'approve', 'buyer_scoped' => true,
                'signature_label' => $setting('pra_flow2_label_approved_merchant', 'Approved By (Merchant)'), 'prints_ocr_check' => false, 'prints_completion' => false],
            ['sequence' => 4, 'label' => 'Accounts Team Check', 'department' => 'accounts', 'actor_kind' => 'team', 'stage' => 'check', 'buyer_scoped' => false,
                'signature_label' => null, 'prints_ocr_check' => true, 'prints_completion' => false],
            ['sequence' => 5, 'label' => 'Accounts Head Approval', 'department' => 'accounts', 'actor_kind' => 'head', 'stage' => 'approve', 'buyer_scoped' => false,
                'signature_label' => $setting('pra_flow2_label_approved_finance', 'Approved By (Finance and Commercial)'), 'prints_ocr_check' => false, 'prints_completion' => false],
            ['sequence' => 6, 'label' => 'Commercial Head Completion', 'department' => 'commercial', 'actor_kind' => 'head', 'stage' => 'complete', 'buyer_scoped' => false,
                'signature_label' => null, 'prints_ocr_check' => false, 'prints_completion' => true],
        ];

        foreach ($rows as $index => $row) {
            DB::table('pra_steps')->insert($row + [
                'position' => ($index + 1) * 10,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pra_steps');
    }
};
