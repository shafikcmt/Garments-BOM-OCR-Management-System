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
            // General Stock purchasing sign-offs. Given per person, never by the
            // Supply Chain General role itself, so nobody approves their own work.
            'purchase_cs_check' => [
                'label' => 'Comparative Statement: Check',
                'description' => 'Check a Comparative Statement (the Manager - SCM box).',
                'icon' => 'bi-check2-square',
                'permissions' => ['store.purchase_cs.view', 'store.purchase_cs.review'],
            ],
            'purchase_cs_approve' => [
                'label' => 'Comparative Statement: Approve',
                'description' => 'Give the final approval on a Comparative Statement.',
                'icon' => 'bi-check2-circle',
                'permissions' => ['store.purchase_cs.view', 'store.purchase_cs.approve', 'store.purchase_po.view'],
            ],
            'purchase_po_authorise' => [
                'label' => 'Internal PO: Authorise',
                'description' => 'Authorise a purchase order so Store can receive against it.',
                'icon' => 'bi-patch-check',
                'permissions' => ['store.purchase_po.view', 'store.purchase_po.approve'],
            ],
        ];
    }
}
