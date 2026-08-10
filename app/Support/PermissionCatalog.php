<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;

/**
 * Turns the flat list of permission names into the module / section / action
 * shape the admin permission matrix is drawn from.
 *
 * The names already carry the structure — "store.view", "orders.create",
 * "store.requisition.approve" — so the grouping is read out of them rather than
 * kept in a second list that would drift. A permission added by a later
 * migration appears in the matrix on its own, with no change here.
 *
 * Deliberately no database columns: the alternative was adding module/section/
 * action to Spatie's permissions table and backfilling 42 rows, which is a
 * schema change and a data migration to express something the names already
 * say.
 */
class PermissionCatalog
{
    /**
     * Module labels in the words the business uses. Anything not listed falls
     * back to a headline of its own key, so nothing is ever hidden from the
     * matrix just because it is new.
     */
    private const MODULE_LABELS = [
        'store' => 'General Stock',
        'material' => 'Buyer / Style Stock',
        'orders' => 'Orders / BOM',
        'materials' => 'Materials',
        'vendors' => 'Vendors / Suppliers',
        'shipments' => 'Shipment',
        'payments' => 'Accounts / Payments',
        'ocr' => 'OCR Workspace',
        'users' => 'User Management',
        'roles' => 'Roles & Permissions',
        'reports' => 'Store Reports',
        'audit' => 'Audit Log',
    ];

    /**
     * Module order, matching the sidebar rather than the alphabet — the two
     * stock modules first, because they are the ones with sub-sections and the
     * ones an admin comes to this screen for. Anything unlisted sorts after.
     */
    private const MODULE_ORDER = [
        'store', 'material', 'orders', 'materials', 'vendors', 'shipments',
        'payments', 'reports', 'ocr', 'users', 'roles', 'audit',
    ];

    /**
     * Sub-section labels and order, taken from the sidebar so the matrix reads
     * in the same sequence as the menu the user actually clicks.
     *
     * @see resources/views/layouts/sidebar.blade.php
     */
    private const SECTION_LABELS = [
        // General Stock
        'stock_report' => 'Stock Report',
        'items' => 'Items',
        'receiving' => 'Receiving',
        'issues' => 'Issues',
        'requisition' => 'Purchase Requisition',
        'setup' => 'Setup',

        // Buyer / Style Stock
        'closing_stock' => 'Closing Stock',
        'bulk_issue' => 'Bulk Issuing',
        'requisitions' => 'Requisitions',
    ];

    /** Per-module sub-section order. Sections not listed follow, alphabetically. */
    private const SECTION_ORDER = [
        'store' => ['stock_report', 'items', 'receiving', 'issues', 'requisition', 'setup'],
        'material' => ['closing_stock', 'receiving', 'bulk_issue', 'requisitions'],
    ];

    /**
     * The row holding a module's older whole-module permissions — store.view,
     * store.edit and the rest, which predate the sub-sections and are still
     * what the roles are built from. Kept visible rather than hidden: they are
     * real grants, and an admin looking for why somebody has access needs to
     * see them.
     */
    private const MODULE_WIDE_LABEL = 'Whole module';

    /**
     * Action labels. The five the matrix is built around come first; the rest
     * are the verbs this project already uses and are shown as they are.
     */
    private const ACTION_LABELS = [
        'view' => 'View',
        'create' => 'Create / Entry',
        'edit' => 'Edit',
        'delete' => 'Delete',
        'export' => 'Export',
        'approve' => 'Approve',
        'manage' => 'Manage',
        'issue' => 'Issue',
        'return' => 'Return',
        'adjust' => 'Adjust',
        'review' => 'Review',
        'accounts' => 'Accounts Stage',
    ];

    /**
     * Column order for the matrix. Modules are drawn as rows, these as columns,
     * and a module simply has no checkbox where the permission does not exist.
     */
    public const ACTION_ORDER = ['view', 'create', 'edit', 'delete', 'export', 'approve', 'manage', 'issue', 'return', 'adjust', 'review', 'accounts'];

    /** Permissions that are one-off switches rather than a module action. */
    private const SPECIAL_MODULE = 'Special Approvals';

    /**
     * Every permission, grouped for display.
     *
     * @return Collection<string, array{label: string, rows: array<int, array{
     *     section: string|null, actions: array<string, array{id: int, name: string, label: string}>
     * }>}>
     */
    public function grouped(): Collection
    {
        return Permission::orderBy('name')->get()
            ->groupBy(fn (Permission $p) => $this->moduleKeyOf($p->name))
            ->map(function (Collection $permissions, string $moduleKey) {
                $rows = $permissions
                    ->groupBy(fn (Permission $p) => $this->sectionKeyOf($p->name) ?? '')
                    ->map(function (Collection $group, string $section) {
                        $entries = $group->mapWithKeys(fn (Permission $p) => [
                            $this->actionKeyOf($p->name) => [
                                'id' => $p->id,
                                'name' => $p->name,
                                'label' => $this->actionLabel($this->actionKeyOf($p->name)),
                            ],
                        ]);

                        return [
                            // Raw key for ordering; label for display.
                            'key' => $section === '' ? null : $section,
                            'section' => $section === '' ? self::MODULE_WIDE_LABEL : $this->sectionLabel($section),
                            // Actions that have a column of their own...
                            'actions' => $entries
                                ->filter(fn ($e, $action) => in_array($action, self::ACTION_ORDER, true))
                                ->all(),
                            // ...and the ones that do not. A permission like
                            // ocr.update_owner_field or approve-pra is a switch
                            // rather than one of the five standard verbs, so it
                            // is drawn as its own labelled checkbox instead of
                            // being silently dropped for want of a column.
                            'extra' => $entries
                                ->reject(fn ($e, $action) => in_array($action, self::ACTION_ORDER, true))
                                ->all(),
                        ];
                    })
                    ->values()
                    ->all();

                // Sidebar order, so General Stock reads Stock Report, Items,
                // Receiving, Issues, Purchase Requisition, Setup — the sequence
                // of the menu itself. Module-wide sits first as the heading row
                // for the module's older flat permissions.
                $order = self::SECTION_ORDER[$moduleKey] ?? [];

                usort($rows, function (array $a, array $b) use ($order) {
                    $rank = function (?string $section) use ($order) {
                        if ($section === null) {
                            return -1;
                        }

                        $i = array_search($section, $order, true);

                        return $i === false ? PHP_INT_MAX : $i;
                    };

                    return [$rank($a['key']), $a['section'] ?? ''] <=> [$rank($b['key']), $b['section'] ?? ''];
                });

                // The action columns THIS module actually uses. Drawing the
                // global set instead meant Shipment, which has four actions,
                // rendered twelve columns and eight dashes — the clutter that
                // pushed the grid into horizontal scrolling.
                $used = [];

                foreach ($rows as $row) {
                    $used = array_merge($used, array_keys($row['actions']));
                }

                $used = array_values(array_filter(
                    self::ACTION_ORDER,
                    fn ($action) => in_array($action, $used, true)
                ));

                return [
                    'key' => $moduleKey,
                    'label' => $this->moduleLabel($moduleKey),
                    'rows' => $rows,
                    'columns' => $used,
                    // A module with one row has no sub-sections worth showing:
                    // its name is the row. Anything else gets section rows.
                    'sectioned' => count($rows) > 1,
                ];
            })
            ->sortBy(function ($module, $key) {
                $i = array_search($key, self::MODULE_ORDER, true);

                // Unlisted modules follow in name order; the one-off switches last.
                return $key === self::SPECIAL_MODULE
                    ? 'z2'
                    : ($i === false ? 'z1'.$module['label'] : sprintf('a%02d', $i));
            });
    }

    /**
     * The action columns actually in use, so the matrix does not draw ten empty
     * columns on an install that only uses four.
     *
     * @return array<int, string>
     */
    public function actionColumns(): array
    {
        $used = Permission::pluck('name')
            ->map(fn (string $name) => $this->actionKeyOf($name))
            ->unique()
            ->all();

        return array_values(array_filter(self::ACTION_ORDER, fn ($a) => in_array($a, $used, true)));
    }

    public function actionLabel(string $action): string
    {
        return self::ACTION_LABELS[$action] ?? Str::headline($action);
    }

    /**
     * Module key of a permission name.
     *
     * "store.view" and "store.requisition.approve" are both the store module;
     * "approve-pra", which has no dot at all, is a one-off switch.
     */
    private function moduleKeyOf(string $name): string
    {
        if (! str_contains($name, '.')) {
            return self::SPECIAL_MODULE;
        }

        return Str::before($name, '.');
    }

    /** Middle part of a three-part name, or null for a plain module.action. */
    private function sectionKeyOf(string $name): ?string
    {
        $parts = explode('.', $name);

        return count($parts) >= 3 ? $parts[1] : null;
    }

    /**
     * Action of a permission name — the last dotted part. A name with no dot is
     * its own action, which is what puts each special switch on its own row.
     */
    private function actionKeyOf(string $name): string
    {
        return str_contains($name, '.') ? Str::afterLast($name, '.') : $name;
    }

    private function moduleLabel(string $key): string
    {
        return $key === self::SPECIAL_MODULE
            ? self::SPECIAL_MODULE
            : (self::MODULE_LABELS[$key] ?? Str::headline($key));
    }

    private function sectionLabel(string $key): string
    {
        return self::SECTION_LABELS[$key] ?? Str::headline($key);
    }
}
