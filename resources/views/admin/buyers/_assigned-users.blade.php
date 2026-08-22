{{-- Who works on this buyer.

     Assignment only. This screen never writes buyer_scope_mode: putting a user
     who works across all buyers onto one buyer here would read as a grant and
     would actually confine them to it, so such a user is not offered and the
     text says where to change that instead.

     The owner is shown but not listed as a team member — a department admin
     owns buyers through buyers.department_admin_id, which is the field on the
     form above, and never appears in the assignment pivot. --}}
<div class="card border-0 shadow-sm mt-4" style="border-radius:var(--gx-radius);">
    <div class="card-body p-4">
        <h5 class="mb-1">Team on this Buyer</h5>
        <p class="text-muted small mb-4">
            The people who may edit this buyer's files. The owner above always can;
            everyone listed here has been assigned to it.
        </p>

        @if($assignedUsers->isEmpty())
            <p class="text-muted mb-4">
                <i class="bi bi-people me-1" aria-hidden="true"></i>
                Nobody is assigned to this buyer yet.
            </p>
        @else
            <div class="table-responsive mb-4">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th scope="col">Name</th>
                            <th scope="col">Email</th>
                            <th scope="col" class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($assignedUsers as $assignedUser)
                            <tr>
                                <td>{{ $assignedUser->name }}</td>
                                <td class="text-muted">{{ $assignedUser->email }}</td>
                                <td class="text-end">
                                    <form action="{{ route('admin.buyers.users.detach', [$buyer, $assignedUser]) }}"
                                          method="POST" class="d-inline">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-outline-danger">
                                            <i class="bi bi-x-circle me-1" aria-hidden="true"></i>Remove
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <form action="{{ route('admin.buyers.users.attach', $buyer) }}" method="POST"
              class="row g-2 align-items-end">
            @csrf
            <div class="col-md-6">
                <label class="form-label" for="assign_user_id">Add a team member</label>
                <select name="user_id" id="assign_user_id"
                        class="form-select @error('user_id') is-invalid @enderror"
                        {{ $assignableUsers->isEmpty() ? 'disabled' : '' }}>
                    <option value="">Choose a user…</option>
                    @foreach($assignableUsers as $assignableUser)
                        <option value="{{ $assignableUser->id }}">
                            {{ $assignableUser->name }} ({{ $assignableUser->email }})
                        </option>
                    @endforeach
                </select>
                @error('user_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-auto">
                <button type="submit" class="btn btn-primary" {{ $assignableUsers->isEmpty() ? 'disabled' : '' }}>
                    <i class="bi bi-person-plus me-1" aria-hidden="true"></i>Add to Buyer
                </button>
            </div>
            <div class="col-12">
                <div class="form-text">
                    Only users who are already set to <strong>specific buyers</strong> appear here.
                    Someone who works across all buyers must be changed from
                    <a href="{{ route('admin.users.index') }}">Admin &rsaquo; Users</a> first —
                    adding them here would restrict them to this buyer alone.
                </div>
            </div>
        </form>
    </div>
</div>
