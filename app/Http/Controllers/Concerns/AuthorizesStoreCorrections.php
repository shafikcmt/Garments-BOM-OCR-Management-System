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
     * $what names the record in the refusal, so a blocked user is told what they
     * cannot change rather than a bare "Forbidden".
     */
    protected function authorizeStoreCorrection(string $permission, ?string $what = null): void
    {
        $verb = $permission === self::PERMISSION_DELETE ? 'delete' : 'edit';
        $subject = $what ? ' a recorded '.$what : ' this record';

        abort_unless(
            auth()->user()?->can($permission) ?? false,
            403,
            'You do not have permission to '.$verb.$subject.'. Corrections are handled by Admin or Management.',
        );
    }

    /**
     * Whether the current user may correct store records — for deciding whether
     * to render the buttons at all.
     *
     * @return array{edit: bool, delete: bool}
     */
    protected function storeCorrectionAbilities(): array
    {
        $user = auth()->user();

        return [
            'edit' => $user?->can(self::PERMISSION_EDIT) ?? false,
            'delete' => $user?->can(self::PERMISSION_DELETE) ?? false,
        ];
    }
}
