<?php

namespace App\Support;

/**
 * Which departments buyer scoping applies to, and what the scope modes are.
 *
 * Buyer scoping was built for Merchandising and asked `departmentKey() ===
 * 'merchandising'` in six places. This turns that literal into one list, so a
 * department is switched on by adding a string here rather than by finding
 * every comparison and hoping none was missed.
 *
 * SCOPED_DEPARTMENTS IS A CONST, NOT A SETTING. It is deliberately not in
 * config and deliberately not an admin-editable field: it decides whether an
 * entire department's access is restricted at all, and a runtime toggle for
 * that is a checkbox that widens or narrows access for everyone in a
 * department with no code review, no diff and no test. Adding one here is a
 * commit, which is the point.
 *
 * A department NOT listed here is unscoped — every method that reads this
 * answers "unrestricted" for its users, which is exactly what Commercial,
 * Store, Accounts and Production get today.
 */
final class BuyerScope
{
    /**
     * Departments where buyer scoping is switched on.
     *
     * @var list<string>
     */
    public const SCOPED_DEPARTMENTS = [
        'merchandising',
    ];

    /** Every buyer — the unrestricted mode, and the default for a new user. */
    public const MODE_ALL = 'all';

    /** Confined to the buyers explicitly assigned to this user. */
    public const MODE_SPECIFIC = 'specific';

    /**
     * The two modes, for validation and for the form.
     *
     * @return list<string>
     */
    public static function modes(): array
    {
        return [self::MODE_ALL, self::MODE_SPECIFIC];
    }

    /** Whether buyer scoping applies to this department key at all. */
    public static function appliesTo(?string $departmentKey): bool
    {
        return $departmentKey !== null
            && in_array($departmentKey, self::SCOPED_DEPARTMENTS, true);
    }
}
