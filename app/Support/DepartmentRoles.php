<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Which roles belong to which department.
 *
 * There is no users.department_id — a user's department has always been
 * read off their role, and this is the map that does it. It exists so the
 * user form can ask for a department first and then offer only that
 * department's roles, which is the thing that stops somebody being given
 * Management access when a Store user was meant.
 *
 * SINCE 2026_09_23 THE MAP IS DATA: the departments and department_roles
 * tables, edited on Admin > Settings > Departments. The constant below is
 * what seeded them and is the fallback while a deploy has not yet migrated
 * — the public API is unchanged, so nothing that asks a department
 * question had to change. Read once per request and cached.
 *
 * Deliberately NOT merged with PiAlertSettings::departmentOptions(). That
 * map drives which departments receive PI alerts, and its keys are
 * validated against the alert settings form. Two different questions.
 *
 * The first role listed under a department is its Department Admin — the
 * broad role that covers the whole department. The rest are narrower.
 */
class DepartmentRoles
{
    /**
     * The seed, and the fallback before the tables exist.
     *
     * admin and management sit under Management / Admin only, not under
     * Store. They can reach Store screens, but that is oversight rather than
     * membership, and offering them under Store is how somebody ends up a
     * full admin when a store user was intended.
     *
     * @var array<string, array{label: string, roles: list<string>}>
     */
    private const MAP = [
        'merchandising' => ['label' => 'Merchandising', 'roles' => ['merchant']],
        'supply_chain' => ['label' => 'Supply Chain', 'roles' => ['supply_chain']],
        'commercial' => ['label' => 'Commercial', 'roles' => ['commercial']],
        'store' => ['label' => 'Store', 'roles' => ['store', 'store_general_stock', 'store_material_stock']],
        'accounts' => ['label' => 'Accounts', 'roles' => ['account']],
        'production' => ['label' => 'Production', 'roles' => ['production']],
        'management' => ['label' => 'Management / Admin', 'roles' => ['admin', 'management']],
    ];

    /** Roles shown when no department is chosen, or for a role that is mapped nowhere. */
    public const UNASSIGNED = 'other';

    private const CACHE = 'departmentRoles.map';

    /**
     * The live map: active departments in the admin's order, each with its
     * roles, primary first. Falls back to the constant when the tables are
     * not there yet.
     *
     * @return array<string, array{label: string, roles: list<string>}>
     */
    public static function map(): array
    {
        // Cached on the container, not in a static: the container is rebuilt
        // per request and per test, so an edit on the settings screen is seen
        // by the next request without a static to remember to clear.
        if (app()->bound(self::CACHE)) {
            return app(self::CACHE);
        }

        if (! Schema::hasTable('departments') || ! Schema::hasTable('department_roles')) {
            app()->instance(self::CACHE, self::MAP);

            return self::MAP;
        }

        $roles = DB::table('department_roles')
            ->orderByDesc('is_primary')->orderBy('sort_order')->orderBy('id')
            ->get()
            ->groupBy('department_key');

        $map = [];

        foreach (DB::table('departments')->where('is_active', true)->orderBy('sort_order')->orderBy('label')->get() as $d) {
            $map[$d->key] = [
                'label' => $d->label,
                'roles' => ($roles[$d->key] ?? collect())->pluck('role_name')->values()->all(),
            ];
        }

        app()->instance(self::CACHE, $map);

        return $map;
    }

    /** Drop the per-request cache — after the admin edits the list. */
    public static function refresh(): void
    {
        app()->forgetInstance(self::CACHE);
    }

    /**
     * Department key => label, for the department select.
     *
     * @return array<string, string>
     */
    public static function departments(): array
    {
        return array_map(fn (array $d) => $d['label'], self::map());
    }

    /**
     * Role names belonging to a department, in listed order — Department Admin
     * first.
     *
     * @return list<string>
     */
    public static function rolesFor(?string $department): array
    {
        return self::map()[$department]['roles'] ?? [];
    }

    /** The department a role belongs to, or null when it is mapped nowhere. */
    public static function departmentOf(?string $role): ?string
    {
        if (! $role) {
            return null;
        }

        foreach (self::map() as $key => $department) {
            if (in_array($role, $department['roles'], true)) {
                return $key;
            }
        }

        return null;
    }

    public static function labelOf(?string $department): ?string
    {
        return self::map()[$department]['label'] ?? null;
    }

    /**
     * role name => department key, for the form's client-side filtering.
     *
     * Every role in the database is included, not just the mapped ones: a role
     * this map has never heard of still has to be selectable, or the only way
     * to edit a user who holds one would be to change their role first.
     *
     * @param  iterable<object>  $roles
     * @return array<string, string>
     */
    public static function indexFor(iterable $roles): array
    {
        $index = [];

        foreach ($roles as $role) {
            $index[$role->name] = self::departmentOf($role->name) ?? self::UNASSIGNED;
        }

        return $index;
    }

    /** "Store — General Stock" from "store_general_stock". */
    public static function roleLabel(string $role): string
    {
        return match ($role) {
            'store_general_stock' => 'Store — General Stock',
            'store_material_stock' => 'Store — Buyer / Style Stock',
            'harc' => 'HARC',
            default => Str::headline($role),
        };
    }
}
