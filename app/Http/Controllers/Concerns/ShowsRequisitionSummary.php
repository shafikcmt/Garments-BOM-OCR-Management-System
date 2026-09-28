<?php

namespace App\Http\Controllers\Concerns;

use App\Models\User;
use App\Services\RequirementVsIssueService;
use App\Services\StockSummaryService;

/**
 * The Stock Summary widget's data, for a dashboard that wants to carry it.
 *
 * Seven dashboards include this widget — Store, Admin, Management, Accounts,
 * Commercial, Merchandising and Supply Chain — and every one asks the same
 * question here. It renders only for users holding store.requirement_issue.view
 * (Admin included) — the Requirement vs Issue access check; on every other
 * dashboard it is absent.
 */
trait ShowsRequisitionSummary
{
    /**
     * @return array{stockSummary: array<string, mixed>|null, fulfilmentVisible: bool}
     */
    protected function requisitionSummary(?User $user): array
    {
        // Only for users holding store.requirement_issue.view (and Admin) —
        // the same check as Requirement vs Issue. Every other dashboard shows
        // nothing here and runs none of its queries.
        if (! RequirementVsIssueService::canView($user)) {
            return [
                'stockSummary' => null,
                'fulfilmentVisible' => false,
            ];
        }

        // The current month on a dashboard: a dashboard answers "where are we
        // now". The requisition screen is where a different period is chosen.
        return [
            'stockSummary' => app(StockSummaryService::class)->build($user, []),
            'fulfilmentVisible' => true,
        ];
    }
}
