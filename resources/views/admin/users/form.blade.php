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

<div class="mb-3">
    <label class="form-label">Role</label>
    <select name="role" class="form-control" required>
        <option value="">Select Role</option>
        @foreach($roles as $role)
            <option value="{{ $role->name }}"
                @selected(old('role', isset($user) ? $user->getRoleNames()->first() : '') == $role->name)>
                {{ ucfirst(str_replace('_', ' ', $role->name)) }}
            </option>
        @endforeach
    </select>
</div>

<div class="mb-3">
    <label class="form-label">Status</label>
    <select name="status" class="form-control" required>
        <option value="1" @selected(old('status', $user->status ?? 1) == 1)>Active</option>
        <option value="0" @selected(old('status', $user->status ?? 1) == 0)>Inactive</option>
    </select>
</div>