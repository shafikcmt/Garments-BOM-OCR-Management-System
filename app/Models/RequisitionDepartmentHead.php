<?php

namespace App\Models;

use App\Services\RequisitionReviewChain;
use App\Support\DepartmentRoles;
use Illuminate\Database\Eloquent\Model;

/**
 * Who heads a department, for the Purchase Requisition workflow.
 *
 * This began as its own table (role_name -> user). Departments are data now
 * (2026_09_23_000001) and the head is departments.head_user_id, edited on
 * Admin > Settings > Departments. The static API below is what every caller
 * ever used, so it stays and simply reads the new home; the old table is
 * kept, unread, and its rows were copied across by that migration.
 *
 * Keys are department keys (production, harc). A role name is accepted
 * too and resolved to its department, because purchase_requisitions rows
 * written before the change carry the requester's role.
 */
class RequisitionDepartmentHead extends Model
{
    protected $fillable = ['role_name', 'user_id'];

    /**
     * The departments a requisition can belong to, with the label a manager
     * would use. Management / Admin is left out: its people raise the Store's
     * own final sheet, which has no department step.
     *
     * @return array<string, string> key => label
     */
    public static function departmentOptions(): array
    {
        return array_filter(
            DepartmentRoles::departments(),
            fn ($label, $key) => $key !== 'management',
            ARRAY_FILTER_USE_BOTH
        );
    }

    public static function departmentLabel(?string $keyOrRole): string
    {
        $key = self::normalizeKey($keyOrRole);

        return DepartmentRoles::labelOf($key) ?? ucwords(str_replace('_', ' ', (string) $keyOrRole));
    }

    /**
     * The department a user raises requisitions FOR — the department of
     * the first of their roles that belongs to one. Store-side users raise
     * final-level requisitions, which have no department step, so their
     * department is not asked for here.
     */
    public static function departmentOf(?User $user): ?string
    {
        return RequisitionReviewChain::departmentOf($user);
    }

    /** The head user of a department, or null when none is configured. */
    public static function headOf(?string $keyOrRole): ?User
    {
        $key = self::normalizeKey($keyOrRole);

        return $key ? Department::query()->where('key', $key)->first()?->head : null;
    }

    /**
     * The departments this user heads.
     *
     * @return array<int, string> department keys
     */
    public static function departmentsHeadedBy(?User $user): array
    {
        if (! $user) {
            return [];
        }

        return Department::query()->active()->where('head_user_id', $user->id)->pluck('key')->all();
    }

    /**
     * The sections this user heads.
     *
     * @return array<int, int> indent_sections ids
     */
    public static function sectionsHeadedBy(?User $user): array
    {
        if (! $user) {
            return [];
        }

        return IndentSection::query()->where('head_user_id', $user->id)->pluck('id')->all();
    }

    /** Name (or clear) the head of a department. */
    public static function assign(string $keyOrRole, ?User $head): void
    {
        $key = self::normalizeKey($keyOrRole);

        if ($key) {
            Department::query()->where('key', $key)->update(['head_user_id' => $head?->id]);
        }
    }

    /** A department key as given, or the department of a role name. */
    public static function normalizeKey(?string $keyOrRole): ?string
    {
        if (! $keyOrRole) {
            return null;
        }

        if (DepartmentRoles::labelOf($keyOrRole) !== null) {
            return $keyOrRole;
        }

        return DepartmentRoles::departmentOf($keyOrRole);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
