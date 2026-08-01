{{-- Full-page New / Edit Bulk Issue.

     The same form the index page's slide-in panel renders — both include
     _bulk-issue-form, so there is one form, not a page and a panel that drift.
     What differs is only the shell: this one uses the whole browser width and
     has its own URL, so it can be linked, bookmarked and reached with Back.

     The panel is still on the index page while this route is being verified;
     removing it is a separate, approved step.

     Expects: $hasBookingPos, $requisitions, $sections, $editing, $issue. --}}
@extends('layouts.app')

@section('title', $editing ? 'Material Stock — Edit Bulk Issue' : 'Material Stock — New Bulk Issue')

@section('styles')
<style>
    @include('store.material-stock._bulk-issue-form-styles')

    /* --- Full-page shell --------------------------------------------------
       The form partial was written for a panel, which carried its own canvas
       and gutters. On a page those come from here instead, so the partial
       itself did not have to change. */
    .bi-page { background: #F8FAFC; border: 1px solid #E9EDF3; border-radius: 18px; padding: 1.25rem 1.5rem 0; }
    .bi-page .bi-card { margin-bottom: 1.15rem; }
    /* The sticky bar is full-bleed inside the page card, as it was in the panel. */
    .bi-page .bi-wizard-bar { margin-inline: -1.5rem; padding-inline: 1.5rem; border-radius: 0 0 18px 18px; }

    /* Module tabs. Same segmented control the listing uses for its period
       filter, so the two levels of navigation read as one system. */
    .bi-modetabs { display: inline-flex; gap: .15rem; padding: .25rem; background: #F1F5F9; border: 1px solid #E9EDF3; border-radius: 12px; }
    .bi-modetab {
        display: inline-flex; align-items: center; gap: .4rem; padding: .45rem .9rem; border-radius: 9px;
        font-size: .8125rem; font-weight: 600; letter-spacing: -.01em; color: #64748B; text-decoration: none;
        transition: background-color .18s ease, color .18s ease, box-shadow .18s ease;
    }
    .bi-modetab:hover { color: var(--gx-primary, #0F172A); }
    .bi-modetab.active {
        background: #fff; color: var(--gx-primary, #0F172A);
        box-shadow: 0 1px 2px rgba(15, 23, 42, .06), 0 2px 8px -4px rgba(15, 23, 42, .18);
    }

    /* On a page the form has room to put the four quantity fields and the
       summary facts on one line, which the panel never had. */
    @media (min-width: 992px) {
        .bi-page { padding-inline: 2rem; }
        .bi-page .bi-wizard-bar { margin-inline: -2rem; padding-inline: 2rem; }
    }
    @media (max-width: 575.98px) {
        .bi-page { padding-inline: 1rem; border-radius: 14px; }
        .bi-page .bi-wizard-bar { margin-inline: -1rem; padding-inline: 1rem; }
    }
</style>
@endsection

@section('content')
<div class="container-fluid">
    <x-breadcrumb :items="[
        ['label' => 'Store', 'url' => route('store.dashboard')],
        ['label' => 'Buyer / Style Stock'],
        ['label' => 'Bulk Issuing', 'url' => route('store.material.bulk-issues.index')],
        ['label' => $editing ? 'Edit' : 'New'],
    ]" />

    <div class="app-hero-card p-4 mb-4">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div class="d-flex align-items-center gap-3">
                <span class="app-stat-icon" style="width:46px;height:46px;border-radius:15px;font-size:20px;"><i class="bi bi-box-arrow-up" aria-hidden="true"></i></span>
                <div>
                    <div class="app-hero-eyebrow">Buyer / Style Stock</div>
                    <h3 class="app-hero-title mb-0">{{ $editing ? 'Edit Bulk Issue' : 'New Bulk Issue' }}</h3>
                    <p class="app-hero-copy mb-0">Select the PO, choose the item lines, then split each into Bulk / Sample / Liability / Dead.</p>
                </div>
            </div>

            {{-- Module tabs: the history and the entry form are two places, not
                 two modes of one screen, so each has its own URL. --}}
            <nav class="bi-modetabs" aria-label="Bulk Issuing sections">
                <a href="{{ route('store.material.bulk-issues.index') }}" class="bi-modetab">
                    <i class="bi bi-clock-history" aria-hidden="true"></i>Issue History
                </a>
                <span class="bi-modetab active" aria-current="page">
                    <i class="bi bi-plus-lg" aria-hidden="true"></i>{{ $editing ? 'Edit Issue' : 'New Bulk Issue' }}
                </span>
            </nav>
        </div>
    </div>

    @include('store._flash')

    @if(! $hasBookingPos)
        <div class="alert alert-warning">No booking POs exist yet, so nothing can be issued.</div>
    @else
        {{-- Every section on one page, top to bottom: Select PO, Issue
             Quantities, Indent Details, Remarks, then the sticky action bar.
             The gating (sections 2-4 locked until a PO is chosen) comes from the
             form partial itself, so it behaves identically in both shells. --}}
        <div class="bi-page" x-data="bulkIssueWizard" @keydown="onKeydown($event)"
             @if($editing) data-bi-edit-id="{{ $issue->id }}" @endif>
            @include('store.material-stock._bulk-issue-form')
        </div>
    @endif
</div>

@if($hasBookingPos)
    @include('store.material-stock._bulk-issue-item-picker')
@endif

{{-- The form module reads its routes from here, exactly as the index page does. --}}
<script type="application/json" id="bi-config">
    {!! json_encode([
        'routes' => [
            'poSearch' => route('store.material.bulk-issues.po-search'),
            'poItems' => route('store.material.bulk-issues.po-items', ['bookingPo' => '__ID__']),
            'poDetails' => route('store.material.bulk-issues.po-details', ['bookingPo' => '__ID__']),
            'store' => route('store.material.bulk-issues.store'),
            'update' => route('store.material.bulk-issues.update', ['materialBulkIssue' => '__ID__']),
            'show' => route('store.material.bulk-issues.show', ['materialBulkIssue' => '__ID__']),
            'index' => route('store.material.bulk-issues.index'),
        ],
    ]) !!}
</script>
@endsection
