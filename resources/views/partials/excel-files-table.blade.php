@php
    $workspaceLockRoles = $workspaceLockRoles ?? collect();
    $workspaceLockUsers = $workspaceLockUsers ?? collect();
    $authUser = auth()->user();
    $canLockFiles = $authUser?->hasRole('admin');
    $canDeleteFiles = $authUser?->hasRole('admin') || $authUser?->hasRole('merchant');

    // Resolved by ExcelFileSummaryService in the controller. Falling back to
    // resolving it here would reintroduce the cell-hydration cost this replaced,
    // so an unset value simply shows blanks.
    $fileSummaries = $fileSummaries ?? [];

    $statusOptions = collect($files)->pluck('status')->filter()->unique()->sort()->values();
@endphp

<div class="card shadow-sm border-0 gx-card" data-file-table>
    <div class="card-body">
        {{-- Filtering runs in the browser over rows already on the page, which
             suits a list this size and keeps the lock/permission logic below
             server-rendered. --}}
        <div class="row g-2 align-items-end mb-3">
            <div class="col-12 col-lg-5">
                <label class="form-label small fw-semibold mb-1" for="fileSearch">Search</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-search" aria-hidden="true"></i></span>
                    <input type="search" id="fileSearch" class="form-control" data-file-search
                           placeholder="Buyer, season, style or contract…" autocomplete="off">
                </div>
            </div>

            <div class="col-6 col-lg-3">
                <label class="form-label small fw-semibold mb-1" for="fileStatus">Status</label>
                <select id="fileStatus" class="form-select" data-file-status>
                    <option value="">All statuses</option>
                    @foreach($statusOptions as $option)
                        <option value="{{ strtolower($option) }}">{{ ucfirst($option) }}</option>
                    @endforeach
                </select>
            </div>

            <div class="col-6 col-lg-4 d-flex gap-2">
                <button type="button" class="btn btn-outline-secondary" data-file-clear>Clear</button>
                <button type="button" class="btn btn-outline-primary" data-file-export disabled>
                    <i class="bi bi-download me-1" aria-hidden="true"></i>Export <span data-file-export-count></span>
                </button>
            </div>
        </div>

        <div class="d-flex flex-wrap align-items-center gap-2 mb-2 d-none" data-file-chips></div>

        <div class="small text-muted mb-2" data-file-count aria-live="polite"></div>

        <div class="table-responsive gx-table-scroll">
        <table class="table table-bordered align-middle mb-0 gx-file-table">
            <thead>
                <tr>
                    <th style="width:38px;">
                        <input type="checkbox" class="form-check-input" data-file-select-all
                               aria-label="Select all files">
                    </th>
                    <th>#</th>
                    <th><button type="button" class="gx-sort" data-sort="1">Buyer Name <i class="bi bi-arrow-down-up" aria-hidden="true"></i></button></th>
                    <th><button type="button" class="gx-sort" data-sort="2">Season Name <i class="bi bi-arrow-down-up" aria-hidden="true"></i></button></th>
                    <th><button type="button" class="gx-sort" data-sort="3">Style Name <i class="bi bi-arrow-down-up" aria-hidden="true"></i></button></th>
                    <th><button type="button" class="gx-sort" data-sort="4">Contract Number <i class="bi bi-arrow-down-up" aria-hidden="true"></i></button></th>
                    <th><button type="button" class="gx-sort" data-sort="5">Contract Shipment Date <i class="bi bi-arrow-down-up" aria-hidden="true"></i></button></th>
                    <th><button type="button" class="gx-sort" data-sort="6">Status <i class="bi bi-arrow-down-up" aria-hidden="true"></i></button></th>
                    <th width="280">Action</th>
                </tr>
            </thead>
            <tbody>
                @forelse($files as $file)
                    @php
                        $summary = ($fileSummaries[$file->id] ?? []) + [
                            'Buyer Name' => '',
                            'Season Name' => '',
                            'Style Name' => '',
                            'Contract Number' => '',
                            'Contract Shipment Date' => '',
                        ];

                        $status = strtolower($file->status ?? 'pending');
                        $isFileLocked = (bool) ($file->is_locked ?? false);
                        $isLockedForCurrentUser = $file->isLockedForUser($authUser);

                        $statusClass = match($status) {
                            'pending' => 'warning',
                            'processing' => 'info',
                            'completed' => 'success',
                            'locked' => 'secondary',
                            default => 'secondary',
                        };

                        $actionLabel = $isLockedForCurrentUser
                            ? 'View'
                            : match($status) {
                                'pending' => 'Open',
                                'processing' => 'Edit',
                                'completed' => 'Open',
                                'locked' => 'View',
                                default => 'Open',
                            };
                    @endphp

                    <tr class="{{ $isLockedForCurrentUser ? 'table-warning' : '' }}"
                        data-file-row
                        data-status="{{ $status }}"
                        data-search="{{ strtolower(collect($summary)->filter()->implode(' ')) }}">
                        <td>
                            <input type="checkbox" class="form-check-input" data-file-check
                                   value="{{ $file->id }}"
                                   data-export="{{ collect([$summary['Buyer Name'], $summary['Season Name'], $summary['Style Name'], $summary['Contract Number'], $summary['Contract Shipment Date'], $status])->implode('|') }}"
                                   aria-label="Select this file">
                        </td>
                        <td>{{ $loop->iteration }}</td>
                        <td>{{ $summary['Buyer Name'] ?: '-' }}</td>
                        <td>{{ $summary['Season Name'] ?: '-' }}</td>
                        <td>{{ $summary['Style Name'] ?: '-' }}</td>
                        <td>{{ $summary['Contract Number'] ?: '-' }}</td>
                        <td>{{ $summary['Contract Shipment Date'] ?: '-' }}</td>
                        <td>
                            <div class="d-flex flex-column gap-1 align-items-start">
                                <span class="badge bg-{{ $statusClass }}">
                                    {{ ucfirst($status) }}
                                </span>

                                @if($isFileLocked)
                                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle" title="{{ $file->lock_reason ?: 'Locked by admin' }}">
                                        <i class="bi bi-lock-fill me-1" aria-hidden="true"></i>{{ $file->lockScopeLabel() }} lock
                                    </span>
                                    @if($isLockedForCurrentUser)
                                        <small class="text-danger fw-semibold">Read only for you</small>
                                    @endif
                                @endif
                            </div>
                        </td>
                        <td>
                            <div class="d-flex gap-1 flex-wrap">
                                <a href="{{ route('uploaded-files.show', $file->id) }}"
                                   class="btn btn-sm {{ $isLockedForCurrentUser ? 'btn-outline-secondary' : 'btn-primary' }}"
                                   title="{{ $isLockedForCurrentUser ? 'This file is locked. You can view only.' : 'Open file' }}">
                                    {{ $actionLabel }}
                                </a>

                                @if($canLockFiles)
                                    <button type="button"
                                            class="btn btn-sm {{ $isFileLocked ? 'btn-warning' : 'btn-outline-warning' }}"
                                            data-bs-toggle="modal"
                                            data-bs-target="#fileLockModal{{ $file->id }}">
                                        <i class="bi {{ $isFileLocked ? 'bi-lock-fill' : 'bi-unlock' }}"></i>
                                        {{ $isFileLocked ? 'Manage' : 'Lock' }}
                                    </button>
                                @endif

                                @if($canDeleteFiles)
                                    <form action="{{ route('uploaded-files.destroy', $file->id) }}"
                                          method="POST"
                                          onsubmit="return confirm('Are you sure you want to delete this file? This will remove the uploaded file and all worksheet rows.');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-danger">
                                            Delete
                                        </button>
                                    </form>
                                @endif
                            </div>

                            @if($isFileLocked && ($file->lockedBy || $file->locked_at))
                                <div class="small text-muted mt-1">
                                    @if($file->lockedBy)
                                        By {{ $file->lockedBy->name }}
                                    @endif
                                    @if($file->locked_at)
                                        {{ $file->lockedBy ? ' · ' : '' }}{{ $file->locked_at->format('Y-m-d H:i') }}
                                    @endif
                                </div>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9">
                            <div class="text-center text-muted py-5">
                                <i class="bi bi-inbox d-block mb-2" style="font-size:32px;"></i>
                                <div class="fw-semibold mb-1">No BOM files yet</div>
                                <p class="small mb-3">Upload a merchant input file to start the workspace.</p>
                                @if(auth()->user()?->hasRole('merchant'))
                                    <a href="{{ route('merchant.workspace') }}" class="btn btn-primary btn-sm">
                                        <i class="bi bi-upload me-1" aria-hidden="true"></i>Upload your first BOM
                                    </a>
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforelse

                {{-- Shown when a filter matches nothing; the empty row above is
                     for when there is genuinely no data at all. --}}
                <tr class="d-none" data-file-no-match>
                    <td colspan="9" class="text-center text-muted py-4">
                        No file matches the current filters.
                    </td>
                </tr>
            </tbody>
        </table>
        </div>
    </div>
</div>

@if($canLockFiles)
    @foreach($files as $file)
        @php
            $isFileLocked = (bool) ($file->is_locked ?? false);
            $lockScope = $file->lock_scope ?: 'all_users';
            $lockedUserIds = collect($file->locked_user_ids ?? [])->map(fn ($id) => (int) $id)->all();
            $lockedRoleIds = collect($file->locked_role_ids ?? [])->map(fn ($id) => (int) $id)->all();
        @endphp

        <div class="modal fade" id="fileLockModal{{ $file->id }}" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-centered">
                <div class="modal-content border-0 shadow">
                    <form method="POST" action="{{ route('uploaded-files.lock', $file->id) }}">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="locked" value="0">

                        <div class="modal-header">
                            <div>
                                <h5 class="modal-title mb-0">Workspace File Lock Control</h5>
                                <small class="text-muted">{{ $file->original_file_name ?? $file->file_name }}</small>
                            </div>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>

                        <div class="modal-body">
                            <div class="alert alert-info py-2 small">
                                Admin lock makes matching users read-only. Locked users cannot edit, update, paste changes, or add rows. Delete remains available only for admin and merchant.
                            </div>

                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" role="switch" id="workspaceFileLocked{{ $file->id }}" name="locked" value="1" @checked($isFileLocked)>
                                <label class="form-check-label fw-bold" for="workspaceFileLocked{{ $file->id }}">Lock this workspace file</label>
                            </div>

                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">Lock scope</label>
                                    <select name="lock_scope" class="form-select">
                                        <option value="all_users" @selected($lockScope === 'all_users')>Lock all users</option>
                                        <option value="specific_roles" @selected($lockScope === 'specific_roles')>Lock specific roles</option>
                                        <option value="specific_users" @selected($lockScope === 'specific_users')>Lock specific users</option>
                                    </select>
                                    <small class="text-muted">Choose who cannot edit this file.</small>
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">Specific roles</label>
                                    <select name="locked_role_ids[]" class="form-select" multiple size="6">
                                        @foreach($workspaceLockRoles as $role)
                                            <option value="{{ $role->id }}" @selected(in_array((int) $role->id, $lockedRoleIds, true))>
                                                {{ ucfirst(str_replace('_', ' ', $role->name)) }}
                                            </option>
                                        @endforeach
                                    </select>
                                    <small class="text-muted">Used only when scope is specific roles.</small>
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">Specific users</label>
                                    <select name="locked_user_ids[]" class="form-select" multiple size="6">
                                        @foreach($workspaceLockUsers as $lockUser)
                                            <option value="{{ $lockUser->id }}" @selected(in_array((int) $lockUser->id, $lockedUserIds, true))>
                                                {{ $lockUser->name }} - {{ $lockUser->email }}
                                            </option>
                                        @endforeach
                                    </select>
                                    <small class="text-muted">Used only when scope is specific users.</small>
                                </div>
                            </div>

                            <div class="mt-3">
                                <label class="form-label fw-semibold">Lock reason / note</label>
                                <textarea name="lock_reason" rows="3" class="form-control" placeholder="Why is this file locked?">{{ $file->lock_reason }}</textarea>
                            </div>
                        </div>

                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary">Save Lock Control</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endforeach
@endif
