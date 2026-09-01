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
     * WHAT ADDING A NAME HERE DOES, because it is not a display setting: every
     * user in that department whose buyer_scope_mode is 'specific', OR who
     * holds any buyer assignment, becomes CONFINED from the next request.
     * Confinement narrows what they may edit (ExcelFile::isBuyerLockedForUser)
     * and what their department's report shows them. A stray buyer_id left on
     * an account becomes a live restriction the moment its department is
     * listed — run `php artisan buyer:audit-scope` on production before adding
     * one, which is exactly what that command exists to answer.
     *
     * Admin and Management are never affected: the super admin is excluded by
     * name in User::isBuyerScopedDepartment(), and Management's department key
     * is not listed here.
     *
     * supply_chain was added once its report carried the same scope rule
     * Merchandising's does, and the audit came back clean.
     *
     * commercial and accounts followed, on the same two conditions: their
     * screens read through the scope, and `buyer:audit-scope --department=<key>`
     * came back PASS for each. Neither confines anybody by itself — every
     * existing user in both is on buyer_scope_mode 'all' and holds no buyer, so
     * nothing changed for anyone until an admin ticks a buyer on purpose.
     *
     * THE KEY IS 'accounts', NOT 'account'. 'account' is the ROLE name; the
     * department key is plural, per DepartmentRoles::MAP. appliesTo() is asked
     * about a department key, so the singular would silently match nothing and
     * look like scoping that quietly does not work.
     *
     * store is listed, but read UNSCOPED_ROLES below before assuming that means
     * all of it — Store is the first department where the answer is not the
     * same for every role in it.
     *
     * @var list<string>
     */
    public const SCOPED_DEPARTMENTS = [
        'merchandising',
        'supply_chain',
        'commercial',
        'accounts',
        'store',
    ];

    /**
     * Roles carved OUT of an otherwise scoped department.
     *
     * WHY THIS EXISTS AT ALL. Every other department answers the buyer question
     * the same way for all of its roles, so a department key was enough. Store
     * does not: it holds two different businesses behind one department key.
     *
     *   Buyer / Style stock   material_receivings, material_bulk_issues,
     *                         material_requisitions, material_stock_ledgers —
     *                         every one carries excel_file_id, so every one can
     *                         be traced to a buyer. Scoping applies.
     *   General Stock         stock_items, stock_purchases, stock_issues —
     *                         NOT ONE has an excel_file_id or a buyer column.
     *                         There is no buyer to scope on. Consumables bought
     *                         for the factory belong to no order.
     *
     * Listing 'store' without this carve-out would make a General Stock user
     * isBuyerConfined() the moment somebody assigned them a buyer: a Buyer
     * Access control on their account that changes nothing on any screen they
     * use, plus a BOM edit-lock they did not have before. A control that does
     * nothing is worse than no control.
     *
     * store_material_stock stays scoped, and so does plain 'store' — the
     * department admin, who works across both sides and must be confinable on
     * the side that has buyers.
     *
     * A ROLE HERE WINS OVER ITS DEPARTMENT. Ask appliesToRole(), not
     * appliesTo(), anywhere a role is available; appliesTo() cannot see this
     * list and will answer for the department as a whole.
     *
     * @var list<string>
     */
    public const UNSCOPED_ROLES = [
        'store_general_stock',
    ];

    /**
     * Roles buyer scoping NEVER applies to, whatever their department says.
     *
     * UNSCOPED_ROLES above answers a department-shaped question: Store is
     * scoped, but one role inside it has no buyer column to scope on. This list
     * answers a different one - roles that must stay unscoped no matter how
     * SCOPED_DEPARTMENTS grows later, because confining them would be wrong on
     * its face rather than merely unimplemented:
     *
     *   management  Oversight. Management reads the whole floor by definition;
     *               a Management user confined to two buyers is a reporting
     *               screen that silently under-reports to the people who decide
     *               on it. (admin is already excluded by name in
     *               User::isBuyerScopedDepartment(), so it is not repeated.)
     *   production  Sews what is cut, for every order on the line. Its screens
     *               carry no buyer filter, so a buyer on a production account
     *               would be a control that restricts nothing visible while
     *               still locking BOM edits.
     *   store_general_stock  Consumables belong to no order - see UNSCOPED_ROLES.
     *
     * BEHAVIOUR-NEUTRAL THE DAY IT LANDED, deliberately: 'management' and
     * 'production' are not in SCOPED_DEPARTMENTS, so appliesToRole() already
     * answered false for both, and store_general_stock was already carved out.
     * It is a standing guarantee for whoever adds the next department, not a
     * change of anybody's access.
     *
     * store_general_stock appears in BOTH lists on purpose. UNSCOPED_ROLES is
     * also handed to the Users form so the Buyer Access block can start closed
     * for it; this list is the access rule. Dropping it from either one would
     * quietly change something.
     *
     * @var list<string>
     */
    public const NEVER_SCOPED_ROLES = [
        'management',
        'production',
        'store_general_stock',
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

    /**
     * Whether buyer scoping applies to this department key at all.
     *
     * DEPARTMENT-WIDE, and therefore blind to UNSCOPED_ROLES. It answers true
     * for 'store' even though a General Stock user in it is not scoped. Use it
     * only where a role genuinely is not available — the Users form's
     * department dropdown, which decides whether the Buyer Access block is
     * worth showing at all before a role is picked. Everywhere a role IS
     * available, ask appliesToRole().
     */
    public static function appliesTo(?string $departmentKey): bool
    {
        return $departmentKey !== null
            && in_array($departmentKey, self::SCOPED_DEPARTMENTS, true);
    }

    /**
     * Whether buyer scoping applies to a specific ROLE. The precise question,
     * and the one every access decision should ask.
     *
     * Three gates, in this order and not another: the never-scoped roles, then
     * the role's own department carve-out, then the department allowlist. Both
     * role lists are checked BEFORE the department, so a scoped department can
     * never re-admit a role that was deliberately excluded from it.
     */
    public static function appliesToRole(?string $role): bool
    {
        if ($role === null
            || in_array($role, self::NEVER_SCOPED_ROLES, true)
            || in_array($role, self::UNSCOPED_ROLES, true)) {
            return false;
        }

        return self::appliesTo(DepartmentRoles::departmentOf($role));
    }
}
