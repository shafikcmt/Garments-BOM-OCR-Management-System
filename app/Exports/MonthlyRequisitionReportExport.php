<?php

namespace App\Exports;

use App\Services\MonthlyRequisitionReportService;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Excel export for the monthly Purchase Requisition report.
 *
 * Reproduces the company's "Month_Of_<Month>.xlsx" workbook: an "ALL" sheet
 * carrying one row per item for the month, then one sheet per category
 * carrying the same rows grouped. The Section-wise export is one sheet per
 * section instead. Only categories/sections that actually have rows get a
 * sheet, so the workbook never opens on a row of empty tabs.
 */
class MonthlyRequisitionReportExport implements WithMultipleSheets
{
    use Exportable;

    /** @param array<string, mixed> $data payload from MonthlyRequisitionReportController */
    public function __construct(private readonly array $data)
    {
    }

    /** @return list<MonthlyRequisitionReportSheet> */
    public function sheets(): array
    {
        // The controller decides the tabs: ALL + one per category, or — the
        // Section-wise export — one per section.
        $tabs = $this->data['sheets'] ?? collect(['ALL' => $this->data['rows']])->union($this->data['byCategory']);
        $sectionWise = ($this->data['view'] ?? null) === MonthlyRequisitionReportService::VIEW_SECTION;
        $report = app(MonthlyRequisitionReportService::class);
        $sheets = [];

        foreach ($tabs as $name => $rows) {
            $sheets[] = new MonthlyRequisitionReportSheet(array_merge($this->data, [
                // Each tab's header names its own sheets, and its section —
                // never its category.
                'headerRequisitionNos' => $report->requisitionNosLabel($rows),
                'headerSection' => $sectionWise ? (string) $name : 'ALL',
            ]), (string) $name, $rows);
        }

        return $sheets;
    }
}
