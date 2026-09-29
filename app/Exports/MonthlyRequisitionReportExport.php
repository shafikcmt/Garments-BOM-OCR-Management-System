<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Excel export for the monthly Purchase Requisition report.
 *
 * Reproduces the company's "Month_Of_<Month>.xlsx" workbook, following the
 * screen's switch: All / By Category → an "ALL" sheet with one row per item,
 * then one sheet per category; By Section → one sheet per section. Only
 * categories/sections that actually have rows get a sheet, so the workbook
 * never opens on a row of empty tabs. Each sheet prints its own header and
 * signature boxes (MonthlyRequisitionReportService::tabHeader()).
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
        $sheets = [];

        foreach ($this->data['sheets'] as $name => $tab) {
            $sheets[] = new MonthlyRequisitionReportSheet($this->data, (string) $name, $tab);
        }

        return $sheets;
    }
}
