<?php

namespace App\Policies;

use App\Models\User;

/**
 * Who may administer whom on the User Management screen.
 *
 * Two kinds of administrator reach that screen:
 *
 *   super admin       the 'admin' role. Unchanged by this policy — every method
 *                     below answers true for them on its first line, so their
 *                     access is exactly what it was before department admins
 *                     existed.
 *   department admin  users.is_department_admin, scoped to the one department
 *                     their own role maps to. They can do the same things, to
 *                     fewer people.
 *
 * Scope is decided by role, because role is where a department has always been
 * recorded — there is no users.department column. See App\Support\DepartmentRoles.
 *
 * This is the enforcement layer. The form hides the controls a department admin
 * may not use, but hiding a control has never been a control: a hand-made POST,
 * a replayed form or a guessed /admin/users/9/edit all arrive here.
 */
class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasRole('admin') || $actor->isDepartmentAdmin();
    }

    public function view(User $actor, User $target): bool
    {
        return $actor->hasRole('admin') || $this->administers($actor, $target);
    }

    public function create(User $actor): bool
    {
        return $actor->hasRole('admin') || $actor->isDepartmentAdmin();
    }

    public function update(User $actor, User $target): bool
    {
        return $actor->hasRole('admin') || $this->administers($actor, $target);
    }

    public function delete(User $actor, User $target): bool
    {
        if ($actor->hasRole('admin')) {
            return true;
        }

        // Deleting yourself is refused for everyone; the controller says so in
        // words rather than a bare 403, so it stays out of the scope check.
        return $actor->id !== $target->id && $this->administers($actor, $target);
    }

    /**
     * Resetting a password is a takeover of the account, so it is gated exactly
     * as editing is — separately, because reset-password and send-reset-link
     * are their own routes and would otherwise be an unguarded way in.
     */
    public function resetPassword(User $actor, User $target): bool
    {
        return $this->update($actor, $target);
    }

    /**
     * Only a super admin may promote or demote a department admin.
     *
     * A department admin who could set this flag could promote a colleague, or
     * themselves into another department. It is the one field on the form that
     * confers authority rather than access.
     */
    public function setDepartmentAdmin(User $actor): bool
    {
        return $actor->hasRole('admin');
    }

    /**
     * Who may grant a scoped merchant the BOM upload override.
     *
     * Deliberately NOT the super admin's field. This is the one control on the
     * user form that belongs to a department admin instead: the buyer's owner
     * decides who on their own team may upload for it. A super admin already
     * bypasses buyer scoping entirely, so there is nothing here for them to
     * set, and offering it to them would put two different authorities on one
     * checkbox.
     *
     * Three conditions, and each one is a hole if left out:
     *
     *   actor owns a buyer   an admin of no buyer grants nothing.
     *   target is theirs     the target must be scoped to the actor's own
     *                        buyer, or one buyer's admin could hand out upload
     *                        rights on another buyer's team.
     *   not yourself         a department admin already uploads by virtue of
     *                        owning the buyer; letting them tick their own box
     *                        is a self-grant with no meaning.
     */
    public function setCanUpload(User $actor, User $target): bool
    {
        if (! $actor->isMerchantDepartment() || ! $actor->isDepartmentAdmin()) {
            return false;
        }

        if ($actor->id === $target->id) {
            return false;
        }

        $buyerId = $actor->merchantBuyerId();

        return $buyerId !== null
            && ! $target->isDepartmentAdmin()
            && $target->isMerchantDepartment()
            && (int) $target->buyer_id === (int) $buyerId;
    }

    /**
     * Whether a department admin's scope covers this user.
     *
     * Three ways to fall outside it, and each one is a hole if it is left out:
     *
     *   different department  the point of the scope.
     *   no department at all  a user holding no role, or a role the map has
     *                         never heard of, belongs to nobody and stays a
     *                         super admin matter. Without this, an unmapped
     *                         role would compare null to null and match every
     *                         department admin at once.
     *   another dept admin    peers and other departments' heads are managed by
     *                         the super admin only, or one department admin
     *                         could strip another's account.
     *
     * Managing your own account is allowed and lands on the existing self-edit
     * guard in the controller, which already refuses your own role, status and
     * permissions.
     */
    private function administers(User $actor, User $target): bool
    {
        if (! $actor->isDepartmentAdmin()) {
            return false;
        }

        if ($target->id === $actor->id) {
            return true;
        }

        if ($target->is_department_admin || $target->hasRole('admin')) {
            return false;
        }

        $scope = $actor->departmentKey();
        $theirs = $target->departmentKey();

        return $theirs !== null && $theirs === $scope;
    }
}
