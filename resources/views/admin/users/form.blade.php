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
        <option value="">Select Department</option>
        @foreach($departments as $key => $label)
            <option value="{{ $key }}" @selected($currentDepartment === $key)>{{ $label }}</option>
        @endforeach
    </select>
    <div class="form-text">Choose the department first — it decides which roles are offered.</div>
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

<div class="mb-3">
    <label class="form-label">Status</label>
    <select name="status" class="form-control" required>
        <option value="1" @selected(old('status', $user->status ?? 1) == 1)>Active</option>
        <option value="0" @selected(old('status', $user->status ?? 1) == 0)>Inactive</option>
    </select>
</div>