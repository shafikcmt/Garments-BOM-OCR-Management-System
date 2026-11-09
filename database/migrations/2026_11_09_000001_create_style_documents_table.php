<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Document bank of a style: tech pack, approved lab dip, PP comments, test
 * reports and the like, kept with the style instead of in e-mail. The file is
 * stored privately and served through the application.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('style_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mother_style_id')->constrained('mother_styles')->cascadeOnDelete();
            $table->string('category', 60)->nullable();
            $table->string('title', 160);
            $table->string('file_path', 500);
            $table->string('original_name', 255);
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('file_size')->default(0);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('style_documents');
    }
};
