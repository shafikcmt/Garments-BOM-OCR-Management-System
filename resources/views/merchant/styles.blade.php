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
                   copy="Your style master: buyer, season, style and linked child styles, with each style's colours, orders, quantity, ship date, materials, costing and samples." />

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
                    <table class="table table-sm align-middle mb-0" style="min-width:0">
                        <thead><tr>
                            <th>Style</th><th>Group</th><th class="text-end">Colours</th><th class="text-end">POs</th><th class="text-end">Order qty</th>
                            <th>Ship date</th><th class="text-end">BOM lines</th><th>Costing</th><th>Samples</th><th class="text-end">Action</th>
                        </tr></thead>
                        <tbody>
                        @forelse($season->motherStyles as $m)
                            @php($st = $stats[$m->id] ?? null)
                            <tr>
                                <td style="min-width:150px">
                                    <div class="fw-semibold">{{ $m->style_no }} @if($m->order_lines_count)<span class="badge bg-success-subtle text-success ms-1">{{ $m->order_lines_count }} order line(s)</span>@endif</div>
                                    @foreach($m->children as $c)
                                        <span class="badge bg-light text-dark border me-1">
                                            {{ $c->style_no }} <small class="text-muted">· {{ \App\Models\ChildStyle::KINDS[$c->kind] ?? $c->kind }}</small>
                                            <form method="POST" action="{{ route('merchant.styles.children.destroy', $c) }}" class="d-inline" onsubmit="return confirm('Delete this child style?')">
                                                @csrf @method('DELETE')
                                                <button class="btn btn-link btn-sm p-0 ms-1 text-danger text-decoration-none">Delete</button>
                                            </form>
                                        </span>
                                    @endforeach
                                    <button type="button" class="btn btn-link btn-sm p-0 mt-1 text-decoration-none" data-bs-toggle="collapse" data-bs-target="#addChild{{ $m->id }}" aria-expanded="false">
                                        <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add Child Style
                                    </button>
                                    <form id="addChild{{ $m->id }}" method="POST" action="{{ route('merchant.styles.children.store', $m) }}" class="collapse mt-1">
                                        @csrf
                                        <div class="d-flex flex-column gap-1">
                                            <input name="style_no" class="form-control form-control-sm" placeholder="Child style no." required maxlength="80" aria-label="Child style of {{ $m->style_no }}">
                                            <select name="kind" class="form-select form-select-sm" aria-label="Kind">
                                                @foreach(\App\Models\ChildStyle::KINDS as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach
                                            </select>
                                            <button class="btn btn-outline-primary btn-sm"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add</button>
                                        </div>
                                    </form>
                                </td>
                                <td>{{ $m->product_group ?: '—' }}</td>
                                <td class="text-end">{{ $st && $st['colours'] ? $st['colours'] : '—' }}</td>
                                <td class="text-end">{{ $st && $st['pos'] ? $st['pos'] : '—' }}</td>
                                <td class="text-end">{{ $st && $st['qty'] > 0 ? number_format($st['qty']) : '—' }}</td>
                                <td class="text-nowrap">{{ $st && $st['ship'] ? $st['ship']->format('d M Y') : '—' }}@if($st && $st['ship_passed'])<div class="small text-muted">passed</div>@endif</td>
                                <td class="text-end">{{ $st && $st['materials'] ? number_format($st['materials']) : '—' }}</td>
                                <td>
                                    @if($st && $st['has_costing'])
                                        @if($st['margin'] !== null)<span class="badge {{ $st['margin'] < 0 ? 'bg-danger-subtle text-danger' : 'bg-success-subtle text-success' }}">{{ $st['margin'] }}% margin</span>@else<span class="badge bg-light text-dark border">Final</span>@endif
                                    @else<span class="text-muted">—</span>@endif
                                </td>
                                <td>
                                    @if($st && $st['open_samples'] > 0)
                                        <span class="badge bg-warning-subtle text-warning-emphasis">{{ $st['open_samples'] }} open</span>@if($st['late_samples'] > 0)<span class="badge bg-danger-subtle text-danger ms-1">{{ $st['late_samples'] }} late</span>@endif
                                    @else<span class="text-muted">—</span>@endif
                                </td>
                                <td class="text-end">
                                  <div class="d-flex flex-column gap-1 align-items-stretch">
                                    <a href="{{ route('merchant.styles.mothers.show', $m) }}" class="btn btn-outline-primary btn-sm">
                                        <i class="bi bi-bar-chart-steps me-1" aria-hidden="true"></i>View Status
                                    </a>
                                    <form method="POST" action="{{ route('merchant.styles.mothers.destroy', $m) }}" onsubmit="return confirm('Delete this style and its child styles?')">
                                        @csrf @method('DELETE')
                                        <button class="btn btn-outline-danger btn-sm w-100"><i class="bi bi-trash me-1" aria-hidden="true"></i>Delete</button>
                                    </form>
                                  </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="10" class="text-muted text-center py-3">No styles in this season yet.</td></tr>
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
