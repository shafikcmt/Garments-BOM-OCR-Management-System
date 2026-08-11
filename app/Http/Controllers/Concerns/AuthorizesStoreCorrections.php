<?php

namespace App\Http\Controllers\Concerns;

/**
 * Server-side guard for the store correction actions (edit / delete).
 *
 * Business rule, already established for Bulk Issuing and now applied to every
 * store record: a Store user records a movement but may not edit or delete it
 * afterwards, because every change recomputes closing stock that other
 * departments and management read. Corrections are an Admin / Management
 * responsibility, carried by the store.edit and store.delete permissions.
 *
 * Hiding the buttons in a view is presentation only. This is what actually stops
 * a hand-crafted request, a replayed form from a cached page, or a direct API
 * call from a role without the permission.
 *
 * SECTION-AWARE. The flat store.edit / store.delete pair predates the
 * sub-section permissions and covers every General Stock screen at once, so a
 * correction right could not be given for Issues without also giving it for
 * Receiving. A controller now names its section in $storeSection, and the check
 * passes on EITHER that section's permission or the flat one.
 *
 * The OR is what makes this safe to fit: anyone holding store.edit or
 * store.delete today keeps working exactly as before, and the section
 * permission only ever opens a second door. It mirrors the role_or_perm guards
 * on the routes, which admit a role or a permission for the same reason.
 *
 * A section permission that does not exist simply never matches — Spatie
 * returns false for an unknown ability rather than raising — so a controller
 * whose section has no .delete permission falls back to the flat one on its
 * own. store.setup.delete is deliberately one of those: setup has view and
 * edit only, and deleting a master row stays an Admin / Management matter.
 */
trait AuthorizesStoreCorrections
{
    /** Permission required to change a record that is already recorded. */
    protected const PERMISSION_EDIT = 'store.edit';

    /** Permission required to remove a record that is already recorded. */
    protected const PERMISSION_DELETE = 'store.delete';

    protected function authorizeStoreEdit(?string $what = null): void
    {
        $this->authorizeStoreCorrection(self::PERMISSION_EDIT, $what);
    }

    protected function authorizeStoreDelete(?string $what = null): void
    {
        $this->authorizeStoreCorrection(self::PERMISSION_DELETE, $what);
    }

    /**
     * The permissions that satisfy one correction action: this controller's
     * section-scoped one first, then the flat one that has always covered it.
     *
     * A controller that declares no $storeSection is checked on the flat
     * permission alone, exactly as before.
     *
     * @return list<string>
     */
    protected function correctionPermissions(string $flat): array
    {
        $action = $flat === self::PERMISSION_DELETE ? 'delete' : 'edit';
        $section = $this->storeSection ?? null;

        return array_values(array_filter([
            $section ? $section.'.'.$action : null,
            $flat,
        ]));
    }

    /**
     * $what names the record in the refusal, so a blocked user is told what they
     * cannot change rather than a bare "Forbidden".
     */
    protected function authorizeStoreCorrection(string $permission, ?string $what = null): void
    {
        $verb = $permission === self::PERMISSION_DELETE ? 'delete' : 'edit';
        $subject = $what ? ' a recorded '.$what : ' this record';

        abort_unless(
            auth()->user()?->canAny($this->correctionPermissions($permission)) ?? false,
            403,
            'You do not have permission to '.$verb.$subject.'. Corrections are handled by Admin or Management.',
        );
    }

    /**
     * Whether the current user may correct store records — for deciding whether
     * to render the buttons at all.
     *
     * Asks exactly the question authorizeStoreCorrection() asks, so a user who
     * holds only the section permission sees the button that will work for
     * them, and nobody is shown one that will refuse them.
     *
     * @return array{edit: bool, delete: bool}
     */
    protected function storeCorrectionAbilities(): array
    {
        $user = auth()->user();

        return [
            'edit' => $user?->canAny($this->correctionPermissions(self::PERMISSION_EDIT)) ?? false,
            'delete' => $user?->canAny($this->correctionPermissions(self::PERMISSION_DELETE)) ?? false,
        ];
    }
}
