<div class="mb-3">
    <label class="form-label">Role Name</label>
    <input type="text" name="name" class="form-control" value="{{ old('name', $role->name ?? '') }}" placeholder="Example: merchant" required>
    <small class="text-muted">Use small letters. Example: admin, merchant, account, commercial, store, supply_chain</small>
</div>

<div class="mb-3">
    <label class="form-label">Guard Name</label>
    <input type="text" name="guard_name" class="form-control" value="{{ old('guard_name', $role->guard_name ?? 'web') }}" required>
</div>