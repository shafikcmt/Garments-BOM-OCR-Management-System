<?php

use App\Models\AppNotification;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;

/**
 * File upload / edit notifications used to go to every non-merchant user,
 * including people who never open the Workspace (for example a Supply Chain
 * General purchaser). New ones now go only to users who can open it
 * (User::canOpenWorkspace()). This clears the backlog those users already have:
 * their unread Workspace file notifications are marked read.
 *
 * Nothing is deleted, and users who can open the Workspace are not touched.
 * Data only; down() does nothing because "unread" cannot be told apart again.
 */
return new class extends Migration
{
    private const TYPES = ['excel_uploaded', 'excel_updated', 'order_changed'];

    public function up(): void
    {
        $userIds = AppNotification::query()
            ->whereIn('type', self::TYPES)
            ->whereNull('read_at')
            ->distinct()
            ->pluck('user_id');

        User::whereIn('id', $userIds)->get()
            ->reject(fn (User $user) => $user->canOpenWorkspace())
            ->each(fn (User $user) => AppNotification::query()
                ->where('user_id', $user->id)
                ->whereIn('type', self::TYPES)
                ->whereNull('read_at')
                ->update(['read_at' => now()]));
    }

    public function down(): void
    {
        //
    }
};
