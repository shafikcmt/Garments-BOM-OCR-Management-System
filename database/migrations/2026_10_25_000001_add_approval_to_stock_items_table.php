<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Approval point for new Item Master entries.
 *
 * Every existing item is 'approved' (the column default), so nothing already in
 * use changes. A new item added by a user who cannot approve starts 'pending'
 * and inactive, and only becomes usable once an approver accepts it.
 */
return new class extends Migration
{
    private const PERMISSION = 'store.items.approve';

    public function up(): void
    {
        Schema::table('stock_items', function (Blueprint $table) {
            $table->string('approval_status', 20)->default('approved')->after('is_active')->index();
            $table->unsignedBigInteger('approved_by')->nullable()->after('approval_status');
            $table->timestamp('approved_at')->nullable()->after('approved_by');
        });

        $permission = Permission::findOrCreate(self::PERMISSION, 'web');
        Role::where('name', 'admin')->first()?->givePermissionTo($permission);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::table('stock_items', function (Blueprint $table) {
            $table->dropIndex(['approval_status']);
            $table->dropColumn(['approval_status', 'approved_by', 'approved_at']);
        });

        Permission::where('name', self::PERMISSION)->first()?->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
