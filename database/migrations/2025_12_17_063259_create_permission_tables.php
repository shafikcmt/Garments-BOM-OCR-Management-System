<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Spatie Permission Tables for Garments OCR System
|--------------------------------------------------------------------------
|
| This migration creates the tables needed for role and permission management.
| 
| Tables included:
| 1. roles                - Stores role names (Admin, Merchant, Supply Chain, etc.)
| 2. permissions          - Stores fine-grained permissions (create_order, approve_order, etc.)
| 3. model_has_roles      - Pivot table linking users to roles (many-to-many)
| 4. model_has_permissions- Pivot table linking users to permissions (optional)
| 5. role_has_permissions - Pivot table linking roles to permissions
|
| Notes:
| - Minimum required: roles + model_has_roles + users
| - Permissions tables are optional if you only use role-based access
| - Users table is assumed to already exist
*/


return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
         // -------------------------------
        // Permissions Table
        // -------------------------------
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('guard_name')->default('web');
            $table->timestamps();
        });

        // -------------------------------
        // Roles Table
        // -------------------------------
        Schema::create('roles', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name')->unique();
            $table->string('description')->nullable();
            $table->string('guard_name')->default('web');
            $table->timestamps();
        });

        // -------------------------------
        // Model Has Permissions (pivot) Table
        // -------------------------------
        Schema::create('model_has_permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('permission_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->index(['model_id', 'model_type']);
            $table->foreign('permission_id')->references('id')->on('permissions')->onDelete('cascade');
            $table->primary(['permission_id', 'model_id', 'model_type']);
        });

       
        // -------------------------------
        // Model Has Roles (pivot) Table
        // -------------------------------
        Schema::create('model_has_roles', function (Blueprint $table) {
            $table->unsignedBigInteger('role_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->index(['model_id', 'model_type']);
            $table->foreign('role_id')->references('id')->on('roles')->onDelete('cascade');
            $table->primary(['role_id', 'model_id', 'model_type']);
        });

     

        // -------------------------------
        // Role Has Permissions (pivot) Table
        // -------------------------------
        Schema::create('role_has_permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('permission_id');
            $table->unsignedBigInteger('role_id');
            $table->foreign('permission_id')->references('id')->on('permissions')->onDelete('cascade');
            $table->foreign('role_id')->references('id')->on('roles')->onDelete('cascade');
            $table->primary(['permission_id', 'role_id']);
        });

        // Clear Spatie cache
        app('cache')
            ->store(config('permission.cache.store') != 'default' ? config('permission.cache.store') : null)
            ->forget(config('permission.cache.key'));
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('role_has_permissions');
        Schema::dropIfExists('model_has_permissions');
        Schema::dropIfExists('model_has_roles');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
    }
};
