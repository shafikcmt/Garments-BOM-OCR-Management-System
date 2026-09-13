<?php

namespace App\Http\Controllers\Concerns;

use App\Models\PurchaseRequisition;
use App\Models\User;
use App\Services\RequisitionFulfilmentService;

/**
 * The Stock Summary widget's data, for a dashboard that wants to carry it.
 *
 * Seven screens show this widget — Store, Admin, Management and the four
 * department dashboards — and every one of them needs the same three keys
 * computed the same way. Written once here so a change to what the widget is
 * given is a change in one file, not seven.
 *
 * The SCOPE is not decided here: RequisitionFulfilmentService reads it from the
 * user, so Store, Admin and Management get the whole company and a department
 * user granted the requisition permissions gets their own requisitions. The one
 * thing this adds is whether the widget should appear at all.
 */
trait ShowsRequisitionSummary
{
    /**
     * @return array{fulfilment: array<string, mixed>|null, fulfilmentPeriod: string, fulfilmentVisible: bool}
     */
    protected function requisitionSummary(?User $user): array
    {
        // Shown to whoever may see requisitions at all — the Store team, Admin
        // and Management, or a department user who has been granted
        // store.requisition.view. A department that cannot raise requisitions
        // has nothing to read here, so their dashboard is left as it was.
        $visible = $user !== null && (
            PurchaseRequisition::seesEveryRequisition($user)
            || $user->can('store.requisition.view')
        );

        if (! $visible) {
            return [
                'fulfilment' => null,
                'fulfilmentPeriod' => '',
                'fulfilmentVisible' => false,
            ];
        }

        // The current month on a dashboard: a dashboard answers "where are we
        // now". The requisition screen is where a different period is chosen,
        // and the widget there follows that screen's own month filter.
        $month = now()->format('Y-m');

        return [
            'fulfilment' => app(RequisitionFulfilmentService::class)->summary($user, ['month' => $month]),
            'fulfilmentPeriod' => now()->format('F Y'),
            'fulfilmentVisible' => true,
        ];
    }
}
