<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Role;

/**
 * What one administrator may hand out on the User Management screen.
 *
 * Three questions, all asked of the person doing the editing rather than the
 * person being edited:
 *
 *   which users can they see       departmentKey(), applied as a filter
 *   which roles can they assign    assignableRoles()
 *   which permissions can they grant  grantablePermissions()
 *
 * A super admin is unlimited on all three, and every method says so first, so
 * the existing screen behaves exactly as it did.
 *
 * The rule that matters is the last one: a department admin may grant only what
 * they themselves hold. Without it, the permission matrix — which lists every
 * permission in the system — is a self-service escalation form: tick
 * users.delete, save, and you have given away a right nobody gave you.
 */
class UserAdministrationScope
{
    public function __construct(private readonly User $actor)
    {
    }

    public static function for(User $actor): self
    {
        return new self($actor);
    }

    public function isSuperAdmin(): bool
    {
        return $this->actor->hasRole('admin');
    }

    /** The one department a department admin is confined to; null for a super admin. */
    public function departmentKey(): ?string
    {
        return $this->isSuperAdmin() ? null : $this->actor->departmentKey();
    }

    public function departmentLabel(): ?string
    {
        return DepartmentRoles::labelOf($this->departmentKey());
    }

    /**
     * Roles this administrator may assign, in the order the dropdown shows.
     *
     * A department admin gets their own department's roles and nothing else.
     * Note what is deliberately dropped for them: the existing form lets an
     * unmapped role through, so that a user holding a legacy role can still be
     * edited. That allowance is a super admin's — for a department admin an
     * unmapped role is a role of unknown reach, which is exactly the thing the
     * scope exists to refuse.
     *
     * @return Collection<int, Role>
     */
    public function assignableRoles(): Collection
    {
        $roles = Role::with('permissions')->orderBy('name')->get();

        if ($this->isSuperAdmin()) {
            return $roles;
        }

        $allowed = DepartmentRoles::rolesFor($this->departmentKey());

        return $roles->filter(fn (Role $role) => in_array($role->name, $allowed, true))->values();
    }

    public function mayAssignRole(?string $role): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        return $role !== null
            && in_array($role, DepartmentRoles::rolesFor($this->departmentKey()), true);
    }

    /**
     * Permission names this administrator may switch on or off for someone else
     * — everything they themselves hold, whether by role or by direct grant.
     *
     * Null means "no limit" (super admin), which is not the same as an empty
     * list and must not be flattened into one.
     *
     * @return list<string>|null
     */
    public function grantablePermissions(): ?array
    {
        if ($this->isSuperAdmin()) {
            return null;
        }

        return $this->actor->getAllPermissions()->pluck('name')->all();
    }

    public function mayGrant(string $permission): bool
    {
        $grantable = $this->grantablePermissions();

        return $grantable === null || in_array($permission, $grantable, true);
    }

    /** Only a super admin may promote someone to department admin. */
    public function maySetDepartmentAdminFlag(): bool
    {
        return $this->isSuperAdmin();
    }
}
