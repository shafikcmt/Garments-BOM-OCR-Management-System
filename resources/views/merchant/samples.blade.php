@extends('layouts.app')

@section('title', 'Samples')

@section('content')
<div class="container-fluid">
    <x-breadcrumb :items="[['label' => 'Merchandising', 'url' => route('merchant.status.index')], ['label' => 'Samples']]" />

    <x-page-header icon="scissors" eyebrow="Merchandising" title="Samples"
                   copy="Every sample of your buyers: what, when the buyer needs it, when it was sent and what the buyer said. Add a sample from the style page.">
        <x-slot:actions><a href="{{ route('merchant.samples.lessons') }}" class="btn btn-outline-secondary"><i class="bi bi-lightbulb me-1" aria-hidden="true"></i>Buyer Comments (Lessons)</a></x-slot:actions>
    </x-page-header>

    <x-flash />
    @if($errors->any())
        <div class="alert alert-danger py-2">@foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>
    @endif

    @if($late > 0)
        <div class="alert alert-danger d-flex align-items-center gap-2 py-2"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i><div><strong>{{ $late }}</strong> sample(s) not sent and past the buyer's date.</div></div>
    @endif

    <x-card class="mb-3" body-class="p-3">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-12 col-md-3">
                <label class="form-label mb-1" for="sBuyer">Buyer</label>
                <select name="buyer" id="sBuyer" class="form-select">
                    <option value="">All buyers</option>
                    @foreach($buyers as $b)<option value="{{ $b->id }}" @selected($buyer?->id === $b->id)>{{ $b->buyer_name }}</option>@endforeach
                </select>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label mb-1" for="sStatus">Status</label>
                <select name="status" id="sStatus" class="form-select">
                    <option value="open" @selected($status === 'open')>Open (not approved or rejected)</option>
                    @foreach(\App\Models\Sample::STATUSES as $k => $label)<option value="{{ $k }}" @selected($status === $k)>{{ $label }}</option>@endforeach
                </select>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label mb-1" for="sType">Sample type</label>
                <select name="type" id="sType" class="form-select">
                    <option value="">All types</option>
                    @foreach($types as $t)<option value="{{ $t->id }}" @selected($typeId === $t->id)>{{ $t->name }}</option>@endforeach
                </select>
            </div>
            <div class="col-auto d-flex gap-2">
                <button class="btn btn-primary"><i class="bi bi-funnel me-1" aria-hidden="true"></i>Filter</button>
                <a href="{{ route('merchant.samples.index') }}" class="btn btn-outline-secondary"><i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>Reset</a>
            </div>
        </form>
    </x-card>

    <x-card class="mb-3" body-class="p-3">
        <x-slot:title>Samples <span class="badge bg-light text-muted border ms-1">{{ $samples->count() }}</span></x-slot:title>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead><tr><th>Style</th><th>Type</th><th>Colour / Size</th><th class="text-end">Qty</th><th>Needed By</th><th>Sent</th><th>Status</th><th>Buyer Comment</th><th></th></tr></thead>
                <tbody>
                @forelse($samples as $s)
                    @include('merchant._sample-row', ['s' => $s, 'canEdit' => $canEdit, 'showStyle' => true])
                @empty
                    <tr><td colspan="9" class="text-center text-muted py-4">No samples for this choice. Add a sample from the style page.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </x-card>

    @if($canEdit)
        <x-card title="Sample Types" body-class="p-3">
            <div class="d-flex flex-wrap gap-2 mb-3">
                @foreach($types as $t)
                    <form method="POST" action="{{ route('merchant.samples.types.toggle', $t) }}">@csrf @method('PUT')
                        <button class="btn btn-sm {{ $t->is_active ? 'btn-outline-secondary' : 'btn-outline-danger' }}" title="{{ $t->is_active ? 'Switch off' : 'Switch on' }}">
                            <i class="bi {{ $t->is_active ? 'bi-toggle-on' : 'bi-toggle-off' }} me-1" aria-hidden="true"></i>{{ $t->name }}{{ $t->is_active ? '' : ' (off)' }}
                        </button>
                    </form>
                @endforeach
            </div>
            <form method="POST" action="{{ route('merchant.samples.types.store') }}" class="row g-2 align-items-end">
                @csrf
                <div class="col-12 col-md-4">
                    <label class="form-label mb-1" for="typeName">New sample type</label>
                    <input type="text" name="name" id="typeName" class="form-control form-control-sm" maxlength="60" placeholder="e.g. Salesman Sample" required>
                </div>
                <div class="col-auto"><button class="btn btn-outline-primary btn-sm"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add Type</button></div>
                <div class="col small text-muted">Press a type to switch it off or on. Switched-off types are not offered for new samples.</div>
            </form>
        </x-card>
    @endif
</div>
@endsection
