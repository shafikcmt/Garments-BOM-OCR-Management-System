@extends('layouts.app')

@section('title', 'Requisition Signature Settings')

@php
    // The rows the form opens with: what failed validation, else what is saved.
    $rowsFor = function (string $level) use ($blocksByLevel) {
        $old = old('blocks.'.$level);
        if (is_array($old)) {
            return array_values($old);
        }

        return ($blocksByLevel[$level] ?? collect())->map(fn ($b) => [
            'label' => $b->label,
            'title' => $b->title,
            'assignee_type' => $b->assignee_type,
            'role_name' => $b->role_name,
            'user_id' => $b->user_id,
            'stamp_source' => $b->stamp_source ?? '',
        ])->values()->all();
    };
@endphp

@section('content')
<div class="container-fluid">
    <x-breadcrumb :items="[
        ['label' => 'Admin', 'url' => route('admin.dashboard')],
        ['label' => 'Settings'],
        ['label' => 'Requisition Signatures'],
    ]" />

    <div class="app-hero-card p-4 mb-4">
        <div class="d-flex align-items-center justify-content-between gap-3 flex-wrap">
            <div class="d-flex align-items-center gap-3">
                <span class="app-stat-icon" style="width:46px;height:46px;border-radius:15px;font-size:20px;"><i class="bi bi-pen" aria-hidden="true"></i></span>
                <div>
                    <div class="app-hero-eyebrow">Admin / Settings</div>
                    <h3 class="app-hero-title mb-0">Requisition Signatures</h3>
                    <p class="app-hero-copy mb-0">The signature boxes printed on each Purchase Requisition. Department and section heads, who check a sheet before it reaches Store, are named on the Departments screen.</p>
                </div>
            </div>
            {{-- Heads live on the Departments screen — one list, not two. --}}
            <a href="{{ route('admin.departments.index') }}" class="btn btn-outline-primary">
                <i class="bi bi-diagram-3 me-1" aria-hidden="true"></i>Manage Departments
            </a>
        </div>
    </div>

    @if(session('success'))
        <div class="alert alert-success border-0 shadow-sm rounded-3">{{ session('success') }}</div>
    @endif

    @if($errors->any())
        <div class="alert alert-danger border-0 shadow-sm rounded-3">
            <ul class="mb-0">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Stages nobody could approve today. A sheet needing one of them
         cannot be submitted until it is set. --}}
    @php
        $warningCount = collect($stageWarnings)->flatten()->count();
    @endphp
    @if($warningCount > 0)
        <div class="alert alert-warning border-0 shadow-sm rounded-3">
            <i class="bi bi-exclamation-triangle-fill me-1" aria-hidden="true"></i>
            {{ $warningCount }} approval {{ $warningCount === 1 ? 'stage is' : 'stages are' }} not set. Requisitions that need {{ $warningCount === 1 ? 'it' : 'them' }} cannot be submitted until {{ $warningCount === 1 ? 'it is' : 'they are' }} set — see the boxes marked below.
        </div>
    @endif

    <form method="POST" action="{{ route('admin.requisition-signatures.update') }}" id="signatureSettingsForm">
        @csrf

        {{-- The header block printed on every requisition. Saved on each
             submit as the requisition's own copy, so changing these later
             does not rewrite a document already sent. --}}
        @php
            $pickable = function (?int $chosen) use ($users) {
                // A chosen user who has since been deactivated still shows,
                // so the admin can see who it is and replace them.
                return $chosen && ! $users->contains('id', $chosen)
                    ? $users->concat(\App\Models\User::whereKey($chosen)->get())
                    : $users;
            };
        @endphp
        <div class="card border-0 shadow-sm mb-4" style="border-radius:var(--gx-radius);">
            <div class="card-body p-4">
                <h5 class="fw-bold mb-1">Document Header</h5>
                <p class="text-muted small mb-3">Printed in the header of every Purchase Requisition. Each requisition keeps the values it was submitted with.</p>
                <div class="row g-3">
                    <div class="col-12 col-lg-4">
                        <label class="form-label fw-semibold" for="headerUnitName">Name of Unit</label>
                        <input type="text" name="header_unit_name" id="headerUnitName" class="form-control" maxlength="255"
                               value="{{ old('header_unit_name', $header['unit_name']) }}" placeholder="{{ $companyName }}">
                        <div class="form-text">Leave empty to use {{ $companyName }}.</div>
                    </div>
                    <div class="col-12 col-lg-4">
                        <label class="form-label fw-semibold" for="headerStorePersonnel">Store Personnel</label>
                        <select name="header_store_personnel" id="headerStorePersonnel" class="form-select js-searchable" placeholder="Store department head">
                            <option value="">Store department head</option>
                            @foreach($pickable($header['store_personnel']) as $u)
                                <option value="{{ $u->id }}" @selected((int) old('header_store_personnel', $header['store_personnel']) === $u->id)>{{ $u->name }} — {{ $u->email }}</option>
                            @endforeach
                        </select>
                        <div class="form-text">Name, designation, department, phone and email in the Store Personnel box.</div>
                    </div>
                    <div class="col-12 col-lg-4">
                        <label class="form-label fw-semibold" for="headerContactPerson">Contact Person (final sheet)</label>
                        <select name="header_contact_person" id="headerContactPerson" class="form-select js-searchable" placeholder="The person who prepared the sheet">
                            <option value="">The person who prepared the sheet</option>
                            @foreach($pickable($header['contact_person']) as $u)
                                <option value="{{ $u->id }}" @selected((int) old('header_contact_person', $header['contact_person']) === $u->id)>{{ $u->name }} — {{ $u->email }}</option>
                            @endforeach
                        </select>
                        <div class="form-text">Contact No. / Email on the Store's final sheet. A department sheet shows the requester's own.</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4">

            @foreach($levels as $level => $levelLabel)
                <div class="col-12">
                    <div class="card border-0 shadow-sm" style="border-radius:var(--gx-radius);">
                        <div class="card-body p-4">
                            <div class="d-flex align-items-center justify-content-between gap-3 flex-wrap mb-3">
                                <div>
                                    <h5 class="mb-1">{{ $levelLabel }} — Signature Boxes</h5>
                                    <p class="text-muted small mb-0">
                                        @if($level === 'department')
                                            Printed on a requisition a department raises for itself. Box 1 is the person who raised it; each box after it approves in this order. The last box (Received by Store) ends the sheet — it then waits to be merged.
                                        @else
                                            Printed on the merged Purchase Requisition the Store raises, and on the monthly report. Box 1 is the Store user who merges, checks and submits it (Prepared by); each box after it approves in this order, and the last box gives the final approval.
                                        @endif
                                    </p>
                                </div>
                                <button type="button" class="btn btn-outline-primary btn-sm" data-add-block="{{ $level }}">
                                    <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add Box
                                </button>
                            </div>

                            <div class="table-responsive">
                                <table class="table align-middle mb-0" data-block-table="{{ $level }}">
                                    <thead class="table-light">
                                        <tr>
                                            <th style="width:48px;">#</th>
                                            <th style="min-width:160px;">Label</th>
                                            <th style="min-width:170px;">Designation shown</th>
                                            <th style="min-width:190px;">Signed by</th>
                                            <th style="min-width:220px;">Role / User</th>
                                            <th style="min-width:210px;">Name &amp; date filled from</th>
                                            <th style="width:150px;" class="text-end">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($rowsFor($level) as $i => $row)
                                            @include('admin.requisition-signatures._block-row', ['level' => $level, 'index' => $i, 'row' => $row, 'warning' => $stageWarnings[$level][$i] ?? null])
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="mt-4">
            <button type="submit" class="btn btn-primary px-4"><i class="bi bi-save me-1" aria-hidden="true"></i>Save Settings</button>
        </div>
    </form>

    {{-- The row a new box is cloned from. __LEVEL__ / __INDEX__ are replaced
         on insert; the row's own selects are then wired up like the rest. --}}
    <template id="blockRowTemplate">
        @include('admin.requisition-signatures._block-row', ['level' => '__LEVEL__', 'index' => '__INDEX__', 'row' => [
            'label' => '', 'title' => '', 'assignee_type' => 'user', 'role_name' => '', 'user_id' => '', 'stamp_source' => '',
        ]])
    </template>

    @include('store.stock._searchable')
</div>

<script>
(function () {
    // Show only the picker the "Signed by" choice needs.
    function syncRow(row) {
        var type = row.querySelector('[data-field="assignee_type"]').value;
        row.querySelector('[data-role-picker]').classList.toggle('d-none', type !== 'role');
        row.querySelector('[data-user-picker]').classList.toggle('d-none', type !== 'user');
        row.querySelector('[data-no-picker]').classList.toggle('d-none', type === 'role' || type === 'user');
    }

    function renumber(table) {
        var level = table.getAttribute('data-block-table');
        table.querySelectorAll('tbody tr').forEach(function (row, i) {
            row.querySelector('[data-seq]').textContent = i + 1;
            row.querySelectorAll('[name]').forEach(function (el) {
                el.name = el.name.replace(/^blocks\[[^\]]*\]\[[^\]]*\]/, 'blocks[' + level + '][' + i + ']');
            });
            var count = table.querySelectorAll('tbody tr').length;
            row.querySelector('[data-move-up]').disabled = i === 0;
            row.querySelector('[data-move-down]').disabled = i === count - 1;
            // A level must keep at least one box — the server refuses an
            // empty level too; this just keeps the button honest.
            row.querySelector('[data-remove-block]').disabled = count <= 1;
        });
    }

    function wire(row) {
        row.querySelector('[data-field="assignee_type"]').addEventListener('change', function () { syncRow(row); });
        syncRow(row);
    }

    document.querySelectorAll('[data-block-table]').forEach(function (table) {
        table.querySelectorAll('tbody tr').forEach(wire);
        renumber(table);

        table.addEventListener('click', function (e) {
            var btn = e.target.closest('button');
            if (!btn) { return; }
            var row = btn.closest('tr');
            var body = table.querySelector('tbody');

            if (btn.hasAttribute('data-remove-block')) {
                if (body.querySelectorAll('tr').length <= 1) { return; }
                row.remove();
            } else if (btn.hasAttribute('data-move-up') && row.previousElementSibling) {
                body.insertBefore(row, row.previousElementSibling);
            } else if (btn.hasAttribute('data-move-down') && row.nextElementSibling) {
                body.insertBefore(row.nextElementSibling, row);
            }
            renumber(table);
        });
    });

    document.querySelectorAll('[data-add-block]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var level = btn.getAttribute('data-add-block');
            var table = document.querySelector('[data-block-table="' + level + '"]');
            var body = table.querySelector('tbody');
            var html = document.getElementById('blockRowTemplate').innerHTML
                .replace(/__LEVEL__/g, level)
                .replace(/__INDEX__/g, String(body.querySelectorAll('tr').length));
            body.insertAdjacentHTML('beforeend', html);
            var row = body.lastElementChild;
            wire(row);
            renumber(table);
            if (window.gxInitSearchable) { window.gxInitSearchable(row); }
        });
    });
})();
</script>
@endsection
