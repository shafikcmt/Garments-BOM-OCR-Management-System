<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * The OCR workbook: the "Database" sheet (one row per material line, in the
 * buyer's own column layout when that buyer has a saved import profile) and
 * the Contract, Material, Payment and Clearance summary sheets.
 *
 * The sheets are prepared by OcrSummaryController so the screen and the file
 * read the same figures.
 */
class OcrExport implements WithMultipleSheets
{
    use Exportable;

    /** @param  list<OcrSheet>  $sheets */
    public function __construct(private readonly array $sheets)
    {
    }

    public function sheets(): array
    {
        return $this->sheets;
    }
}
