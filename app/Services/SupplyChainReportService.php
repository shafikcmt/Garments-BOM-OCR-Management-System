<?php

namespace App\Services;

/**
 * Supply Chain reports (read-only).
 *
 * WHAT THIS REPORT SHOWS, and why it is not simply "Supply Chain's columns".
 *
 * Supply Chain owns 41 headers and NOT ONE of them names a buyer, a style or a
 * material. Those live on Merchandising's columns. A report of Supply Chain's
 * own columns alone would print PO numbers, statuses and dates with nothing to
 * say which order any line belongs to — correct, and unusable.
 *
 * So a short, fixed list of Merchandising columns rides along as CONTEXT: read
 * only, always leading, and never editable from here. That is not a widening of
 * access. Every header in the system carries can_view_all = true, and the
 * workspace's own rule (ExcelFileController::visibleHeader) is "can_view_all OR
 * owns it" — a Supply Chain user already sees Buyer and Style on the grid every
 * day. owner_role_id governs who may EDIT a column, not who may read it.
 *
 * The boundary that IS enforced: no column belonging to Commercial, Accounts,
 * Production or Store appears here, and no Merchandising column beyond
 * contextKeys(). The parent's columns() is the only thing that decides.
 *
 * Everything else — the buyer scope, the filters, the dropdowns, the exports'
 * shared query path — is DepartmentReportService.
 */
class SupplyChainReportService extends DepartmentReportService
{
    /** The role whose headers this report is built from. */
    public const ROLE = 'supply_chain';

    protected function ownerRole(): string
    {
        return self::ROLE;
    }

    /**
     * Merchandising columns carried as read-only context, in display order.
     *
     * Deliberately three. Each one answers a question Supply Chain's own
     * columns cannot: which buyer, which style, which material. A fourth would
     * be someone else's report.
     *
     * @return list<string>
     */
    public function contextKeys(): array
    {
        return ['buyer_name', 'style_name', 'description'];
    }

    /**
     * Buyer and Style are context columns; the rest are Supply Chain's own.
     * Only columns this report actually DISPLAYS are filterable.
     *
     * @return array<string, string>
     */
    protected function exactFilters(): array
    {
        return [
            'buyer' => 'buyer_name',
            'style' => 'style_name',
            'po_no' => 'material_po_number',
            'order_status' => 'material_order_status',
            'pi_status' => 'pi_status',
        ];
    }

    /**
     * @return list<string>
     */
    protected function searchKeys(): array
    {
        return ['description', 'sap_code'];
    }

    /**
     * PO Date — Supply Chain's own, and the one date column carrying data
     * today.
     */
    protected function dateKey(): string
    {
        return 'po_date';
    }

    /**
     * The screen and Excel carry all 44 (3 context + 41 own); 44 will not fit
     * an A4 landscape page at a readable size. These ten are the ordering and
     * status picture a manager checks.
     *
     * @return list<string>
     */
    protected function pdfKeys(): array
    {
        return [
            'buyer_name',
            'style_name',
            'description',
            'material_po_number',
            'po_date',
            'materials_ordered',
            'material_order_status',
            'pi_status',
            'pi_amount',
            'arrival_status',
        ];
    }

    protected function fallbackDateLabel(): string
    {
        return 'PO Date';
    }
}
