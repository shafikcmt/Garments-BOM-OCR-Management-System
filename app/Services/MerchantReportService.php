<?php

namespace App\Services;

/**
 * Merchandising reports (read-only).
 *
 * A line-level view of the BOM the merchant department already fills in: one
 * row per excel_rows record, one column per header THIS department owns.
 *
 * Merchandising declares no context columns. It owns buyer_name, style_name and
 * description itself, so its own columns already say which order a line belongs
 * to — the reason contextKeys() is a default on the parent rather than an
 * abstract every department has to answer.
 *
 * Everything else — the column boundary, the buyer scope, the filters, the
 * dropdowns, the exports' shared query path — is DepartmentReportService.
 */
class MerchantReportService extends DepartmentReportService
{
    /** The role whose headers this report is built from. */
    public const ROLE = 'merchant';

    protected function ownerRole(): string
    {
        return self::ROLE;
    }

    /**
     * Every key here is a merchant-owned header. Listing one owned by another
     * role would let a merchant filter on a column they cannot see, which is
     * the same leak by a slower route.
     *
     * @return array<string, string>
     */
    protected function exactFilters(): array
    {
        return [
            'buyer' => 'buyer_name',
            'style' => 'style_name',
            'season' => 'season_name',
            'gmts_color' => 'gmts_color_name',
            'po_no' => 'garments_po',
        ];
    }

    /**
     * @return list<string>
     */
    protected function searchKeys(): array
    {
        return ['description', 'sap_code', 'material_type'];
    }

    protected function dateKey(): string
    {
        return 'contract_shipment_date';
    }

    /**
     * The screen and Excel carry all 35 merchant columns; 35 will not fit an A4
     * landscape page at a readable size, so the PDF prints the management
     * summary instead. Material Description is included because a BOM row is
     * material-level — without it the page shows a dozen identical buyer/style
     * lines with no way to tell them apart.
     *
     * @return list<string>
     */
    protected function pdfKeys(): array
    {
        return [
            'buyer_name',
            'style_name',
            'season_name',
            'gmts_color_name',
            'garments_po',
            'description',
            'contract_shipment_date',
            'customer_contract_quantity',
            'materials_to_be_ordered',
            'fabric_swatch_status',
        ];
    }

    protected function fallbackDateLabel(): string
    {
        return 'Shipment Date';
    }
}
