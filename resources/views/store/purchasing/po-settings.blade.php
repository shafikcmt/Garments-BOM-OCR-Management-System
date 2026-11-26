@extends('layouts.app')

@section('title', 'Purchase Order Setup')

@section('content')
<div class="container-fluid gx-stock-scope">
    <x-breadcrumb :items="[['label' => 'General Stock'], ['label' => 'Purchasing'], ['label' => 'Internal POs', 'url' => route('store.purchasing.po.index')], ['label' => 'Setup']]" />

    @include('store.stock._stock-ui')

    <x-page-header icon="sliders" eyebrow="General Stock Purchasing" title="Purchase Order Setup"
                   copy="What every new purchase order is printed with, and who gives each purchase approval. Existing purchase orders are not changed." />

    <x-flash />

    {{-- 1. PO FORMAT --}}
    <form method="POST" action="{{ route('store.purchasing.po.settings.format') }}" class="card gx-stock-card mb-4">
        @csrf
        <div class="gx-stock-card-body">
            <h6 class="fw-bold mb-1">PO format (default text)</h6>
            <div class="small text-muted mb-3">These fill a new PO automatically. The person making the PO can still edit them on that PO while it is a draft.</div>

            <div class="row g-3">
                @foreach($fields as $key => $field)
                    @php($locked = ($field['admin_only'] ?? false) && ! $isAdmin)
                    <div class="col-12 col-md-6">
                        <label class="form-label fw-semibold" for="f_{{ $key }}">{{ $field['label'] }}</label>
                        @if($field['rows'] > 1)
                            <textarea id="f_{{ $key }}" name="{{ $key }}" rows="{{ $field['rows'] }}" maxlength="1000" class="form-control" @disabled($locked)>{{ old($key, $values[$key] ?? '') }}</textarea>
                        @else
                            <input type="text" id="f_{{ $key }}" name="{{ $key }}" maxlength="1000" class="form-control" value="{{ old($key, $values[$key] ?? '') }}" @disabled($locked)>
                        @endif
                        <div class="form-text">{{ $field['hint'] }}</div>
                    </div>
                @endforeach
            </div>

            <hr class="my-4">
            <h6 class="fw-bold mb-1">Signature box titles</h6>
            <div class="small text-muted mb-3">The two boxes at the bottom of the printed PO.</div>
            <div class="row g-3">
                <div class="col-12 col-md-6">
                    <label class="form-label fw-semibold" for="sign_checked">First approval box</label>
                    <input type="text" id="sign_checked" name="sign_checked" maxlength="60" class="form-control" value="{{ old('sign_checked', $signs['checked']) }}">
                </div>
                <div class="col-12 col-md-6">
                    <label class="form-label fw-semibold" for="sign_authorised">Second approval box</label>
                    <input type="text" id="sign_authorised" name="sign_authorised" maxlength="60" class="form-control" value="{{ old('sign_authorised', $signs['authorised']) }}">
                </div>
            </div>

            <div class="mt-4"><button class="btn btn-primary"><i class="bi bi-check2 me-1" aria-hidden="true"></i>Save PO format</button></div>
        </div>
    </form>

    {{-- 2. WHO APPROVES --}}
    <form method="POST" action="{{ route('store.purchasing.po.settings.approvers') }}" class="card gx-stock-card">
        @csrf
        <div class="gx-stock-card-body">
            <h6 class="fw-bold mb-1">Who gives each purchase approval</h6>
            <div class="small text-muted mb-3">Tick the people for each approval point. This is the same switch as Portal Access on the user's profile. The person who made a document can never approve it, whatever is ticked here. People marked "role" hold the access through their role and are changed on the role.</div>

            <div class="row g-3">
                @foreach($points as $key => $point)
                    <div class="col-12 col-xl-6">
                        <div class="border rounded-3 p-3 h-100">
                            <div class="fw-semibold">{{ $point['label'] }}</div>
                            <div class="small text-muted mb-2">{{ $point['description'] }}</div>
                            @unless($point['mayEdit'])
                                <div class="small text-warning mb-2">You do not hold this access yourself, so you cannot change who has it.</div>
                            @endunless
                            <div style="max-height:220px;overflow:auto;" class="border rounded-2 p-2">
                                @foreach($users as $u)
                                    @php($has = in_array($u->id, $point['holders'], true))
                                    @php($role = in_array($u->id, $point['viaRole'], true))
                                    <label class="d-flex align-items-center gap-2 py-1 small">
                                        <input type="checkbox" class="form-check-input mt-0" name="approvers[{{ $key }}][]" value="{{ $u->id }}"
                                               @checked($has) @disabled(! $point['mayEdit'] || $role)>
                                        @if($role)<input type="hidden" name="approvers[{{ $key }}][]" value="{{ $u->id }}">@endif
                                        <span>{{ $u->name }}@if($u->designation) <span class="text-muted">· {{ $u->designation }}</span>@endif @if($role)<span class="badge text-bg-light border">role</span>@endif</span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="mt-4"><button class="btn btn-primary"><i class="bi bi-check2 me-1" aria-hidden="true"></i>Save approvers</button></div>
        </div>
    </form>
</div>
@endsection
