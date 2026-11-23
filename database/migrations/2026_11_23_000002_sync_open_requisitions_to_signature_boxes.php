<?php

use App\Models\RequisitionSignatureBlock;
use App\Services\RequisitionWorkflow;
use Illuminate\Database\Migrations\Migration;

/**
 * Saving the signature settings now updates sheets already on their way
 * (RequisitionWorkflow::syncOpenSheets). A box Admin removed before this
 * shipped still shows on the sheets raised earlier, so the same sync runs
 * once here. Stages already signed are not touched. down() does nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(RequisitionWorkflow::class)->syncOpenSheets(array_keys(RequisitionSignatureBlock::levelLabels()));
    }

    public function down(): void
    {
        //
    }
};
