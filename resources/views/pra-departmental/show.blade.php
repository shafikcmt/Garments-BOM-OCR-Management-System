@extends('layouts.app')

@section('title', 'PRA ' . $paymentRequest->request_no)

@section('styles')
@include('supply-chain.bookings.partials._po-module-theme')
<style>
    /* --- Request Details: a definition grid, hairline-separated ------------ */
    .pra-review .pra-facts {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        border: 1px solid var(--po-line-soft);
        border-radius: var(--po-r-control);
        overflow: hidden;
    }
    .pra-review .pra-fact {
        padding: 12px 14px;
        border-right: 1px solid var(--po-line-soft);
        border-bottom: 1px solid var(--po-line-soft);
        min-width: 0;
    }
    .pra-review .pra-fact:nth-child(3n) { border-right: 0; }
    .pra-review .pra-fact.is-wide { grid-column: 1 / -1; border-right: 0; }
    .pra-review .pra-fact.is-total { background: var(--po-paper); }
    .pra-review .pra-fact-label { font-size: 10px; font-weight: 800; letter-spacing: .09em; text-transform: uppercase; color: var(--po-muted); margin-bottom: 3px; }
    .pra-review .pra-fact-value { font-size: 13.5px; font-weight: 600; color: var(--po-ink); overflow-wrap: anywhere; }
    .pra-review .pra-fact-value.po-num { text-align: left; font-size: 16px; font-weight: 800; color: var(--po-blue-deep); }
    @media (max-width: 767.98px) {
        .pra-review .pra-facts { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .pra-review .pra-fact:nth-child(3n) { border-right: 1px solid var(--po-line-soft); }
        .pra-review .pra-fact:nth-child(2n) { border-right: 0; }
    }

    /* --- Booking PO ------------------------------------------------------------ */
    .pra-review .pra-po { border: 1px solid var(--po-line-soft); border-radius: var(--po-r-control); overflow: hidden; }
    .pra-review .pra-po-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        flex-wrap: wrap;
        padding: 10px 14px;
        background: var(--po-paper);
        border-bottom: 1px solid var(--po-line-soft);
    }
    .pra-review .pra-po-no { font-size: 14px; font-weight: 800; color: var(--po-blue-deep); letter-spacing: .02em; }
    .pra-review .pra-po .po-btn-quiet { min-height: 32px; padding: 0 12px; font-size: 12.5px; }

    /* --- Approval History ---------------------------------------------------- */
    .pra-review .pra-history .po-panel-body { padding: 0; }
    .pra-review .pra-step-no {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 28px;
        height: 28px;
        border-radius: 8px;
        background: var(--po-tint);
        color: var(--po-blue-deep);
        font-weight: 800;
        font-size: 12.5px;
    }
    .pra-review .pra-history-sub { font-size: 11.5px; color: var(--po-muted); margin-top: 2px; }
    .pra-review .pra-history-comment { color: var(--po-ink); font-weight: 500; max-width: 320px; }

    /* --- Decision rail --------------------------------------------------------
       Two registers, kept visibly apart: INFORMATION (the step, who may act)
       sits on paper; the DECISION (comment, assignment, buttons) sits on the
       surface below a labelled divider. A reader should never wonder which
       part of the panel does something. */
    .pra-review .pra-step-card {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 12px 14px;
        border: 1px solid #BAE0F2;
        border-radius: var(--po-r-control);
        background: var(--po-tint);
        margin-bottom: 14px;
    }
    .pra-review .pra-step-card .pra-step-no { width: 36px; height: 36px; font-size: 15px; background: var(--po-blue); color: #fff; flex: 0 0 auto; }
    .pra-review .pra-step-card .pra-step-title { font-size: 14px; font-weight: 800; color: var(--po-blue-deep); line-height: 1.2; }
    .pra-review .pra-step-card .pra-step-sub { font-size: 12px; color: var(--po-muted); margin-top: 2px; }

    .pra-review .pra-info-block {
        padding: 12px;
        border: 1px solid var(--po-line-soft);
        border-radius: var(--po-r-control);
        background: var(--po-paper);
        margin-bottom: 14px;
    }
    .pra-review .pra-info-block .pra-fact-label { margin-bottom: 8px; }
    .pra-review .pra-roster { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 6px; }
    .pra-review .pra-roster li {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
        padding: 7px 10px;
        border: 1px solid var(--po-line-soft);
        border-radius: 8px;
        font-size: 13px;
        font-weight: 600;
        color: var(--po-ink);
        background: var(--po-surface);
    }
    .pra-review .pra-roster li.is-primary { border-color: #BAE0F2; background: var(--po-tint); }
    .pra-review .pra-roster li.is-you { box-shadow: inset 3px 0 0 var(--po-blue); }

    /* Notes on the module's semantic tokens, replacing Bootstrap's alert palette. */
    .pra-review .pra-note {
        display: flex;
        align-items: flex-start;
        gap: 10px;
        padding: 10px 12px;
        border: 1px solid var(--po-info-border);
        border-radius: var(--po-r-control);
        background: var(--po-info-bg);
        color: var(--po-info);
        font-size: 13px;
        font-weight: 600;
        line-height: 1.4;
    }
    .pra-review .pra-note i { font-size: 16px; line-height: 1.2; flex: 0 0 auto; }
    .pra-review .pra-note-warn { border-color: var(--po-warn-border); background: var(--po-warn-bg); color: var(--po-warn); }
    .pra-review .pra-note-assigned { border-color: #BAE0F2; background: var(--po-tint); color: var(--po-blue-deep); font-weight: 700; }

    .pra-review .pra-decision-divider {
        display: flex;
        align-items: center;
        gap: 10px;
        margin: 18px 0 12px;
        font-size: 10px;
        font-weight: 800;
        letter-spacing: .09em;
        text-transform: uppercase;
        color: var(--po-muted);
    }
    .pra-review .pra-decision-divider::before,
    .pra-review .pra-decision-divider::after { content: ""; flex: 1; border-top: 1px solid var(--po-line); }

    .pra-review .pra-reject-block {
        margin-top: 14px;
        padding: 12px;
        border: 1px dashed var(--po-danger-border);
        border-radius: var(--po-r-control);
        background: var(--po-danger-bg);
    }
    .pra-review .pra-reject-block .po-field-label { color: var(--po-danger); }
    .pra-review .po-btn-danger-quiet {
        background: var(--po-surface);
        border: 1px solid var(--po-danger-border);
        color: var(--po-danger);
        font-weight: 800;
        border-radius: var(--po-r-control);
        min-height: 40px;
    }
    .pra-review .po-btn-danger-quiet:hover,
    .pra-review .po-btn-danger-quiet:focus { background: var(--po-danger); border-color: var(--po-danger); color: #fff; }
    .pra-review .po-form .form-text { font-size: 12px; color: var(--po-muted); }
</style>
@endsection

@section('content')
@php
    $user = auth()->user();
    $statusPill = match ($paymentRequest->status) {
        'completed' => 'po-pill-ok',
        'rejected' => 'po-pill-danger',
        default => 'po-pill-warn',
    };
@endphp
<div class="container-fluid po-scope pra-review">
    {{-- Back to the list this user actually has: the queue while it is their
         step, otherwise All PRAs (an admin has no queue at all). --}}
    <x-breadcrumb :items="[
        ['label' => 'PRA Approvals', 'url' => $canAct ? route('pra_departmental.index') : route('pra_departmental.all')],
        ['label' => $paymentRequest->request_no],
    ]" />

    <div class="po-head mb-3">
        <div class="d-flex align-items-center gap-3">
            <span class="po-head-icon"><i class="bi bi-file-earmark-text" aria-hidden="true"></i></span>
            <div>
                <div class="po-micro">Payment Request Approval</div>
                <h4 class="po-head-title">{{ $paymentRequest->request_no }}</h4>
            </div>
        </div>
        <div class="d-flex gap-2 flex-wrap align-items-center">
            @if($stage)
                <span class="po-pill po-pill-blue"><i class="bi bi-diagram-3" aria-hidden="true"></i>Step {{ $stage->sequence }} — {{ $stage->label }}</span>
                @if($stage->assignee)
                    <span class="po-pill"><i class="bi bi-person-check" aria-hidden="true"></i>Assigned to {{ $stage->isAssignee($user) ? 'you' : $stage->assignee->name }}</span>
                @endif
            @else
                <span class="po-pill {{ $statusPill }}">{{ ucfirst(str_replace('_', ' ', $paymentRequest->status)) }}</span>
            @endif
        </div>
    </div>

    @foreach (['success' => 'success', 'warning' => 'warning', 'error' => 'danger'] as $key => $variant)
        @if(session($key))
            <div class="alert alert-{{ $variant }} border-0 shadow-sm rounded-3 py-2">{{ session($key) }}</div>
        @endif
    @endforeach

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="po-panel mb-3">
                <div class="po-panel-head">
                    <div class="po-panel-head-title">
                        <span class="po-panel-icon"><i class="bi bi-receipt" aria-hidden="true"></i></span>
                        <div class="po-title">Request Details</div>
                    </div>
                </div>
                <div class="po-panel-body">
                    <div class="pra-facts">
                        <div class="pra-fact"><div class="pra-fact-label">Requested By</div><div class="pra-fact-value">{{ $paymentRequest->createdBy?->name ?? '—' }}</div></div>
                        <div class="pra-fact"><div class="pra-fact-label">Buyer</div><div class="pra-fact-value">{{ $paymentRequest->buyer_name ?: '—' }}</div></div>
                        <div class="pra-fact"><div class="pra-fact-label">Supplier</div><div class="pra-fact-value">{{ $paymentRequest->supplier_name ?: '—' }}</div></div>
                        <div class="pra-fact"><div class="pra-fact-label">Season</div><div class="pra-fact-value">{{ $paymentRequest->season_name ?: '—' }}</div></div>
                        <div class="pra-fact"><div class="pra-fact-label">Items</div><div class="pra-fact-value">{{ $paymentRequest->items->count() }}</div></div>
                        <div class="pra-fact is-total"><div class="pra-fact-label">Total PI Amount</div><div class="pra-fact-value po-num">{{ number_format((float) $paymentRequest->total_pi_amount, 2) }}</div></div>
                        @if($paymentRequest->remarks)
                            <div class="pra-fact is-wide"><div class="pra-fact-label">Remarks</div><div class="pra-fact-value fw-normal">{{ $paymentRequest->remarks }}</div></div>
                        @endif
                    </div>
                </div>
            </div>

            @include('partials.pra-documents')
            @include('partials.pra-removed-lines')

            {{-- THE BOOKING PO(S) THIS PRA PAYS FOR. Every viewer sees the summary
                 — it is built from the PRA's own lines and the PO's header, which
                 they are already looking at. The "Open Booking PO" button appears
                 only where this viewer could open that screen; the screen's own
                 guard remains the real gate. Legacy PRAs carry no booking_po_id
                 and so show nothing here. --}}
            @if($bookingPos->isNotEmpty())
                <div class="po-panel mb-3 pra-booking">
                    <div class="po-panel-head">
                        <div class="po-panel-head-title">
                            <span class="po-panel-icon"><i class="bi bi-file-earmark-ruled" aria-hidden="true"></i></span>
                            <div class="po-title">Booking PO</div>
                        </div>
                        <span class="po-pill">{{ $bookingPos->count() }} {{ \Illuminate\Support\Str::plural('PO', $bookingPos->count()) }}</span>
                    </div>
                    <div class="po-panel-body">
                        @foreach($bookingPos as $entry)
                            @php $po = $entry['po']; @endphp
                            <div class="pra-po {{ $loop->last ? '' : 'mb-3' }}">
                                <div class="pra-po-head">
                                    <div>
                                        <div class="pra-po-no">{{ $po->po_no }}</div>
                                        <div class="pra-history-sub">
                                            {{ $po->vendor_name ?: '—' }}
                                            @if($po->buyer_name) · {{ $po->buyer_name }}@endif
                                            @if($po->generated_at) · Generated {{ $po->generated_at->format('d M Y') }}@endif
                                        </div>
                                    </div>
                                    <div class="d-flex align-items-center gap-2 flex-wrap">
                                        <span class="po-pill {{ $po->status === 'completed' ? 'po-pill-ok' : 'po-pill-blue' }}">{{ ucfirst($po->status ?: 'generated') }}</span>
                                        @if($entry['url'])
                                            <a href="{{ $entry['url'] }}" target="_blank" rel="noopener" class="btn btn-sm po-btn-quiet">
                                                <i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>Open Booking PO
                                            </a>
                                        @endif
                                    </div>
                                </div>
                                <div class="table-responsive">
                                    <table class="table po-table align-middle mb-0">
                                        <thead>
                                            <tr><th>Style</th><th>Material</th><th>PI Number</th><th class="po-num">Qty</th><th class="po-num">PI Amount</th><th>Terms</th></tr>
                                        </thead>
                                        <tbody>
                                            @foreach($entry['items'] as $line)
                                                <tr>
                                                    <td class="fw-semibold">{{ $line->style_name ?: '—' }}</td>
                                                    <td>
                                                        <div>{{ $line->material_description ?: '—' }}</div>
                                                        @if($line->material_color)<div class="pra-history-sub">{{ $line->material_color }}</div>@endif
                                                    </td>
                                                    <td>{{ $line->pi_number ?: '—' }}</td>
                                                    <td class="po-num">{{ $line->qty !== null ? rtrim(rtrim(number_format((float) $line->qty, 2), '0'), '.') : '—' }}</td>
                                                    <td class="po-num fw-semibold">{{ number_format((float) $line->pi_amount, 2) }}</td>
                                                    <td class="pra-history-sub">{{ collect([$line->payment_term, $line->delivery_term, $line->ship_mode])->filter()->implode(' · ') ?: '—' }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- The audit trail: who has signed off so far in this cycle. --}}
            <div class="po-panel pra-history">
                <div class="po-panel-head">
                    <div class="po-panel-head-title">
                        <span class="po-panel-icon"><i class="bi bi-clock-history" aria-hidden="true"></i></span>
                        <div class="po-title">Approval History</div>
                    </div>
                    @if($decisions->isNotEmpty())
                        <span class="po-pill">{{ $decisions->count() }} {{ \Illuminate\Support\Str::plural('decision', $decisions->count()) }}</span>
                    @endif
                </div>
                <div class="po-panel-body">
                    <div class="table-responsive">
                        <table class="table po-table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th style="width:64px;">Step</th><th>Department</th><th>Person</th><th>Decision</th><th>When</th><th>Comment</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($decisions as $row)
                                    <tr>
                                        <td><span class="pra-step-no">{{ $row->sequence }}</span></td>
                                        <td>
                                            <div class="fw-semibold text-capitalize">{{ $row->department }}</div>
                                            <div class="pra-history-sub text-capitalize">{{ $row->actor_kind }}</div>
                                        </td>
                                        <td>
                                            <div class="fw-semibold">{{ $row->approver?->name ?? '—' }}</div>
                                            @if($row->assignedUser)
                                                {{-- "Assigned to A, approved by B" — the snapshot taken when this
                                                     decision was recorded, so a later reassignment cannot rewrite it. --}}
                                                <div class="pra-history-sub"><i class="bi bi-person-check me-1" aria-hidden="true"></i>Assigned to {{ $row->assignedUser->name }}</div>
                                            @endif
                                        </td>
                                        <td>
                                            <span class="po-pill {{ $row->isApproved() ? 'po-pill-ok' : 'po-pill-danger' }}">
                                                <i class="bi {{ $row->isApproved() ? 'bi-check2-circle' : 'bi-x-circle' }}" aria-hidden="true"></i>{{ ucfirst($row->status) }}
                                            </span>
                                        </td>
                                        <td class="text-nowrap">
                                            <div>{{ optional($row->acted_at)->format('d M Y') ?? '—' }}</div>
                                            @if($row->acted_at)<div class="pra-history-sub">{{ $row->acted_at->format('h:i A') }}</div>@endif
                                        </td>
                                        <td class="pra-history-comment">{{ $row->comment ?: '—' }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="6" class="text-center text-muted py-4">No decisions recorded yet.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="po-panel po-rail">
                <div class="po-panel-head">
                    <div class="po-panel-head-title">
                        <span class="po-panel-icon"><i class="bi bi-pencil-square" aria-hidden="true"></i></span>
                        <div class="po-title">{{ $stage ? 'Your Decision' : 'Status' }}</div>
                    </div>
                </div>
                <div class="po-panel-body">
                    @if($stage)
                        <div class="pra-step-card">
                            <span class="pra-step-no">{{ $stage->sequence }}</span>
                            <div>
                                <div class="pra-step-title">{{ $stage->label }}</div>
                                <div class="pra-step-sub">Current step · {{ $stage->departmentLabel }} {{ $stage->actorKind === 'head' ? 'Head' : 'Team' }}</div>
                            </div>
                        </div>

                        @if($stage->assignee)
                            <div class="pra-note pra-note-assigned mb-3">
                                <i class="bi bi-person-check-fill" aria-hidden="true"></i>
                                <div>
                                    @if($stage->isAssignee($user))
                                        This step was assigned to you.
                                    @else
                                        Assigned to {{ $stage->assignee->name }}. Anyone listed below may still act.
                                    @endif
                                </div>
                            </div>
                        @elseif($stage->unavailableAssignee)
                            <div class="pra-note pra-note-warn mb-3">
                                <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
                                <div>{{ $stage->unavailableAssignee->name }} was assigned this step but can no longer act on it. It is open to the whole team.</div>
                            </div>
                        @endif

                        {{-- EVERYONE WHO CAN ACT, always shown in full. The named
                             person is first and marked; nobody is hidden. --}}
                        @if($stage->isActionable())
                            <div class="pra-info-block">
                                <div class="pra-fact-label">Who can act</div>
                                <ul class="pra-roster">
                                    @foreach($stage->usersAssigneeFirst() as $person)
                                        <li class="{{ $stage->isAssignee($person) ? 'is-primary' : '' }} {{ (int) $person->id === (int) $user->id ? 'is-you' : '' }}">
                                            <span>{{ $person->name }}@if((int) $person->id === (int) $user->id) <span class="text-muted fw-normal">(you)</span>@endif</span>
                                            @if($stage->isAssignee($person))
                                                <span class="po-pill po-pill-blue">Primary</span>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        @if(! $stage->isActionable())
                            {{-- The configuration gap, stated where the person
                                 who hits it will see it. It names the fix
                                 because they are never the one who can apply it. --}}
                            <div class="pra-note pra-note-warn">
                                <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
                                <div>
                                    {{ $stage->problem() }}
                                    @if(auth()->user()?->hasRole('admin') && \Illuminate\Support\Facades\Route::has('admin.pra-routing.index'))
                                        <a href="{{ route('admin.pra-routing.index') }}" class="ms-1">Open PRA Approval Routing</a>
                                    @endif
                                </div>
                            </div>
                        @elseif($canAct)
                            <div class="pra-decision-divider">Record your decision</div>

                            <form method="POST" action="{{ route('pra_departmental.approve', $paymentRequest) }}" class="po-form">
                                @csrf
                                <label for="approve-comment" class="po-field-label">Comment (optional)</label>
                                <textarea id="approve-comment" name="comment" rows="2" class="form-control mb-3" maxlength="2000"></textarea>

                                @if($stage->stage === \App\Models\PraApproval::STAGE_COMPLETE)
                                    <label for="payment-reference" class="po-field-label">Payment reference no. <span class="fw-normal text-lowercase">(optional)</span></label>
                                    <input id="payment-reference" type="text" name="payment_reference" class="form-control mb-1" maxlength="100" value="{{ old('payment_reference') }}">
                                    <div class="form-text mb-3">Added to Pmt Doc No on every line of this PRA, so Payment Status shows Pmt Done.</div>
                                @endif

                                @if($nextStage && $nextStage->isActionable())
                                    {{-- OPTIONAL PRIMARY ASSIGNEE FOR THE NEXT STEP. Options
                                         are the resolver's eligible set for that step and
                                         nothing else; the controller re-checks the id
                                         against the same set. Blank = automatic. --}}
                                    <label for="assign-next" class="po-field-label">
                                        Assign {{ $nextStage->label }} to <span class="fw-normal text-lowercase">(optional)</span>
                                    </label>
                                    <select id="assign-next" name="assign_next" class="form-select js-searchable mb-1">
                                        <option value="">— Automatic (any eligible {{ $nextStage->departmentLabel }} {{ $nextStage->actorKind === 'head' ? 'Head' : 'Team member' }}) —</option>
                                        @foreach($nextStage->usersAssigneeFirst() as $person)
                                            <option value="{{ $person->id }}" {{ $nextStage->isAssignee($person) ? 'selected' : '' }}>{{ $person->name }}</option>
                                        @endforeach
                                    </select>
                                    <div class="form-text mb-3">The whole {{ $nextStage->departmentLabel }} team can still see and act on it. The person you name is put first and notified directly.</div>
                                @elseif($nextStage)
                                    <div class="pra-note pra-note-warn mb-3">
                                        <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
                                        <div>Next step: {{ $nextStage->problem() }}</div>
                                    </div>
                                @endif

                                <button type="submit" class="btn po-btn-primary w-100">
                                    <i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Approve This Step
                                </button>
                            </form>

                            <div class="pra-reject-block">
                                <form method="POST" action="{{ route('pra_departmental.reject', $paymentRequest) }}" class="po-form">
                                    @csrf
                                    <label for="reject-comment" class="po-field-label">Reason for rejection</label>
                                    <textarea id="reject-comment" name="comment" rows="2" class="form-control mb-2 @error('comment') is-invalid @enderror" maxlength="2000"></textarea>
                                    @error('comment')<div class="invalid-feedback d-block mb-2">{{ $message }}</div>@enderror
                                    <button type="submit" class="btn po-btn-danger-quiet w-100">
                                        <i class="bi bi-x-circle me-1" aria-hidden="true"></i>Reject and Return
                                    </button>
                                </form>
                            </div>
                        @else
                            <div class="pra-note">
                                <i class="bi bi-eye" aria-hidden="true"></i>
                                <div>This step is with {{ $stage->departmentLabel }}. You can view this request but cannot act on this step.</div>
                            </div>
                        @endif
                    @else
                        <div class="pra-step-card">
                            <span class="pra-step-no"><i class="bi {{ $paymentRequest->status === 'completed' ? 'bi-check2' : 'bi-dash' }}" aria-hidden="true"></i></span>
                            <div>
                                <div class="pra-step-title text-capitalize">{{ str_replace('_', ' ', $paymentRequest->status) }}</div>
                                <div class="pra-step-sub">No approval step is waiting on this request.</div>
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@section('scripts')
@if($canAct && $nextStage && $nextStage->isActionable())
    @include('store.stock._searchable')
@endif
@endsection
