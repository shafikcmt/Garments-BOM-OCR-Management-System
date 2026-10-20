@extends('layouts.app')

@section('title', 'Style Library')

@section('content')
<div class="container-fluid">
    <x-breadcrumb :items="[
        ['label' => 'Merchandising', 'url' => route('merchant.dashboard')],
        ['label' => 'Style Library'],
    ]" />

    <x-page-header data-aos="fade-down" icon="diagram-3" eyebrow="Merchandising"
                   title="Style Library"
                   copy="Buyer, season, mother style and child style — the same structure as the buyer folders." />

    <x-flash />

    <div class="row g-3 mb-3">
        <div class="col-6 col-xl-3"><x-stat-card icon="people" tone="primary" label="Buyers" :value="$totals['buyers']" /></div>
        <div class="col-6 col-xl-3"><x-stat-card icon="calendar3" tone="primary" label="Seasons" :value="$totals['seasons']" /></div>
        <div class="col-6 col-xl-3"><x-stat-card icon="tag" tone="success" label="Mother styles" :value="$totals['mothers']" /></div>
        <div class="col-6 col-xl-3"><x-stat-card icon="tags" tone="success" label="Child styles" :value="$totals['children']" /></div>
    </div>

    @if($buyers->isEmpty())
        <x-card><p class="text-muted mb-0">No buyer is assigned to you yet. Ask Admin to assign a buyer.</p></x-card>
    @else
    <div class="row g-3">
        {{-- Buyers --}}
        <div class="col-12 col-lg-3">
            <x-card title="Buyers" body-class="p-2">
                <div class="list-group list-group-flush">
                    @foreach($buyers as $b)
                        <a href="{{ route('merchant.styles.index', ['buyer' => $b->id]) }}"
                           class="list-group-item list-group-item-action d-flex justify-content-between align-items-center {{ $buyer && $buyer->id === $b->id ? 'active' : '' }}">
                            <span>{{ $b->buyer_name }}</span>
                            <span class="badge bg-secondary-subtle text-secondary">{{ $b->mother_styles_count }}</span>
                        </a>
                    @endforeach
                </div>
            </x-card>
        </div>

        <div class="col-12 col-lg-9">
            {{-- Seasons --}}
            <x-card class="mb-3" :title="$buyer->buyer_name.' — Seasons'">
                <x-slot:actions>
                    <button class="btn btn-outline-primary btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#addSeason">
                        <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add Season
                    </button>
                </x-slot:actions>

                <form id="addSeason" method="POST" action="{{ route('merchant.styles.seasons.store') }}" class="collapse row g-2 mb-3">
                    @csrf
                    <input type="hidden" name="buyer_id" value="{{ $buyer->id }}">
                    <div class="col-12 col-md-3"><input name="code" class="form-control" placeholder="Code, e.g. SP27" required maxlength="50"></div>
                    <div class="col-12 col-md-6"><input name="name" class="form-control" placeholder="Name (optional), e.g. Ann Taylor OCR SP27" maxlength="120"></div>
                    <div class="col-12 col-md-3"><button class="btn btn-primary w-100"><i class="bi bi-check-lg me-1" aria-hidden="true"></i>Save Season</button></div>
                </form>

                @forelse($seasons as $s)
                    <a href="{{ route('merchant.styles.index', ['buyer' => $buyer->id, 'season' => $s->id]) }}"
                       class="btn btn-sm {{ $season && $season->id === $s->id ? 'btn-primary' : 'btn-outline-secondary' }} me-1 mb-1">
                        {{ $s->code }} <span class="opacity-75">({{ $s->motherStyles->count() }})</span>
                    </a>
                @empty
                    <p class="text-muted mb-0">No season yet. Add the first season for this buyer.</p>
                @endforelse
            </x-card>

            {{-- Mother / child styles --}}
            @if($season)
            <x-card :title="'Styles — '.$season->code" body-class="p-3">
                <form method="POST" action="{{ route('merchant.styles.mothers.store', $season) }}" class="row g-2 mb-3">
                    @csrf
                    <div class="col-12 col-md-6">
                        <textarea name="codes" rows="2" class="form-control @error('codes') is-invalid @enderror"
                                  placeholder="882635, 720193(PETITE), 748268 (866317)" required>{{ old('codes') }}</textarea>
                        <div class="form-text">One or many. Text in brackets becomes a child style.</div>
                        @error('codes')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-12 col-md-3">
                        <input name="product_group" class="form-control" list="productGroups" placeholder="Product group" maxlength="60">
                        <datalist id="productGroups"><option value="DENIM"><option value="WOVEN"><option value="KNIT"><option value="SWEATER"></datalist>
                    </div>
                    <div class="col-12 col-md-3">
                        <button class="btn btn-primary w-100"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add Styles</button>
                    </div>
                </form>

                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead><tr><th>Mother Style</th><th>Group</th><th>Child Styles</th><th class="text-end">Action</th></tr></thead>
                        <tbody>
                        @forelse($season->motherStyles as $m)
                            <tr>
                                <td class="fw-semibold">{{ $m->style_no }}</td>
                                <td>{{ $m->product_group ?: '—' }}</td>
                                <td>
                                    @foreach($m->children as $c)
                                        <span class="badge bg-light text-dark border me-1">
                                            {{ $c->style_no }} <small class="text-muted">· {{ \App\Models\ChildStyle::KINDS[$c->kind] ?? $c->kind }}</small>
                                            <form method="POST" action="{{ route('merchant.styles.children.destroy', $c) }}" class="d-inline" onsubmit="return confirm('Delete this child style?')">
                                                @csrf @method('DELETE')
                                                <button class="btn btn-link btn-sm p-0 ms-1 text-danger text-decoration-none">Delete</button>
                                            </form>
                                        </span>
                                    @endforeach
                                    <form method="POST" action="{{ route('merchant.styles.children.store', $m) }}" class="d-inline-flex gap-1 mt-1">
                                        @csrf
                                        <input name="style_no" class="form-control form-control-sm" style="width:120px" placeholder="Child style" required maxlength="80">
                                        <select name="kind" class="form-select form-select-sm" style="width:130px">
                                            @foreach(\App\Models\ChildStyle::KINDS as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach
                                        </select>
                                        <button class="btn btn-outline-primary btn-sm"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add</button>
                                    </form>
                                </td>
                                <td class="text-end">
                                    <form method="POST" action="{{ route('merchant.styles.mothers.destroy', $m) }}" onsubmit="return confirm('Delete this style and its child styles?')">
                                        @csrf @method('DELETE')
                                        <button class="btn btn-outline-danger btn-sm"><i class="bi bi-trash me-1" aria-hidden="true"></i>Delete</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-muted text-center py-3">No styles in this season yet.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </x-card>
            @endif
        </div>
    </div>
    @endif
</div>
@endsection
