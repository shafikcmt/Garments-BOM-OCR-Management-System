<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\DepartmentRole;
use App\Models\IndentSection;
use App\Models\User;
use App\Support\DepartmentRoles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

/**
 * Admin > Settings > Departments.
 *
 * One screen for the company's structure as the requisition workflow sees
 * it: each department, the roles that belong to it, who heads it, and the
 * sections under it with their own heads. A department's requisition goes
 * to the nearest of those heads before the Store (RequisitionReviewChain),
 * and the user form offers a department's roles from the same list, so
 * this is the one place either is edited.
 *
 * Sections are indent_sections rows — the Store's Issue Setup master — so
 * a section added here is at once available on the issue register, and
 * "removing" one from a department only unfiles it: the row stays, as
 * every issue that names it must keep doing so.
 *
 * Roles themselves are Spatie's and are not created or renamed here; only
 * which department each belongs to. admin and management stay under
 * Management / Admin and are never offered to another department.
 */
class DepartmentController extends Controller
{
    /** Roles that stay where they are: moving them would hand oversight to a department. */
    private const PINNED_ROLES = ['admin', 'management'];

    public function index()
    {
        $departments = Department::query()->ordered()
            ->with(['head', 'roleLinks', 'sections.head'])
            ->get();

        return view('admin.departments.index', [
            'departments' => $departments,
            'users' => User::query()->where('status', 1)->orderBy('name')->get(['id', 'name', 'designation']),
            'roles' => $this->assignableRoles(),
            // role => department key, so the form can say where a role is now.
            'roleHomes' => DepartmentRole::query()->pluck('department_key', 'role_name')->all(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:80'],
            'head_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'roles' => ['nullable', 'array'],
            'roles.*' => ['string', Rule::in(array_keys($this->assignableRoles()))],
        ], [
            'label.required' => 'Give the department a name.',
        ]);

        $key = $this->uniqueKey($data['label']);

        DB::transaction(function () use ($data, $key) {
            $department = Department::create([
                'key' => $key,
                'label' => trim($data['label']),
                'head_user_id' => $data['head_user_id'] ?? null,
                'is_active' => true,
                'sort_order' => ((int) Department::query()->max('sort_order')) + 10,
            ]);

            $this->syncRoles($department, $data['roles'] ?? []);
        });

        DepartmentRoles::refresh();

        return redirect()->route('admin.departments.index')
            ->with('success', 'Department "'.trim($data['label']).'" added.');
    }

    public function update(Request $request, Department $department)
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:80'],
            'head_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'roles' => ['nullable', 'array'],
            'roles.*' => ['string', Rule::in(array_keys($this->assignableRoles()))],
            'sections' => ['nullable', 'array'],
            'sections.*.id' => ['required', 'integer'],
            'sections.*.name' => ['required', 'string', 'max:255'],
            'sections.*.head_user_id' => ['nullable', 'integer', 'exists:users,id'],
        ], [
            'label.required' => 'Give the department a name.',
            'sections.*.name.required' => 'Every section needs a name.',
        ]);

        DB::transaction(function () use ($data, $department) {
            $department->update([
                'label' => trim($data['label']),
                'head_user_id' => $data['head_user_id'] ?? null,
                // Management / Admin cannot be switched off: its people raise
                // the Store's own sheet and the user form must always offer it.
                'is_active' => $department->key === 'management' || (bool) ($data['is_active'] ?? false),
                'sort_order' => $data['sort_order'] ?? $department->sort_order,
            ]);

            if ($department->key !== 'management') {
                $this->syncRoles($department, $data['roles'] ?? []);
            }

            // Only the sections already filed under this department are on the
            // form; each is renamed / re-headed in place, in the order shown.
            $own = $department->sections()->pluck('id')->all();

            foreach (array_values($data['sections'] ?? []) as $i => $row) {
                $id = (int) ($row['id'] ?? 0);

                if (! in_array($id, $own, true)) {
                    continue;
                }

                IndentSection::query()->whereKey($id)->update([
                    'name' => trim($row['name']),
                    'head_user_id' => $row['head_user_id'] ?? null,
                    'sort_order' => ($i + 1) * 10,
                    'updated_at' => now(),
                ]);
            }
        });

        DepartmentRoles::refresh();

        return redirect()->route('admin.departments.index')
            ->with('success', $department->label.' saved.');
    }

    /** A new section under a department — a new Issue Setup row, filed here. */
    public function storeSection(Request $request, Department $department)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'head_user_id' => ['nullable', 'integer', 'exists:users,id'],
        ], [
            'name.required' => 'Give the section a name.',
        ]);

        $name = trim($data['name']);

        // The master may already carry this name as a plain issue-register
        // section (or under another department). File that row rather than
        // creating a twin — the register would otherwise offer the name twice.
        $existing = IndentSection::query()->whereLike('name', $name)->first();

        if ($existing && $existing->department_key && $existing->department_key !== $department->key) {
            return back()->withInput()->withErrors([
                'name' => '"'.$existing->name.'" is already a section of '.($existing->department?->label ?? 'another department').'.',
            ]);
        }

        $section = $existing ?: new IndentSection(['name' => $name, 'is_active' => true, 'created_by' => auth()->id()]);
        $section->department_key = $department->key;
        $section->head_user_id = $data['head_user_id'] ?? null;
        $section->sort_order = ((int) $department->sections()->max('sort_order')) + 10;
        $section->is_active = true;
        $section->save();

        return redirect()->route('admin.departments.index')
            ->with('success', 'Section "'.$section->name.'" added under '.$department->label.'.');
    }

    /**
     * Take a section out of its department. The Issue Setup row itself is
     * kept — issues and requisitions point at it — it simply belongs to
     * nobody again, and its head is cleared with it.
     */
    public function detachSection(IndentSection $section)
    {
        $label = $section->department?->label;

        $section->update(['department_key' => null, 'head_user_id' => null, 'sort_order' => 0]);

        return redirect()->route('admin.departments.index')
            ->with('success', 'Section "'.$section->name.'" removed from '.($label ?: 'its department').'. It is still available on the issue register.');
    }

    /**
     * Give a department exactly these roles, in this order, the first as
     * primary. A role taken from another department leaves it — a role
     * belongs to one department.
     *
     * @param  list<string>  $roles
     */
    private function syncRoles(Department $department, array $roles): void
    {
        $roles = array_values(array_unique(array_diff($roles, self::PINNED_ROLES)));

        DepartmentRole::query()->where('department_key', $department->key)->delete();
        DepartmentRole::query()->whereIn('role_name', $roles)->delete();

        foreach ($roles as $i => $role) {
            DepartmentRole::create([
                'department_key' => $department->key,
                'role_name' => $role,
                'is_primary' => $i === 0,
                'sort_order' => ($i + 1) * 10,
            ]);
        }
    }

    /** @return array<string, string> role name => label, the roles a department may be given */
    private function assignableRoles(): array
    {
        return Role::query()->where('guard_name', 'web')
            ->whereNotIn('name', self::PINNED_ROLES)
            ->orderBy('name')
            ->pluck('name')
            ->mapWithKeys(fn ($name) => [$name => Str::headline($name)])
            ->all();
    }

    private function uniqueKey(string $label): string
    {
        $base = Str::slug(trim($label), '_') ?: 'department';
        $key = $base;

        for ($n = 2; Department::query()->where('key', $key)->exists(); $n++) {
            $key = $base.'_'.$n;
        }

        return $key;
    }
}
