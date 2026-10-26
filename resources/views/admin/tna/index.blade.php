@extends('layouts.app')

@section('title', 'TnA Templates')

@section('content')
<div class="container-fluid">
    <x-breadcrumb :items="[['label' => 'Admin', 'url' => route('admin.dashboard')], ['label' => 'TnA Templates']]" />

    <x-page-header icon="calendar2-check" eyebrow="Admin" title="TnA Templates"
                   copy="Time and Action plans: tasks due a number of days before or after an order date, given to a role. Apply one to a style from its page, or let it apply itself every morning.">
        <x-slot:actions>
            <a href="{{ route('admin.tna.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>New Template</a>
        </x-slot:actions>
    </x-page-header>

    <x-flash />

    <x-card body-class="p-3">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead><tr><th>Template</th><th>Buyer</th><th class="text-end">Tasks</th><th>Status</th><th>Applies</th><th class="text-end">Action</th></tr></thead>
                <tbody>
                @forelse($templates as $t)
                    <tr>
                        <td class="fw-semibold">{{ $t->name }}</td>
                        <td>{{ $t->buyer?->buyer_name ?? 'All buyers' }}</td>
                        <td class="text-end">{{ $t->tasks_count }}</td>
                        <td>@if($t->is_active)<span class="badge bg-success-subtle text-success">Active</span>@else<span class="badge bg-secondary-subtle text-secondary">Inactive</span>@endif</td>
                        <td>{{ $t->auto_apply ? 'Automatically every morning' : 'By hand from the style page' }}</td>
                        <td class="text-end text-nowrap">
                            <a href="{{ route('admin.tna.edit', $t) }}" class="btn btn-outline-primary btn-sm"><i class="bi bi-pencil me-1" aria-hidden="true"></i>Edit</a>
                            <form method="POST" action="{{ route('admin.tna.destroy', $t) }}" class="d-inline" onsubmit="return confirm('Delete the TnA template {{ $t->name }}? Tasks already made from it are kept.')">
                                @csrf @method('DELETE')
                                <button class="btn btn-outline-danger btn-sm"><i class="bi bi-trash me-1" aria-hidden="true"></i>Delete</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted py-4">No TnA template yet. Press New Template.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </x-card>
</div>
@endsection
