<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/** One plain sheet: bold header row, optional greyed example row (template). */
class VendorSheetExport implements FromArray, WithHeadings, WithTitle, WithStyles, ShouldAutoSize
{
    /** @param list<string> $headings @param array<int, array<int, mixed>> $rows */
    public function __construct(private string $title, private array $headings, private array $rows, private bool $example = false)
    {
    }

    public function headings(): array
    {
        return $this->headings;
    }

    public function array(): array
    {
        return $this->rows;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function styles(Worksheet $sheet): array
    {
        if ($this->example && $this->rows !== []) {
            $sheet->getStyle('A2:' . $sheet->getHighestColumn() . '2')->getFont()->setItalic(true)->getColor()->setARGB('FF9AA0AE');
        }

        return [1 => ['font' => ['bold' => true]]];
    }
}
