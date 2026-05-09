<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_delivery_destinations', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('details');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        $now = now();
        DB::table('booking_delivery_destinations')->insert([
            [
                'title' => 'Humana Apparels Private Limited',
                'details' => "Humana Apparels Private Limited
Momin Nagar, Gorai, Mirzapur, Tangail - 1941, Bangladesh
Attn: Robin / Ashif Contact: +8801992371918 / +8801914650402
BIN: 005635381-0406 TIN: 780096271681",
                'is_active' => true,
                'sort_order' => 10,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_delivery_destinations');
    }
};
