<?php

namespace App\Support;

/**
 * The "Portal Access" switches on the Admin > Users form.
 *
 * Each switch is a named bundle of existing permissions, so an admin can give
 * somebody one everyday ability from their own portal without opening the full
 * permission matrix. A switch never invents a permission: it writes the ones
 * listed here as direct grants on the user, on top of whatever their role
 * already carries.
 *
 * To add a switch, add one entry below. The form, the save and the matrix
 * sync all read this list, so nothing else changes.
 */
final class PortalAccess
{
    /**
     * @return array<string, array{label: string, description: string, icon: string, permissions: list<string>}>
     */
    public static function toggles(): array
    {
        return [
            'general_requisition' => [
                'label' => 'General Stock Requisition',
                'description' => 'Raise and follow their own Purchase Requisitions.',
                'icon' => 'bi-clipboard-check',
                'permissions' => ['store.requisition.view', 'store.requisition.create'],
            ],
        ];
    }
}
