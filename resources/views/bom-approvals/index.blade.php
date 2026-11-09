@extends('layouts.app')

@section('title', 'BOM Approvals')

@section('content')
<div class="container-fluid">
    <x-page-header data-aos="fade-down" icon="patch-check" eyebrow="Merchandising"
                   title="BOM Approvals"
                   copy="A style's BOM is checked and approved before it is ordered." />

    <x-flash />

    @if($isAdmin)
        <x-card title="Settings" class="mb-3" body-class="p-3">
            <form method="POST" action="{{ route('bom_approvals.settings') }}" class="row g-3 align-items-center">
                @csrf
                <div class="col-12 col-md-4 form-check ms-2">
                    <input type="checkbox" class="form-check-input" id="setMgmt" name="management_stage" value="1" {{ $managementStage ? 'checked' : '' }}>
                    <label class="form-check-label" for="setMgmt">Second approval by Management</label>
                </div>
                <div class="col-12 col-md-5 form-check ms-2">
                    <input type="checkbox" class="form-check-input" id="setPo" name="required_for_po" value="1" {{ $requiredForPo ? 'checked' : '' }}>
                    <label class="form-check-label" for="setPo">Supply Chain cannot generate a PO until the BOM is approved</label>
                </div>
                <div class="col-12 col-md-6 form-check ms-2">
                    <input type="checkbox" class="form-check-input" id="setOrderPo" name="order_required_for_po" value="1" {{ $orderRequiredForPo ? 'checked' : '' }}>
                    <label class="form-check-label" for="setOrderPo">Supply Chain cannot generate a PO until the buyer order (customer PO) is approved</label>
                </div>
                <div class="col-auto"><button class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1" aria-hidden="true"></i>Save Settings</button></div>
            </form>
            <div class="form-text mt-2">Styles that are not linked to an imported BOM (older files) are never blocked. The buyer order is approved on the style's Buyer Orders page; switching this on blocks every customer PO that is not approved yet, including orders that were never registered there.</div>
        </x-card>
    @endif

    <div class="btn-group mb-3" role="group">
        <a href="{{ route('bom_approvals.index') }}" class="btn btn-sm {{ $tab === 'waiting' ? 'btn-primary' : 'btn-outline-secondary' }}">Waiting for me ({{ $waiting->count() }})</a>
        @if($canSeeAll)<a href="{{ route('bom_approvals.index', ['tab' => 'all']) }}" class="btn btn-sm {{ $tab === 'all' ? 'btn-primary' : 'btn-outline-secondary' }}">All BOMs</a>@endif
    </div>

    @php($rows = $tab === 'all' ? $all : $waiting)
    <x-card body-class="p-3">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead><tr><th>Style</th><th>Buyer / Season</th><th>Status</th><th>Submitted by</th><th>Submitted</th><th></th></tr></thead>
                <tbody>
                @forelse($rows as $a)
                    @php($m = $a->motherStyle)
                    <tr>
                        <td class="fw-semibold">{{ $m->style_no }}</td>
                        <td>{{ $m->buyer?->buyer_name }} · {{ $m->season?->code }}</td>
                        <td>@include('bom-approvals._badge', ['state' => $service->state($m, $a), 'approval' => $a])</td>
                        <td>{{ $a->submitter?->name ?: '—' }}</td>
                        <td>{{ $a->submitted_at?->format('d M Y') ?: '—' }}</td>
                        <td class="text-end"><a href="{{ route('merchant.status.style', $m) }}" class="btn btn-outline-primary btn-sm"><i class="bi bi-eye me-1" aria-hidden="true"></i>Open Style</a></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted py-4">{{ $tab === 'all' ? 'No BOM has been submitted yet.' : 'Nothing is waiting for you.' }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </x-card>
</div>
@endsection
