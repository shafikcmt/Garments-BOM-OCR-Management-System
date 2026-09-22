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
                    <p class="app-hero-copy mb-0">The signature boxes printed on each Purchase Requisition, and who heads each department.</p>
                </div>
            </div>
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

    <form method="POST" action="{{ route('admin.requisition-signatures.update') }}" id="signatureSettingsForm">
        @csrf

        <div class="row g-4">
            {{-- Who heads each department and section — what the
                 "Requester's Department Head" box and the Review queue
                 resolve to — is set on the Departments screen, so there is
                 one list of heads, not two. --}}
            <div class="col-12">
                <div class="alert alert-light border small d-flex align-items-center justify-content-between gap-3 flex-wrap mb-0">
                    <span>Department and section heads, who check a department's requisition before it reaches Store, are named on the Departments screen.</span>
                    <a href="{{ route('admin.departments.index') }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-diagram-3 me-1" aria-hidden="true"></i>Manage Departments</a>
                </div>
            </div>

            @foreach($levels as $level => $levelLabel)
                <div class="col-12">
                    <div class="card border-0 shadow-sm" style="border-radius:var(--gx-radius);">
                        <div class="card-body p-4">
                            <div class="d-flex align-items-center justify-content-between gap-3 flex-wrap mb-3">
                                <div>
                                    <h5 class="mb-1">{{ $levelLabel }} — Signature Boxes</h5>
                                    <p class="text-muted small mb-0">
                                        @if($level === 'department')
                                            Printed on a requisition a department raises for itself. Boxes print left to right in this order.
                                        @else
                                            Printed on the merged Purchase Requisition the Store raises, and on the monthly report. Boxes print left to right in this order.
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
                                            @include('admin.requisition-signatures._block-row', ['level' => $level, 'index' => $i, 'row' => $row])
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
