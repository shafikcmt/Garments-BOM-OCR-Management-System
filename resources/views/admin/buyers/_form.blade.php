<div class="row g-3">

    <div class="col-md-4">
        <label class="form-label" for="buyer_code">Buyer Code</label>
        <input type="text" name="buyer_code" id="buyer_code" class="form-control @error('buyer_code') is-invalid @enderror"
               value="{{ old('buyer_code', $buyer->buyer_code ?? '') }}"
               placeholder="BUY-001">
        @error('buyer_code')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="col-md-4">
        <label class="form-label" for="buyer_name">Buyer Name <span class="text-danger">*</span></label>
        <input type="text" name="buyer_name" id="buyer_name" class="form-control @error('buyer_name') is-invalid @enderror"
               value="{{ old('buyer_name', $buyer->buyer_name ?? '') }}"
               placeholder="H&M">
        @error('buyer_name')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    {{-- Buyer ownership. Assigning an admin here is what scopes their whole
         team: the admin uploads for this buyer, users they create inherit it,
         and files of other buyers become read-only for them. The hidden marker
         says the control was on the page, so a form that never showed it can
         never read as "clear the owner". --}}
    <div class="col-md-4">
        <label class="form-label" for="department_admin_id">Department Admin</label>
        <input type="hidden" name="department_admin_control" value="1">
        <select name="department_admin_id" id="department_admin_id"
                class="form-select @error('department_admin_id') is-invalid @enderror">
            <option value="">Not assigned</option>
            @foreach($merchantAdmins as $admin)
                <option value="{{ $admin->id }}"
                    @selected((int) old('department_admin_id', $buyer->department_admin_id ?? 0) === (int) $admin->id)>
                    {{ $admin->name }} ({{ $admin->email }})
                </option>
            @endforeach
        </select>
        @error('department_admin_id')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
        <div class="form-text">
            @if($merchantAdmins->isEmpty())
                No Merchant department admin exists yet. Create one from Admin &gt; Users first.
            @else
                The Merchandising admin who owns this buyer. One admin owns one buyer — assigning
                them here releases any buyer they held before.
            @endif
        </div>
    </div>

    <div class="col-md-4">
        <label class="form-label" for="contact_person">Contact Person</label>
        <input type="text" name="contact_person" id="contact_person" class="form-control @error('contact_person') is-invalid @enderror"
               value="{{ old('contact_person', $buyer->contact_person ?? '') }}"
               placeholder="Buyer contact name">
        @error('contact_person')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="col-md-4">
        <label class="form-label" for="email">Email</label>
        <input type="email" name="email" id="email" class="form-control @error('email') is-invalid @enderror"
               value="{{ old('email', $buyer->email ?? '') }}"
               placeholder="buyer@example.com">
        @error('email')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="col-md-4">
        <label class="form-label" for="phone">Phone</label>
        <input type="text" name="phone" id="phone" class="form-control @error('phone') is-invalid @enderror"
               value="{{ old('phone', $buyer->phone ?? '') }}"
               placeholder="+880...">
        @error('phone')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="col-md-4 d-flex align-items-end">
        <div class="form-check form-switch mb-2">
            <input type="hidden" name="is_active" value="0">

            <input class="form-check-input" type="checkbox" name="is_active" value="1" id="is_active"
                   {{ old('is_active', $buyer->is_active ?? true) ? 'checked' : '' }}>

            <label class="form-check-label" for="is_active">
                Active Buyer
            </label>
        </div>
    </div>

</div>
