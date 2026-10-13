<?php

namespace App\Support;

/**
 * Which permission-matrix modules matter for which department.
 *
 * DISPLAY ONLY. It decides which module cards the Users form opens with
 * ("Relevant" view) and nothing else: no permission is granted, hidden or
 * refused because of it. Every card stays in the page, still posts its ticks,
 * and is one click away under "All". A card where the user already holds a
 * permission (from the role or granted) is always shown, whatever this says.
 *
 * Keys are PermissionCatalog module keys. '*' means every module (Management /
 * Admin). A department not listed here, or no department chosen yet, shows all.
 */
final class DepartmentModules
{
    /** @var array<string, list<string>|string> */
    private const MAP = [
        'merchandising' => ['orders', 'materials', 'vendors', 'shipments', 'ocr', 'bom', 'booking'],
        'supply_chain' => ['orders', 'materials', 'vendors', 'shipments', 'booking', 'bom', 'material', 'pra'],
        'commercial' => ['orders', 'vendors', 'shipments', 'payments', 'reports'],
        'store' => ['store', 'material', 'materials', 'vendors', 'reports'],
        'accounts' => ['payments', 'reports', 'pra'],
        'production' => [],
        'management' => '*',
    ];

    /** @return array<string, list<string>|string> */
    public static function map(): array
    {
        return self::MAP;
    }
}
