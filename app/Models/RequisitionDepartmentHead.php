<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The configured head of one department (a Spatie role), for the Purchase
 * Requisition workflow. See the migration for why this is a mapping table.
 */
class RequisitionDepartmentHead extends Model
{
    protected $fillable = ['role_name', 'user_id'];

    /**
     * The departments a head can be named for, with the label a manager
     * would use. The keys are role names — the only place this project
     * records which department a user belongs to.
     *
     * @return array<string, string> role => label
     */
    public static function departmentOptions(): array
    {
        return [
            'merchant' => 'Merchandising',
            'commercial' => 'Commercial',
            'account' => 'Accounts',
            'supply_chain' => 'Supply Chain',
            'production' => 'Production',
            'store' => 'Store',
        ];
    }

    public static function departmentLabel(?string $role): string
    {
        return self::departmentOptions()[$role] ?? ucwords(str_replace('_', ' ', (string) $role));
    }

    /**
     * The department a user raises requisitions FOR: the first of their roles
     * that names a department. Store-side users (store / admin / management)
     * raise final-level requisitions, which have no department step, so
     * their department is not asked for here.
     */
    public static function departmentOf(?User $user): ?string
    {
        if (! $user) {
            return null;
        }

        foreach (array_keys(self::departmentOptions()) as $role) {
            if ($user->hasRole($role)) {
                return $role;
            }
        }

        return null;
    }

    /** The head user of a department, or null when none is configured. */
    public static function headOf(?string $role): ?User
    {
        if (! $role) {
            return null;
        }

        return self::query()->where('role_name', $role)->with('user')->first()?->user;
    }

    /**
     * The departments this user heads.
     *
     * @return array<int, string> role names
     */
    public static function departmentsHeadedBy(?User $user): array
    {
        if (! $user) {
            return [];
        }

        return self::query()->where('user_id', $user->id)->pluck('role_name')->all();
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
