<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Which roles belong to which department.
 *
 * There is no departments table and no users.department_id — a user's
 * department has always been read off their role, and this is the map that does
 * it. It exists so the user form can ask for a department first and then offer
 * only that department's roles, which is the thing that stops somebody being
 * given Management access when a Store user was meant.
 *
 * Deliberately NOT added to PiAlertSettings::departmentOptions(). That map
 * drives which departments receive PI alerts, and its keys are validated
 * against the alert settings form; putting narrow roles into it would offer
 * "Store — General Stock" as an alert recipient and widen that form's accepted
 * values. Two different questions, two maps.
 *
 * The first role listed under a department is its Department Admin — the broad
 * role that covers the whole department. The rest are narrower.
 */
class DepartmentRoles
{
    /**
     * department key => [label, roles...]
     *
     * admin and management sit under Management / Admin only, not under Store.
     * They can reach Store screens, but that is oversight rather than
     * membership, and offering them under Store is how somebody ends up a full
     * admin when a store user was intended.
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

    /**
     * Department key => label, for the department select.
     *
     * @return array<string, string>
     */
    public static function departments(): array
    {
        return array_map(fn (array $d) => $d['label'], self::MAP);
    }

    /**
     * Role names belonging to a department, in listed order — Department Admin
     * first.
     *
     * @return list<string>
     */
    public static function rolesFor(?string $department): array
    {
        return self::MAP[$department]['roles'] ?? [];
    }

    /** The department a role belongs to, or null when it is mapped nowhere. */
    public static function departmentOf(?string $role): ?string
    {
        if (! $role) {
            return null;
        }

        foreach (self::MAP as $key => $department) {
            if (in_array($role, $department['roles'], true)) {
                return $key;
            }
        }

        return null;
    }

    public static function labelOf(?string $department): ?string
    {
        return self::MAP[$department]['label'] ?? null;
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
            default => Str::headline($role),
        };
    }
}
