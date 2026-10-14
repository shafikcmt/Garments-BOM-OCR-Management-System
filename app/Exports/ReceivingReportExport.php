<?php

namespace App\Exports;

use App\Exports\Concerns\FormatsStoreSheet;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromView;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;

/**
 * Excel export for the Receiving report.
 *
 * Renders the same blade the PDF uses, so a downloaded sheet can never disagree
 * with the printed page or the screen.
 *
 * FromView carries the data but none of the presentation, so the sheet is
 * styled afterwards by FormatsStoreSheet — the same styling the monthly
 * requisition workbook and the Consumable Stock Report get, so every Store
 * download looks like it came from one system.
 *
 * ShouldAutoSize is deliberately NOT used, for the reason given in
 * MonthlyRequisitionReportSheet: it measures merged group headings badly.
 */
class ReceivingReportExport implements FromView, WithEvents, WithTitle
{
    use Exportable;
    use FormatsStoreSheet;

    /** @param array<string, mixed> $data payload from ReceivingReportController */
    public function __construct(private readonly array $data)
    {
    }

    public function view(): \Illuminate\Contracts\View\View
    {
        return view('store.stock.receiving-report-pdf', array_merge($this->data, [
            'forExcel' => true,
        ]));
    }

    public function title(): string
    {
        return 'Receiving';
    }

    /** @return array<string, callable> */
    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

                $this->formatStoreSheet($sheet);
                $this->addValueFormulas($sheet);
            },
        ];
    }

    /**
     * Received Value = Received Qty x Unit Price, as a formula on every line
     * that has both, so a click on the cell shows how it was worked out.
     *
     * A line with no unit price keeps its printed value, and the Total row is
     * left as it was. Columns are found by their heading, not by letter.
     */
    private function addValueFormulas(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet): void
    {
        $lastRow = $sheet->getHighestDataRow();
        $lastIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestDataColumn());

        [$headerStart, $headerEnd] = $this->locateHeader($sheet, $lastRow, $lastIndex);

        if ($headerStart === null) {
            return;
        }

        $col = [];

        for ($i = 1; $i <= $lastIndex; $i++) {
            $letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i);
            $col[trim((string) $sheet->getCell($letter.$headerEnd)->getValue())] ??= $letter;
        }

        if (! isset($col['Received Qty'], $col['Unit Price'], $col['Received Value'], $col['SL'])) {
            return;
        }

        for ($row = $headerEnd + 1; $row <= $lastRow; $row++) {
            // Item lines carry a serial number; the Total row and the blank
            // rows below it do not, which is where the lines end.
            if (! is_numeric($sheet->getCell($col['SL'].$row)->getValue())) {
                break;
            }

            $qty = $sheet->getCell($col['Received Qty'].$row)->getValue();
            $price = $sheet->getCell($col['Unit Price'].$row)->getValue();

            $value = $sheet->getCell($col['Received Value'].$row)->getValue();

            // Only where the formula lands on the printed value: the price is
            // printed to two decimals, so a rate with more would make the
            // formula disagree with the figure the report has always shown.
            if (is_numeric($qty) && is_numeric($price) && is_numeric($value)
                && abs((float) $qty * (float) $price - (float) $value) < 0.005) {
                $sheet->setCellValue(
                    $col['Received Value'].$row,
                    '='.$col['Received Qty'].$row.'*'.$col['Unit Price'].$row
                );
            }
        }
    }
}
