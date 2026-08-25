<?php

namespace App\Console\Commands;

use App\Support\BuyerScope;
use App\Support\BuyerScopeModeBackfill;
use App\Support\DepartmentRoles;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Read-only audit of buyer scoping, to be run on PRODUCTION before another
 * department is switched on in BuyerScope::SCOPED_DEPARTMENTS.
 *
 * WHY THIS EXISTS. BuyerPivotBackfill copies users.buyer_id into the buyer_user
 * pivot for EVERY user, with no department filter — there is a test asserting
 * it does exactly that, deliberately, because the backfill's job is to copy the
 * column rather than to judge it. A stray buyer_id on a Supply Chain or Store
 * account is therefore already sitting in the pivot, inert ONLY because
 * User::isBuyerScopedDepartment() refuses to read it for those departments.
 *
 * Widen that list and every stray value becomes a live assignment. The
 * development database holds none, so it cannot answer the question; only
 * production can. This prints the answer.
 *
 * WRITES NOTHING. No flags, no --fix. What to do about a stray holder is a
 * decision for a person — clear the value, or assign the user deliberately —
 * and a command that silently picked one would be the exact class of change
 * this audit is meant to prevent.
 */
class AuditBuyerScope extends Command
{
    protected $signature = 'buyer:audit-scope {--department= : Report what switching THIS department on would confine, whether or not it is scoped yet}';

    protected $description = 'Report buyer assignments held by users outside the scoped departments (read-only)';

    public function handle(): int
    {
        if ($this->option('department') !== null) {
            return $this->auditDepartment((string) $this->option('department'));
        }

        $this->newLine();
        $this->info('Buyer scope audit');
        $this->line('Departments scoped today: ' . implode(', ', BuyerScope::SCOPED_DEPARTMENTS));
        $this->line('Roles the mode backfill treated as scoped: ' . implode(', ', BuyerScopeModeBackfill::ROLES_SCOPED_AT_WRITE_TIME));
        $this->newLine();

        $audit = new BuyerScopeModeBackfill();
        $stray = $audit->strayBuyerHolders();

        if ($stray === []) {
            $this->info('PASS - no user outside the scoped departments holds a buyer.');
            $this->line('Switching another department on will confine nobody by itself.');
        } else {
            $this->error('REVIEW NEEDED - ' . count($stray) . ' user(s) outside the scoped departments hold a buyer.');
            $this->line('Each would become confined to that buyer if their department is switched on.');
            $this->newLine();

            $this->table(
                ['ID', 'Name', 'Email', 'Role(s)', 'buyer_id', 'Pivot buyers', 'Mode'],
                collect($stray)->map(fn ($user) => [
                    $user->id,
                    $user->name,
                    $user->email,
                    $this->rolesFor($user->id) ?: '(none)',
                    $user->buyer_id ?? '-',
                    $this->pivotBuyersFor($user->id) ?: '-',
                    $user->buyer_scope_mode,
                ])->all()
            );

            $this->line('Resolve each one before deploying, by either:');
            $this->line('  - clearing the stray value (users.buyer_id and the buyer_user row), or');
            $this->line('  - assigning that user deliberately, so the confinement is intended.');
        }

        $this->newLine();
        $this->line('Mode distribution across all users:');
        $this->table(
            ['buyer_scope_mode', 'Users'],
            DB::table('users')
                ->select('buyer_scope_mode', DB::raw('count(*) as total'))
                ->groupBy('buyer_scope_mode')
                ->orderBy('buyer_scope_mode')
                ->get()
                ->map(fn ($row) => [$row->buyer_scope_mode ?? '(null)', $row->total])
                ->all()
        );

        // Always 0: this reports, it does not gate a pipeline. A non-zero exit
        // would make it a check that someone eventually passes a flag to skip.
        return self::SUCCESS;
    }

    /**
     * --department=<key>: what confinement would look like for ONE department.
     *
     * The default audit answers "is anybody, anywhere, holding a buyer their
     * department cannot use". This answers the narrower question a switch-on
     * decision actually turns on: if BuyerScope listed THIS department
     * tomorrow, who becomes confined, and to what?
     *
     * It reads the department's roles from DepartmentRoles rather than from
     * BuyerScope, so it works for a department that is not scoped yet — which
     * is the only time the question is worth asking. Run it BEFORE editing the
     * const, and again on production before deploying that edit.
     *
     * WRITES NOTHING, like the default audit. Exit code is always 0: this
     * informs a person, it does not gate a pipeline.
     */
    private function auditDepartment(string $departmentKey): int
    {
        $departments = DepartmentRoles::departments();

        if (! array_key_exists($departmentKey, $departments)) {
            $this->error('Unknown department "'.$departmentKey.'".');
            $this->line('Known departments: '.implode(', ', array_keys($departments)));

            return self::SUCCESS;
        }

        $label = $departments[$departmentKey];
        $roles = DepartmentRoles::rolesFor($departmentKey);
        $isScoped = BuyerScope::appliesTo($departmentKey);

        $this->newLine();
        $this->info('Buyer scope audit - '.$label);
        $this->line('Department key: '.$departmentKey);
        $this->line('Roles in this department: '.(implode(', ', $roles) ?: '(none)'));
        $this->line($isScoped
            ? 'Status: ALREADY SCOPED - the holders below are confined right now.'
            : 'Status: NOT SCOPED - the holders below would become confined if it were listed.');
        $this->newLine();

        $holders = (new BuyerScopeModeBackfill())->buyerHoldersInDepartment($departmentKey);

        if ($holders === []) {
            $this->info('PASS - no user in this department holds a buyer.');
            $this->line($isScoped
                ? 'Nobody in it is confined today.'
                : 'Adding it to BuyerScope::SCOPED_DEPARTMENTS would confine nobody by itself.');

            return self::SUCCESS;
        }

        $this->warn(count($holders).' user(s) in this department hold a buyer.');
        $this->newLine();

        $this->table(
            ['ID', 'Name', 'Email', 'Role(s)', 'Dept admin', 'buyer_id', 'Pivot buyers', 'Owns buyers', 'Mode'],
            collect($holders)->map(fn ($user) => [
                $user->id,
                $user->name,
                $user->email,
                $this->rolesFor($user->id) ?: '(none)',
                $user->is_department_admin ? 'yes' : '-',
                $user->buyer_id ?? '-',
                $this->pivotBuyersFor($user->id) ?: '-',
                $this->ownedBuyersFor($user->id) ?: '-',
                $user->buyer_scope_mode,
            ])->all()
        );

        if (! $isScoped) {
            $this->line('Before switching this department on, decide for each:');
            $this->line('  - the assignment is intended, so the confinement is wanted, or');
            $this->line('  - the value is stray and should be cleared first.');
            $this->newLine();
            $this->line('A department admin listed under "Owns buyers" is confined by what they');
            $this->line('own on the Buyers screen, which no buyer_scope_mode value overrides.');
        }

        return self::SUCCESS;
    }

    private function ownedBuyersFor(int $userId): string
    {
        return DB::table('buyers')
            ->where('department_admin_id', $userId)
            ->pluck('buyer_name')
            ->implode(', ');
    }

    private function rolesFor(int $userId): string
    {
        return DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_id', $userId)
            ->pluck('roles.name')
            ->implode(', ');
    }

    private function pivotBuyersFor(int $userId): string
    {
        return DB::table('buyer_user')
            ->join('buyers', 'buyers.id', '=', 'buyer_user.buyer_id')
            ->where('buyer_user.user_id', $userId)
            ->pluck('buyers.buyer_name')
            ->implode(', ');
    }
}
