<?php

namespace App\Services;

/**
 * Commercial reports (read-only).
 *
 * The third department on DepartmentReportService, and the one that shows what
 * the base is for: everything below is declaration. No query, no scope, no
 * pagination and no presentation logic lives here — all of that is inherited
 * and is the same code Merchandising and Supply Chain run.
 *
 * CONTEXT COLUMNS, for the same reason Supply Chain needs them. Commercial owns
 * 22 headers and not one of them names a buyer, a style or a material; those are
 * Merchandising's. A report of Commercial's own columns alone would print Bill
 * of Entry numbers and document dates with nothing to say which order any line
 * belongs to. The three context columns ride along read-only, which is not a
 * widening of access — every header carries can_view_all = true and a
 * Commercial user already sees Buyer and Style on the workspace grid.
 *
 * A NOTE ON THE DATA. 21 of the 22 columns are empty across all 614 BOM lines
 * today; only Payment Status carries values. The report renders them correctly
 * as blank columns and will fill in as Commercial works. This is why dateKey()
 * below is Payment Docs Date — see its comment.
 */
class CommercialReportService extends DepartmentReportService
{
    /** The role whose headers this report is built from. */
    public const ROLE = 'commercial';

    protected function ownerRole(): string
    {
        return self::ROLE;
    }

    /**
     * Merchandising columns carried as read-only context, in display order.
     *
     * PUBLIC, matching the base — the controller passes these to the view so it
     * can tint the borrowed columns. Declaring it protected here would not
     * compile.
     *
     * @return list<string>
     */
    public function contextKeys(): array
    {
        return ['buyer_name', 'style_name', 'description'];
    }

    /**
     * Buyer and Style are context columns; the rest are Commercial's own. Only
     * columns this report DISPLAYS are filterable.
     *
     * The three status columns are the ones a Commercial user actually chases a
     * shipment by. Two of them hold no data yet, so their dropdowns are empty
     * until the department fills them in — an empty dropdown is honest, where a
     * filter on a column the report cannot show would not be.
     *
     * @return array<string, string>
     */
    protected function exactFilters(): array
    {
        return [
            'buyer' => 'buyer_name',
            'style' => 'style_name',
            'payment_status' => 'payment_status',
            'sg_status' => 'sg_status',
            'docs_status' => 'original_docs_submit_status',
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
     * Payment Docs Date.
     *
     * Every date column Commercial owns is empty today, so this is a judgment
     * rather than a reading of the data: it is the date paired with Payment
     * Status, the one Commercial column that carries values, which makes it the
     * range most likely to return something as the department starts filling
     * these in. Changing it is one line, and no other code depends on which
     * column it names.
     */
    protected function dateKey(): string
    {
        return 'payment_docs_date';
    }

    /**
     * The screen and Excel carry all 25 (3 context + 22 own); 25 will not fit
     * an A4 landscape page at a readable size. These ten are the payment and
     * document picture a manager checks.
     *
     * Commercial Remarks is deliberately absent — it is free text long enough
     * to wreck a fixed-width summary row, and it is on the Excel export for
     * anyone who needs it.
     *
     * @return list<string>
     */
    protected function pdfKeys(): array
    {
        return [
            'buyer_name',
            'style_name',
            'description',
            'payment_status',
            'payment_doc_no',
            'payment_docs_date',
            'exp_no',
            'shipping_bill_no',
            'original_docs_submit_status',
            'bill_of_entry_no',
        ];
    }

    protected function fallbackDateLabel(): string
    {
        return 'Payment Docs Date';
    }
}
