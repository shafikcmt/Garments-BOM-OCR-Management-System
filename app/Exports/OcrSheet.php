<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;

/**
 * One plain sheet of the OCR workbook: a heading row and data rows. Used for
 * the Database sheet and each summary sheet alike.
 */
class OcrSheet implements FromArray, WithHeadings, WithTitle, ShouldAutoSize, WithEvents
{
    /**
     * @param  list<string>  $headings
     * @param  list<list<mixed>>  $rows
     */
    public function __construct(
        private readonly string $title,
        private readonly array $headings,
        private readonly array $rows,
    ) {
    }

    public function array(): array
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return $this->headings;
    }

    /** Excel sheet names: at most 31 characters, none of : \ / ? * [ ]. */
    public function title(): string
    {
        return mb_substr(str_replace([':', '\\', '/', '?', '*', '[', ']'], '-', $this->title), 0, 31);
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $last = $sheet->getHighestColumn();
                $sheet->getStyle("A1:{$last}1")->applyFromArray([
                    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                    'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '1E3A5F']],
                ]);
                $sheet->freezePane('A2');
                $sheet->setAutoFilter("A1:{$last}" . max(1, $sheet->getHighestRow()));
            },
        ];
    }
}
