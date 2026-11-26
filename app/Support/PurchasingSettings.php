<?php

namespace App\Support;

use App\Models\AppSetting;
use App\Models\User;

/**
 * Admin-managed settings for General Stock purchasing: what a NEW Internal PO
 * is printed with, the titles of its two signature boxes, and who may give each
 * purchase approval.
 *
 * The built-in values live in config/purchasing.php and are the fallback, so
 * nothing changes until somebody saves a value here. A PO keeps the values it
 * was created with: editing the defaults never rewrites an existing PO.
 */
final class PurchasingSettings
{
    public const KEY_PO_DEFAULTS = 'gs_po_defaults';

    public const KEY_PO_SIGN = 'gs_po_sign_labels';

    /**
     * The fields of the PO format. `admin_only` fields are shown read-only to a
     * team head (the company BIN is a legal identifier).
     *
     * @return array<string, array{label: string, rows: int, hint: string, admin_only?: bool}>
     */
    public static function poFields(): array
    {
        return [
            'invoice_to' => ['label' => 'Bill to / company block', 'rows' => 3, 'hint' => 'Printed under the company name at the top of the PO.'],
            'consignee' => ['label' => 'Consignee (ship to)', 'rows' => 2, 'hint' => 'Where the goods are delivered.'],
            'destination' => ['label' => 'Destination', 'rows' => 1, 'hint' => 'Short place name.'],
            'dispatched_through' => ['label' => 'Dispatched through', 'rows' => 1, 'hint' => 'For example Vendor Transport.'],
            'payment_terms' => ['label' => 'Mode / terms of payment', 'rows' => 2, 'hint' => 'Used when the supplier has no terms of its own.'],
            'delivery_terms' => ['label' => 'Terms of delivery', 'rows' => 3, 'hint' => 'One line per term.'],
            'remarks' => ['label' => 'Remarks', 'rows' => 3, 'hint' => 'One line per remark; printed as a numbered list.'],
            'due_on' => ['label' => 'Due on (each line)', 'rows' => 1, 'hint' => 'For example ASAP.'],
            'bin_number' => ['label' => 'Company BIN', 'rows' => 1, 'hint' => 'Printed on every PO. Only Admin can change it.', 'admin_only' => true],
        ];
    }

    /** Saved value if there is one (even blank), else the built-in default. @return array<string, string|null> */
    public static function poDefaults(): array
    {
        $saved = json_decode((string) AppSetting::get(self::KEY_PO_DEFAULTS, ''), true);
        $saved = is_array($saved) ? $saved : [];
        $defaults = (array) config('purchasing.po_defaults');

        $merged = [];
        foreach (array_keys(self::poFields()) as $key) {
            $merged[$key] = array_key_exists($key, $saved) ? $saved[$key] : ($defaults[$key] ?? null);
        }

        return $merged;
    }

    /** @param array<string, mixed> $values */
    public static function savePoDefaults(array $values, bool $isAdmin): void
    {
        $current = self::poDefaults();

        foreach (self::poFields() as $key => $field) {
            if (! array_key_exists($key, $values)) {
                continue;
            }
            if (($field['admin_only'] ?? false) && ! $isAdmin) {
                continue;
            }
            $current[$key] = trim((string) $values[$key]);
        }

        AppSetting::put(self::KEY_PO_DEFAULTS, json_encode($current));
    }

    /** @return array{checked: string, authorised: string} */
    public static function poSignLabels(): array
    {
        $saved = json_decode((string) AppSetting::get(self::KEY_PO_SIGN, ''), true);
        $saved = is_array($saved) ? $saved : [];

        return [
            'checked' => trim((string) ($saved['checked'] ?? '')) ?: 'Checked By',
            'authorised' => trim((string) ($saved['authorised'] ?? '')) ?: 'Authorised Signatory',
        ];
    }

    public static function savePoSignLabels(?string $checked, ?string $authorised): void
    {
        AppSetting::put(self::KEY_PO_SIGN, json_encode([
            'checked' => trim((string) $checked),
            'authorised' => trim((string) $authorised),
        ]));
    }

    /** Who may open and change these settings: Admin, or the head of a department. */
    public static function mayManage(?User $user): bool
    {
        return $user !== null && ($user->hasRole('admin') || $user->isDepartmentAdmin());
    }

    /**
     * The approval points of purchasing, each backed by a Portal Access switch,
     * so assigning someone here and ticking the switch on their user form are the
     * same grant.
     *
     * @return array<string, array{label: string, description: string, permissions: list<string>}>
     */
    public static function approvalPoints(): array
    {
        $toggles = PortalAccess::toggles();

        return collect(['purchase_cs_check', 'purchase_cs_approve', 'purchase_po_check', 'purchase_po_authorise'])
            ->mapWithKeys(fn ($key) => [$key => [
                'label' => $toggles[$key]['label'],
                'description' => $toggles[$key]['description'],
                'permissions' => $toggles[$key]['permissions'],
            ]])->all();
    }
}
