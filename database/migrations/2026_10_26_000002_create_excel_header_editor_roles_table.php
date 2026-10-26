<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extra roles that may edit a workspace column besides its owner role, set
 * by Admin on Excel Header Control (e.g. shipment columns edited by both
 * Merchandising and Commercial). The owner role still owns the column for
 * progress and reports; this only widens who may type into it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('excel_header_editor_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('excel_header_id')->constrained('excel_headers')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['excel_header_id', 'role_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('excel_header_editor_roles');
    }
};
