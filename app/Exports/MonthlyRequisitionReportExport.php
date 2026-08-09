<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Excel export for the monthly Purchase Requisition report.
 *
 * Reproduces the company's "Month_Of_<Month>.xlsx" workbook: an "ALL" sheet
 * carrying every line of the month, then one sheet per category carrying the
 * same lines grouped. Only categories that actually have lines get a sheet, so
 * the workbook never opens on a row of empty tabs.
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
        $sheets = [new MonthlyRequisitionReportSheet($this->data, 'ALL', $this->data['rows'])];

        foreach ($this->data['byCategory'] as $category => $rows) {
            $sheets[] = new MonthlyRequisitionReportSheet($this->data, (string) $category, $rows);
        }

        return $sheets;
    }
}
