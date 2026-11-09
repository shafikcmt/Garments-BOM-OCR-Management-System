@extends('layouts.app')

@section('title', 'Costing Templates')

@section('content')
<div class="container-fluid">
    <x-breadcrumb :items="[['label' => 'Merchandising', 'url' => route('merchant.status.index')], ['label' => 'Costing Templates']]" />

    <x-page-header icon="file-earmark-ruled" eyebrow="Merchandising" title="Costing Templates"
                   copy="A ready cost sheet for a product group. When you start a pre-costing, choose a template instead of typing every line. You can also save any costing as a template from its own page.">
        @if($canEdit)
            <x-slot:actions>
                <a href="{{ route('merchant.costing_templates.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>New Template</a>
            </x-slot:actions>
        @endif
    </x-page-header>

    <x-flash />

    <x-card body-class="p-0">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0" style="min-width:0">
                <thead><tr><th>Template</th><th>Buyer</th><th>Product group</th><th>Currency</th><th>Per</th><th class="text-end">Lines</th><th>Status</th><th class="text-end">Action</th></tr></thead>
                <tbody>
                @forelse($templates as $t)
                    @php($editable = $canEdit && ($t->buyer_id !== null || $isAdmin))
                    <tr>
                        <td class="fw-semibold">{{ $t->name }}</td>
                        <td>{{ $t->buyer?->buyer_name ?? 'All buyers' }}</td>
                        <td>{{ $t->product_group ?: 'Every group' }}</td>
                        <td>{{ $t->currency }}</td>
                        <td>{{ ucfirst(\App\Models\Costing::BASES[$t->basis] ?? $t->basis) }}</td>
                        <td class="text-end">{{ $t->lines_count }}</td>
                        <td>@if($t->is_active)<span class="badge bg-success-subtle text-success">Active</span>@else<span class="badge bg-secondary-subtle text-secondary">Inactive</span>@endif</td>
                        <td class="text-end text-nowrap">
                            @if($editable)
                                <a href="{{ route('merchant.costing_templates.edit', $t) }}" class="btn btn-outline-primary btn-sm"><i class="bi bi-pencil me-1" aria-hidden="true"></i>Edit</a>
                                <form method="POST" action="{{ route('merchant.costing_templates.destroy', $t) }}" class="d-inline" onsubmit="return confirm('Delete the template {{ $t->name }}? Costings already made from it are not changed.')">
                                    @csrf @method('DELETE')
                                    <button class="btn btn-outline-danger btn-sm"><i class="bi bi-trash me-1" aria-hidden="true"></i>Delete</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-center text-muted py-4">No template yet.@if($canEdit) Press New Template, or open a costing and press Save as Template.@endif</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </x-card>
</div>
@endsection
