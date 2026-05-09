<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('excel_headers', function (Blueprint $table) {
            $table->id();

            $table->string('header_name')->unique();
            $table->string('header_key')->unique();

            $table->foreignId('owner_role_id')->constrained('roles')->cascadeOnDelete();

            $table->unsignedInteger('position')->default(0);
            $table->string('field_type')->default('text'); // text, number, date
            $table->boolean('is_required')->default(false);
            $table->boolean('is_active')->default(true);
            $table->boolean('can_view_all')->default(true);
            $table->boolean('can_edit_owner_only')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('excel_headers');
    }
};