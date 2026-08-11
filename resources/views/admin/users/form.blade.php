<div class="mb-3">
    <label class="form-label">Name</label>
    <input type="text" name="name" class="form-control" value="{{ old('name', $user->name ?? '') }}" required>
</div>

<div class="mb-3">
    <label class="form-label">Email</label>
    <input type="email" name="email" class="form-control" value="{{ old('email', $user->email ?? '') }}" required>
</div>

<div class="mb-3">
    <label class="form-label">Password @isset($user)<small>(leave blank to keep same)</small>@endisset</label>
    <input type="password" name="password" class="form-control">
</div>

@php
    $currentRoleName = old('role', isset($user) ? $user->getRoleNames()->first() : '');
    $currentDepartment = old('department', \App\Support\DepartmentRoles::departmentOf($currentRoleName));
@endphp

{{-- Department first, then the roles inside it. Picking the department narrows
     the list to that department's own roles, so a Store user cannot be handed
     a Management role by scrolling one line too far. --}}
<div class="mb-3">
    <label class="form-label" for="departmentSelect">Department</label>
    <select name="department" id="departmentSelect" class="form-control js-department" required>
        {{-- A department admin is offered one department, so there is nothing
             to choose and no placeholder to leave sitting on "Select". --}}
        @if($scope->isSuperAdmin())
            <option value="">Select Department</option>
        @endif
        @foreach($departments as $key => $label)
            <option value="{{ $key }}"
                @selected($currentDepartment === $key || ! $scope->isSuperAdmin())>{{ $label }}</option>
        @endforeach
    </select>
    <div class="form-text">
        @if($scope->isSuperAdmin())
            Choose the department first — it decides which roles are offered.
        @else
            You can add and manage {{ $scope->departmentLabel() }} users only.
        @endif
    </div>
</div>

<div class="mb-3">
    <label class="form-label" for="roleSelect">Role</label>
    <select name="role" id="roleSelect" class="form-control js-role" required data-role-departments='@json($roleDepartments)'>
        <option value="">Select Role</option>
        @foreach($roles as $role)
            <option value="{{ $role->name }}"
                data-department="{{ $roleDepartments[$role->name] ?? \App\Support\DepartmentRoles::UNASSIGNED }}"
                @selected($currentRoleName == $role->name)>
                {{ \App\Support\DepartmentRoles::roleLabel($role->name) }}
            </option>
        @endforeach
    </select>
    <div class="form-text js-role-hint">The first role in a department is its Department Admin.</div>
</div>

{{-- Department Admin — a promotion, not an access setting, so only a super
     admin ever sees it. The hidden marker says the control was on the page:
     an unticked checkbox posts nothing, and without the marker a save from a
     form that never showed this field would read as "untick it". --}}
@if($scope->maySetDepartmentAdminFlag())
    <div class="mb-3">
        <input type="hidden" name="department_admin_control" value="1">
        <div class="form-check">
            <input type="checkbox" class="form-check-input" id="isDepartmentAdmin"
                   name="is_department_admin" value="1"
                   @checked(old('is_department_admin', $user->is_department_admin ?? false))>
            <label class="form-check-label" for="isDepartmentAdmin">Department Admin</label>
        </div>
        <div class="form-text">
            Lets this person create and manage users in their own department only,
            and grant them permissions they hold themselves. Only you can set this.
        </div>
    </div>
@endif

<div class="mb-3">
    <label class="form-label">Status</label>
    <select name="status" class="form-control" required>
        <option value="1" @selected(old('status', $user->status ?? 1) == 1)>Active</option>
        <option value="0" @selected(old('status', $user->status ?? 1) == 0)>Inactive</option>
    </select>
</div>