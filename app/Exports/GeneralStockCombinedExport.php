<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * The whole Store month in one workbook: Stock, Receiving and Issues as three
 * tabs of a single .xlsx.
 *
 * It composes only. Each sheet is the SAME export class the standalone
 * download on that report's own screen uses, unchanged and unwrapped, so a tab
 * in here and the file that screen produces cannot disagree about columns,
 * totals or styling — they are one object rendering one Blade. The sheet names
 * are the ones those exports already declare through WithTitle ("Stock Jul-26",
 * "Receiving", "Issues"), so a reader who knows the single-sheet downloads
 * recognises the tabs.
 *
 * Sheets are passed in rather than built here. Which sheets a user gets is a
 * PERMISSION question, not an export question: the three reports sit behind
 * three different section guards, so GeneralStockLedgerController decides what
 * this workbook is allowed to contain and hands over only those sheets. This
 * class never reaches for data of its own, which is what keeps that decision
 * in one place.
 *
 * Modelled on MonthlyRequisitionReportExport, the module's existing
 * WithMultipleSheets workbook.
 */
class GeneralStockCombinedExport implements WithMultipleSheets
{
    use Exportable;

    /**
     * @param  list<object>  $sheets  Export objects, already permission-filtered
     *                                and in the order the tabs should appear.
     */
    public function __construct(private readonly array $sheets)
    {
    }

    /** @return list<object> */
    public function sheets(): array
    {
        return $this->sheets;
    }
}
