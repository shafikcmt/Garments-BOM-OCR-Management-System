@extends('layouts.app')

@section('title', 'Item SKU Settings')

@section('content')
<div class="container-fluid">
    <x-breadcrumb :items="[
        ['label' => 'Admin', 'url' => route('admin.dashboard')],
        ['label' => 'Settings'],
        ['label' => 'Item SKU'],
    ]" />

    <div class="app-hero-card p-4 mb-4">
        <div class="d-flex align-items-center gap-3">
            <span class="app-stat-icon" style="width:46px;height:46px;border-radius:15px;font-size:20px;"><i class="bi bi-upc-scan" aria-hidden="true"></i></span>
            <div>
                <div class="app-hero-eyebrow">Admin / Settings</div>
                <h3 class="app-hero-title mb-0">Item SKU</h3>
                <p class="app-hero-copy mb-0">The code every General Stock item carries. Given once, never changed — a new format or category code applies to new items only.</p>
            </div>
        </div>
    </div>

    <x-flash />

    <div class="row g-4">
        {{-- Format --}}
        <div class="col-12 col-xl-6">
            <div class="card h-100">
                <div class="card-body">
                    <h5 class="mb-1">SKU Format</h5>
                    <p class="small text-muted mb-3">
                        Tokens: <code>{CAT:n}</code> category code, <code>{NAME:n}</code> item name, <code>{BRAND:n}</code> brand,
                        <code>{SEQ:n}</code> running number (exactly one). Letters only, uppercase; short values are padded with X.
                        The number runs per prefix — everything but <code>{SEQ}</code>.
                    </p>
                    <form method="POST" action="{{ route('admin.item-sku.pattern') }}">
                        @csrf
                        <label class="form-label fw-semibold" for="skuPattern">Format <span class="text-danger">*</span></label>
                        <div class="d-flex gap-2">
                            <input type="text" name="pattern" id="skuPattern" class="form-control @error('pattern') is-invalid @enderror"
                                   value="{{ old('pattern', $pattern) }}" maxlength="120" autocomplete="off">
                            <button type="submit" class="btn btn-primary text-nowrap"><i class="bi bi-save me-1" aria-hidden="true"></i>Save Format</button>
                        </div>
                        @error('pattern')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        <div class="form-text">Default: <code>{{ $defaultPattern }}</code></div>
                    </form>

                    <h6 class="mt-4 mb-2">Preview</h6>
                    <table class="table table-sm mb-0">
                        <thead><tr><th>Item</th><th>Brand</th><th class="text-end">SKU</th></tr></thead>
                        <tbody id="skuPreview">
                            @foreach($samples as $s)
                                <tr data-name="{{ $s['name'] }}" data-brand="{{ $s['brand'] }}" data-code="{{ $s['code'] }}">
                                    <td class="small">{{ $s['name'] }}</td>
                                    <td class="small text-muted">{{ $s['brand'] ?: '—' }}</td>
                                    <td class="text-end fw-semibold js-sku-sample">—</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    <div class="small text-muted mt-1" id="skuPreviewNote">Numbers in the preview start at 1; real ones continue each prefix.</div>
                </div>
            </div>
        </div>

        {{-- Existing items --}}
        <div class="col-12 col-xl-6">
            <div class="card h-100">
                <div class="card-body">
                    <h5 class="mb-1">Existing Items</h5>
                    <p class="small text-muted mb-3">
                        {{ number_format($withSku) }} item(s) have an SKU; <strong>{{ number_format($missing) }}</strong> do not.
                        New items get one automatically when they are created.
                    </p>

                    <ol class="small mb-3 ps-3">
                        <li>Download the preview and have the Store team check it. Nothing is changed.</li>
                        <li>Then generate: the SKUs are given in the preview's order and never change afterwards.</li>
                    </ol>

                    <div class="d-flex flex-wrap gap-2 mb-3">
                        <a href="{{ route('admin.item-sku.preview') }}" class="btn btn-outline-primary">
                            <i class="bi bi-file-earmark-spreadsheet me-1" aria-hidden="true"></i>Download Preview
                        </a>
                    </div>

                    <form method="POST" action="{{ route('admin.item-sku.apply') }}"
                          onsubmit="return confirm('Give {{ $missing }} item(s) their SKU now? SKUs never change afterwards.');">
                        @csrf
                        <div class="form-check mb-2">
                            <input class="form-check-input @error('confirm') is-invalid @enderror" type="checkbox" name="confirm" value="1" id="skuConfirm">
                            <label class="form-check-label small" for="skuConfirm">The Store team has checked the preview.</label>
                            @error('confirm')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        </div>
                        <button type="submit" class="btn btn-danger" @disabled($missing === 0)>
                            <i class="bi bi-upc me-1" aria-hidden="true"></i>Generate Missing SKUs
                        </button>
                    </form>
                    <div class="form-text mt-2">Same as <code>php artisan stock:generate-skus</code> (use <code>--dry-run</code> for the preview).</div>
                </div>
            </div>
        </div>

        {{-- Category codes --}}
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <h5 class="mb-1">Category Codes</h5>
                    <p class="small text-muted mb-3">
                        The {CAT} part of the SKU. Categories of the same kind may share a code on purpose; every other clash
                        is flagged below. Items with no category use <strong>{{ \App\Services\StockItemSku::NO_CATEGORY }}</strong>
                        ({{ $noCategoryItems }} item(s)). A change applies to new SKUs only.
                    </p>

                    @if($clashes->isNotEmpty())
                        <div class="alert alert-warning small py-2">
                            <strong class="d-block mb-1">Codes shared by more than one category — check each is intended:</strong>
                            @foreach($clashes as $code => $names)
                                <span class="d-block"><strong>{{ $code }}</strong>: {{ implode(' · ', $names) }}</span>
                            @endforeach
                        </div>
                    @endif

                    <form method="POST" action="{{ route('admin.item-sku.codes') }}">
                        @csrf
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-3">
                                <thead>
                                    <tr><th>Category</th><th class="text-end">Items</th><th style="width:140px;">Code</th><th>Note</th></tr>
                                </thead>
                                <tbody>
                                    @foreach($categories as $c)
                                        @php($shared = $clashes->get($c['effective']))
                                        <tr>
                                            <td>{{ $c['name'] }}</td>
                                            <td class="text-end">{{ number_format($c['items']) }}</td>
                                            <td>
                                                <input type="text" name="codes[{{ $c['id'] }}]" value="{{ old('codes.'.$c['id'], $c['code']) }}"
                                                       class="form-control form-control-sm text-uppercase @error('codes.'.$c['id']) is-invalid @enderror"
                                                       maxlength="6" placeholder="{{ $c['effective'] }}" aria-label="Code for {{ $c['name'] }}">
                                            </td>
                                            <td class="small">
                                                @if(! $c['code'])
                                                    <span class="text-muted">No code set — uses {{ $c['effective'] }}</span>
                                                @elseif($shared)
                                                    <span class="text-warning-emphasis">Shared with {{ implode(', ', array_diff($shared, [$c['name']])) }}</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        @error('codes.*')<div class="text-danger small mb-2">{{ $message }}</div>@enderror
                        <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1" aria-hidden="true"></i>Save Codes</button>
                    </form>
                </div>
            </div>
        </div>

        {{-- Corrections log --}}
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <h5 class="mb-1">SKU Corrections</h5>
                    <p class="small text-muted mb-3">An SKU changes only when Admin corrects it from the Items screen, with a reason.</p>
                    @if($changes->isEmpty())
                        <p class="small text-muted mb-0">No corrections yet.</p>
                    @else
                        <div class="table-responsive">
                            <table class="table table-sm mb-0">
                                <thead><tr><th>Date</th><th>Old</th><th>New</th><th>Reason</th><th>By</th></tr></thead>
                                <tbody>
                                    @foreach($changes as $ch)
                                        <tr>
                                            <td class="small text-nowrap">{{ \Illuminate\Support\Carbon::parse($ch->created_at)->format('d M Y, h:i A') }}</td>
                                            <td class="small">{{ $ch->old_sku ?: '—' }}</td>
                                            <td class="small fw-semibold">{{ $ch->new_sku }}</td>
                                            <td class="small">{{ $ch->reason }}</td>
                                            <td class="small">{{ $ch->user_name ?: '—' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Live preview: the same rules as StockItemSku, so the table matches what
     will be generated (numbers start at 1 here). --}}
<script>
    (function () {
        var input = document.getElementById('skuPattern');
        var rows = document.querySelectorAll('#skuPreview tr');
        var TOKEN = /\{(CAT|NAME|BRAND|SEQ):(\d{1,2})\}/g;

        function letters(value, width) {
            var s = String(value || '').replace(/[^A-Za-z]/g, '').toUpperCase().slice(0, width);
            while (s.length < width) { s += 'X'; }
            return s;
        }

        function render(pattern, row, seq) {
            var seqs = (pattern.match(/\{SEQ:\d{1,2}\}/g) || []).length;
            if (seqs !== 1 || /[{}]/.test(pattern.replace(TOKEN, ''))) { return null; }

            return pattern.replace(TOKEN, function (all, token, width) {
                width = parseInt(width, 10);
                if (token === 'CAT') { return letters(row.dataset.code, width); }
                if (token === 'NAME') { return letters(row.dataset.name, width); }
                if (token === 'BRAND') { return letters(row.dataset.brand, width); }
                var n = String(seq);
                while (n.length < width) { n = '0' + n; }
                return n;
            });
        }

        function update() {
            var counters = {};
            rows.forEach(function (row) {
                var prefix = render(input.value, row, 0);
                var cell = row.querySelector('.js-sku-sample');
                if (prefix === null) { cell.textContent = '—'; return; }
                counters[prefix] = (counters[prefix] || 0) + 1;
                cell.textContent = render(input.value, row, counters[prefix]);
            });
        }

        input.addEventListener('input', update);
        update();
    })();
</script>
@endsection
