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
    /* The action bar is full-bleed inside the page card, as it was in the
       panel. The bleed now comes from .bi-bottom, which wraps both it and the
       indent header — applying it twice would double the negative margin. */
    .bi-page .bi-wizard-bar { border-radius: 0 0 18px 18px; }

    /* Compact page header — the matrix below needs the vertical room. */
    /* Was .6rem when a tab row sat under it; with the card following directly
       the header needs the gap the tab row's rule used to provide. */
    .bi-pagehead { display: flex; align-items: center; gap: 1rem; margin-bottom: 1rem; }
    .bi-pagehead-icon {
        flex: none; width: 34px; height: 34px; border-radius: 10px;
        display: inline-flex; align-items: center; justify-content: center;
        background: var(--gx-secondary-bg, #DBEAFE); color: var(--gx-secondary-700, #1D4ED8); font-size: 15px;
    }
    .bi-pagehead-title { font-size: 1.05rem; font-weight: 700; letter-spacing: -.015em; color: var(--gx-primary, #0F172A); margin: 0; }
    .bi-pagehead-sub { font-size: .76rem; color: #64748B; }

    /* Indent header + Save pinned to the foot of the window, so both stay in
       reach however far down the matrix is scrolled. Sticky rather than fixed:
       fixed would need to know where the app's sidebar ends, and would sit on
       top of it at every breakpoint where that guess was wrong. */
    .bi-page .bi-bottom {
        position: sticky; bottom: 0; z-index: 20;
        background: #F8FAFC; margin-inline: -1.5rem; padding-inline: 1.5rem;
        box-shadow: 0 -8px 16px -12px rgba(15, 23, 42, .25);
    }
    .bi-page .bi-bottom .bi-card { margin-bottom: .6rem; }

    /* On a page the form has room to put the four quantity fields and the
       summary facts on one line, which the panel never had. */
    @media (min-width: 992px) {
        .bi-page { padding-inline: 2rem; }
        .bi-page .bi-bottom { margin-inline: -2rem; padding-inline: 2rem; }
    }
    @media (max-width: 575.98px) {
        .bi-page { padding-inline: 1rem; border-radius: 14px; }
        .bi-page .bi-bottom { margin-inline: -1rem; padding-inline: 1rem; }
        /* Nothing is pinned on a phone: a sticky six-field header plus a save
           bar would leave almost no window for the matrix itself. */
        .bi-page .bi-bottom { position: static; box-shadow: none; }
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

    {{-- Compact header. The matrix below needs the vertical room, so the page
         states what it is in one line rather than a hero block. --}}
    <div class="bi-pagehead">
        <div class="d-flex align-items-center gap-2 min-w-0">
            <span class="bi-pagehead-icon"><i class="bi bi-box-arrow-up" aria-hidden="true"></i></span>
            <div class="min-w-0">
                {{-- Same words as the button on the list page that leads here,
                     and as the slide-in panel's title. One name for one thing. --}}
                <h1 class="bi-pagehead-title">{{ $editing ? 'Edit Bulk Issue' : 'New Bulk Issue' }}</h1>
                <p class="bi-pagehead-sub mb-0">Select a PO, then enter the quantities to issue.</p>
            </div>
        </div>
    </div>

    {{-- No period tabs here. They belong to the history listing, which is one
         breadcrumb hop away; repeating them on the entry form would be a second
         copy of the same navigation inside a flow the user is already in. --}}

    @include('store._flash')

    @if(! $hasBookingPos)
        <div class="alert alert-warning">No POs available to issue against.</div>
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

{{-- One dialog: the Purchase Order and the row filters live in it together.
     The item picker it replaced is gone — a PO's lines load straight into the
     matrix now. --}}
@if($hasBookingPos)
    @include('store.material-stock._bulk-issue-filter-modal')
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
