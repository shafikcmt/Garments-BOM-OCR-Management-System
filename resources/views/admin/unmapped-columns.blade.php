@extends('layouts.app')

@section('title', 'Unmapped Columns')

@section('content')
<div class="container-fluid">
    <x-breadcrumb :items="[['label' => 'Admin', 'url' => route('admin.dashboard')], ['label' => 'Unmapped Columns']]" />

    <x-page-header icon="columns-gap" eyebrow="Admin" title="Unmapped Columns"
                   copy="Column titles that buyers' Excel files carry and the workspace has no header for. Teach a title to an existing header (it becomes an Other Name and the next file matches it), make a new header, or ignore it.">
        <x-slot:actions>
            <a href="{{ route('admin.headers.create') }}" class="btn btn-outline-secondary"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>New Header</a>
        </x-slot:actions>
    </x-page-header>

    <x-flash />
    @if($errors->any())
        <div class="alert alert-danger py-2">@foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>
    @endif

    <div class="btn-group mb-3" role="group">
        @foreach(['open' => 'Open', 'mapped' => 'Taught', 'ignored' => 'Ignored'] as $k => $label)
            <a href="{{ route('admin.unmapped.index', ['status' => $k]) }}" class="btn btn-sm {{ $status === $k ? 'btn-primary' : 'btn-outline-secondary' }}">{{ $label }} ({{ $counts[$k] ?? 0 }})</a>
        @endforeach
    </div>

    <x-card body-class="p-0">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0" style="min-width:0">
                <thead><tr><th>Column title</th><th>Buyers</th><th class="text-end">Files</th><th>Last seen</th><th class="text-end" style="min-width:420px">Action</th></tr></thead>
                <tbody>
                @forelse($groups as $g)
                    <tr>
                        <td class="fw-semibold">{{ $g['title'] }}</td>
                        <td>{{ $g['buyers'] ? implode(', ', $g['buyers']) : '—' }}</td>
                        <td class="text-end">{{ $g['times'] }}</td>
                        <td class="text-nowrap">{{ $g['last']?->format('d M Y') ?? '—' }}</td>
                        <td class="text-end">
                            @if($status === 'open')
                                <form method="POST" action="{{ route('admin.unmapped.teach') }}" class="d-inline-flex gap-1 align-items-center">
                                    @csrf
                                    <input type="hidden" name="title_key" value="{{ $g['key'] }}">
                                    <select name="header_id" class="form-select form-select-sm" style="width:190px" required aria-label="Header for {{ $g['title'] }}">
                                        <option value="">Header…</option>
                                        @foreach($headers as $h)<option value="{{ $h->id }}">{{ $h->header_name }}</option>@endforeach
                                    </select>
                                    <button class="btn btn-outline-primary btn-sm"><i class="bi bi-link-45deg me-1" aria-hidden="true"></i>Add as Other Name</button>
                                </form>
                                <a href="{{ route('admin.headers.create', ['name' => $g['title']]) }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>New Header</a>
                                <form method="POST" action="{{ route('admin.unmapped.ignore') }}" class="d-inline">@csrf
                                    <input type="hidden" name="title_key" value="{{ $g['key'] }}">
                                    <button class="btn btn-outline-danger btn-sm"><i class="bi bi-eye-slash me-1" aria-hidden="true"></i>Ignore</button>
                                </form>
                            @elseif($status === 'mapped')
                                <span class="text-muted small">Other Name of <strong>{{ $g['header'] ?? '—' }}</strong></span>
                            @else
                                <form method="POST" action="{{ route('admin.unmapped.reopen') }}" class="d-inline">@csrf
                                    <input type="hidden" name="title_key" value="{{ $g['key'] }}">
                                    <button class="btn btn-outline-primary btn-sm"><i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>Reopen</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center text-muted py-4">{{ $status === 'open' ? 'Nothing here. Every column of the imported files was either imported or already known.' : 'Nothing here.' }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </x-card>
    <p class="small text-muted mt-3 mb-0">Only columns that match no header by name, formula key or Other Name are listed. A column that was skipped on purpose but matches another department's header is not listed. Teaching a name changes the header for every buyer; to keep a title for one buyer only, map it in that buyer's import profile instead.</p>
</div>
@endsection
