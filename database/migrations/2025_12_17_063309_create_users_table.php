<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // -------------------------------
        // Users Table
        // -------------------------------
        Schema::create('users', function (Blueprint $table) {
            $table->id(); // bigint PK
            $table->string('name'); // User name
            $table->string('email')->unique(); // Login email
            $table->string('password'); // Hashed password
            $table->foreignId('role_id')->nullable()->constrained('roles')->onDelete('set null'); // Primary role (optional, dynamic roles via Spatie)
            $table->tinyInteger('status')->default(1); // Active = 1 / Inactive = 0
            $table->rememberToken(); // For "remember me"
            $table->timestamps(); // created_at, updated_at
        });

        // -------------------------------
        // Password Reset Tokens Table
        // -------------------------------
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary(); // User email (unique)
            $table->string('token'); // Reset token
            $table->timestamp('created_at')->nullable(); // Token creation time
        });

        // -------------------------------
        // Sessions Table
        // -------------------------------
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary(); // Session ID
            $table->foreignId('user_id')->nullable()->index()->constrained('users')->onDelete('set null'); // FK to users
            $table->string('ip_address', 45)->nullable(); // User IP
            $table->text('user_agent')->nullable(); // Browser / device info
            $table->longText('payload'); // Session data
            $table->integer('last_activity')->index(); // Timestamp of last activity
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
