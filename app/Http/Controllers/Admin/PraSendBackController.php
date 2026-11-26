<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PaymentRequest;
use App\Services\PraApprovalService;
use App\Services\PraStageResolver;
use App\Services\PraWorkflow;
use Illuminate\Http\Request;

/**
 * Admin correction screen: pick PRAs that are part-way through approval and send
 * them back to an earlier step (for example after the chain was changed, or a
 * step was signed by the wrong person). The rules live in
 * PraWorkflow::sendBackToStep(); this screen only chooses and reports.
 */
class PraSendBackController extends Controller
{
    public function __construct(
        protected PraWorkflow $workflow,
        protected PraStageResolver $resolver,
        protected PraApprovalService $notifier,
    ) {
    }

    public function index()
    {
        $pending = PaymentRequest::query()
            ->where('flow_version', PaymentRequest::FLOW_DEPARTMENTAL)
            ->whereNotNull('current_sequence')
            ->with(['createdBy', 'approvals.approver', 'stageAssignments.user'])
            ->latest('id')
            ->get();

        $rows = $pending->map(function (PaymentRequest $pra) {
            $stage = $this->workflow->currentStage($pra);

            return [
                'pra' => $pra,
                'stage' => $stage,
                'ordinal' => $stage ? $this->resolver->ordinal($stage->sequence) : null,
                'done' => $this->workflow->currentCycleDecisions($pra)
                    ->filter(fn ($d) => $d->isApproved())
                    ->map(fn ($d) => $this->resolver->definitionFor((int) $d->sequence)['label'] . ' (' . ($d->approver?->name ?? '—') . ')')
                    ->values(),
            ];
        });

        $steps = collect($this->resolver->sequences())->map(fn (int $sequence) => [
            'sequence' => $sequence,
            'ordinal' => $this->resolver->ordinal($sequence),
            'label' => $this->resolver->definitionFor($sequence)['label'],
        ]);

        return view('admin.pra-send-back.index', ['rows' => $rows, 'steps' => $steps]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'payment_request_ids' => ['required', 'array', 'min:1'],
            'payment_request_ids.*' => ['integer'],
            'sequence' => ['required', 'integer'],
            'note' => ['required', 'string', 'max:500'],
        ], [
            'payment_request_ids.required' => 'Select at least one PRA.',
            'note.required' => 'Write the reason for sending it back.',
        ]);

        $done = [];
        $failed = [];

        foreach (PaymentRequest::whereIn('id', $data['payment_request_ids'])->get() as $pra) {
            try {
                $pra = $this->workflow->sendBackToStep($pra, $request->user(), (int) $data['sequence'], $data['note']);
                $done[] = $pra->request_no;

                $stage = $this->workflow->currentStage($pra);
                if ($stage) {
                    $this->notifier->notifyStageActors($pra, $stage);
                }
            } catch (\Throwable $e) {
                $failed[] = $pra->request_no . ' (' . $e->getMessage() . ')';
            }
        }

        // The sidebar badge is cached for 30 seconds per user, so it catches up on its own.
        $redirect = redirect()->route('admin.pra-send-back.index');

        if ($done !== []) {
            $redirect->with('success', implode(', ', $done) . ' sent back to "' . $this->resolver->definitionFor((int) $data['sequence'])['label'] . '".');
        }
        if ($failed !== []) {
            $redirect->with('warning', 'Not changed: ' . implode('; ', $failed));
        }

        return $redirect;
    }
}
