<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Departments become data.
 *
 * Until now the department list lived in DepartmentRoles::MAP — a PHP
 * constant — and a role the map had never heard of (cutting) belonged
 * nowhere: no department on the user form, no head to route a requisition
 * to, "ALL" on its number. Adding HARC or Maintenance meant a deploy.
 *
 * Roles stay the source of truth for WHO a person is (Spatie, untouched).
 * A department is a NAMED GROUP OF ROLES with a head, and that is all these
 * two tables hold:
 *
 *   departments        key, label, head (users.id), active, order
 *   department_roles   which roles belong to which department; is_primary
 *                      marks the department's broad "admin" role — the
 *                      first role DepartmentRoles::MAP always listed
 *
 * DepartmentRoles reads these from now on, with the same public API, so
 * the user form, the dashboards, the sidebar, the buyer scope and the PRA
 * resolver need no change. The constant stays in the class as the seed
 * for this migration and as the fallback while a deploy is mid-way.
 *
 * SEED: the seven departments exactly as the map had them, plus
 *   production  gains the `cutting` role (its one user is Cutting staff)
 *   harc        new, role `harc`
 *   maintenance new, role `maintenance`
 * The two new roles themselves are seeded by 2026_09_23_000004.
 *
 * HEADS: requisition_department_heads (role_name -> user) was the first
 * home of "who heads a department". Its rows are copied here by mapping
 * the role to its department; the table itself is left in place, unread.
 *
 * Additive throughout. Nothing is renamed or removed.
 */
return new class extends Migration
{
    /** @var array<string, array{label: string, roles: list<string>}> */
    private const SEED = [
        'merchandising' => ['label' => 'Merchandising', 'roles' => ['merchant']],
        'supply_chain' => ['label' => 'Supply Chain', 'roles' => ['supply_chain']],
        'commercial' => ['label' => 'Commercial', 'roles' => ['commercial']],
        'store' => ['label' => 'Store', 'roles' => ['store', 'store_general_stock', 'store_material_stock']],
        'accounts' => ['label' => 'Accounts', 'roles' => ['account']],
        'production' => ['label' => 'Production', 'roles' => ['production', 'cutting']],
        'harc' => ['label' => 'HARC', 'roles' => ['harc']],
        'maintenance' => ['label' => 'Maintenance', 'roles' => ['maintenance']],
        'management' => ['label' => 'Management / Admin', 'roles' => ['admin', 'management']],
    ];

    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique();
            $table->string('label', 80);
            // The department head. nullOnDelete: a removed account leaves
            // the department headless rather than deleting it.
            $table->foreignId('head_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('department_roles', function (Blueprint $table) {
            $table->id();
            $table->string('department_key', 40)->index();
            $table->string('role_name', 80);
            $table->boolean('is_primary')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            // A role belongs to ONE department — otherwise departmentOf()
            // would have two answers.
            $table->unique('role_name');
        });

        $this->seed();
        $this->copyHeads();
    }

    public function down(): void
    {
        Schema::dropIfExists('department_roles');
        Schema::dropIfExists('departments');
    }

    private function seed(): void
    {
        if (DB::table('departments')->exists()) {
            return;
        }

        $now = now();
        $order = 0;

        foreach (self::SEED as $key => $department) {
            DB::table('departments')->insert([
                'key' => $key,
                'label' => $department['label'],
                'is_active' => true,
                'sort_order' => ++$order * 10,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach ($department['roles'] as $i => $role) {
                DB::table('department_roles')->insert([
                    'department_key' => $key,
                    'role_name' => $role,
                    'is_primary' => $i === 0,
                    'sort_order' => ($i + 1) * 10,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    /** requisition_department_heads.role_name -> departments.head_user_id. */
    private function copyHeads(): void
    {
        if (! Schema::hasTable('requisition_department_heads')) {
            return;
        }

        foreach (DB::table('requisition_department_heads')->get() as $row) {
            $key = DB::table('department_roles')->where('role_name', $row->role_name)->value('department_key');

            if ($key) {
                DB::table('departments')->where('key', $key)->whereNull('head_user_id')
                    ->update(['head_user_id' => $row->user_id, 'updated_at' => now()]);
            }
        }
    }
};
