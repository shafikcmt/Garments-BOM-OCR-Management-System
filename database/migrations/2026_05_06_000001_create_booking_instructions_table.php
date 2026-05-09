<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_instructions', function (Blueprint $table) {
            $table->id();
            $table->text('instruction');
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        $now = now();
        DB::table('booking_instructions')->insert([
            [
                'instruction' => 'Please make sure buyer-required quality and approval are completed before bulk production.',
                'is_default' => true,
                'is_active' => true,
                'sort_order' => 10,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'instruction' => 'Please mention style no., buyer name, PO number, ship mode and incoterm in PI, challan and shipping documents.',
                'is_default' => true,
                'is_active' => true,
                'sort_order' => 20,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'instruction' => 'Bulk booking consumption must match approved BOM / usage standard.',
                'is_default' => true,
                'is_active' => true,
                'sort_order' => 30,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'instruction' => 'Approved dye-lot, inspection report and test report should be shared before shipment.',
                'is_default' => true,
                'is_active' => true,
                'sort_order' => 40,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'instruction' => 'Supplier must maintain delivery schedule strictly and share final shipping document draft for checking.',
                'is_default' => true,
                'is_active' => true,
                'sort_order' => 50,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'instruction' => 'Add any buyer-specific special requirement only in this notes section.',
                'is_default' => true,
                'is_active' => true,
                'sort_order' => 60,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_instructions');
    }
};
