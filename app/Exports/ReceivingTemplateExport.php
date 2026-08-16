<?php

namespace App\Exports;

use App\Imports\ReceivingImport;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * The blank "Download Sample Template" workbook for the receiving import.
 *
 * Headings come straight from ReceivingImport::COLUMNS rather than being
 * retyped here, so the template a user downloads always matches the columns the
 * importer actually reads — the same arrangement the item-master template uses.
 *
 * The column order mirrors the company's own Consumable Stock Report "Purchase"
 * sheet, so anyone comparing the two sees the same layout.
 */
class ReceivingTemplateExport implements FromArray, ShouldAutoSize, WithColumnFormatting, WithHeadings, WithStyles, WithTitle
{
    /**
     * How the two date columns are displayed — the same format the issue
     * template and the company's own Consumption workbook use ("1/Aug/26"),
     * chosen over a numeric one because 01/08/26 reads as two different days
     * depending on who opens it.
     */
    public const DATE_FORMAT = '[$-409]d/mmm/yy;@';

    /** The template's date columns, in heading order. */
    private const DATE_COLUMNS = ['Challan Date*', 'RCV Date'];

    /** @return list<string> */
    public function headings(): array
    {
        return ReceivingImport::COLUMNS;
    }

    /** @return array<int, array<int, mixed>> */
    public function array(): array
    {
        // Two example lines sharing one challan, because the single thing a
        // user has to understand about this file is that consecutive rows with
        // the same challan are ONE delivery.
        // Positions are found by heading rather than counted, so inserting a
        // column into ReceivingImport::COLUMNS cannot silently drop the second
        // example's quantity into the wrong cell — which is exactly what the
        // hardcoded indexes here did when Brand, Size and Specification were
        // added ahead of them.
        $at = fn (string $heading) => array_search($heading, ReceivingImport::COLUMNS, true);

        $first = ReceivingImport::SAMPLE_ROW;

        $second = $first;
        $second[$at('Item Name*')] = 'EXAMPLE — a second item on the SAME challan';
        $second[$at('Purchased Qty*')] = 5;
        $second[$at('Unit Price')] = 20;
        $second[$at('Total Value')] = 100;

        // Challan Date and RCV Date are written as REAL Excel dates — the
        // serial numbers the format in columnFormats() then displays — not as
        // the text the constant holds. Written as text, Excel treats what the
        // user types beneath them as text too, and the file comes back with
        // dates no date function, sort or filter understands.
        //
        // The serials are produced here rather than in SAMPLE_ROW because that
        // constant is also the readable reference for the file's shape, and a
        // bare 46235 documents nothing.
        foreach (self::DATE_COLUMNS as $heading) {
            $column = $at($heading);
            $text = ReceivingImport::SAMPLE_ROW[$column];

            if (trim((string) $text) === '') {
                continue;
            }

            $serial = ExcelDate::PHPToExcel(Carbon::parse($text)->startOfDay());

            $first[$column] = $serial;
            $second[$column] = $serial;
        }

        // Month is derived from Challan Date instead of being repeated as text,
        // so it cannot say Aug-26 next to a September challan once the user
        // edits the row. Challan Date rather than RCV Date because that is the
        // date stock_purchases groups a delivery on, and because RCV Date is
        // optional — a blank one would render as Jan-00. It is display-only:
        // ReceivingImport reads the column but stores nothing from it.
        $monthColumn = $at('Month');
        $challan = self::columnLetter('Challan Date*');
        $first[$monthColumn] = '=TEXT('.$challan.'2,"MMM-YY")';
        $second[$monthColumn] = '=TEXT('.$challan.'3,"MMM-YY")';

        return [$first, $second];
    }

    /**
     * @return array<string, string>
     */
    public function columnFormats(): array
    {
        $formats = [];

        foreach (self::DATE_COLUMNS as $heading) {
            // A range rather than the bare column, because Maatwebsite widens a
            // bare column only as far as the last written row — leaving the
            // empty rows the user is actually going to fill in unformatted.
            $letter = self::columnLetter($heading);
            $formats[$letter.'2:'.$letter.'1000'] = self::DATE_FORMAT;
        }

        return $formats;
    }

    /** A column's letter, found by heading rather than assumed. */
    private static function columnLetter(string $heading): string
    {
        return Coordinate::stringFromColumnIndex(
            (int) array_search($heading, ReceivingImport::COLUMNS, true) + 1
        );
    }

    public function title(): string
    {
        return 'Receiving';
    }

    /** @return array<string, array<string, mixed>> */
    public function styles(Worksheet $sheet): array
    {
        // The example rows are greyed and italic so nobody mistakes them for
        // data they are supposed to keep.
        $last = Coordinate::stringFromColumnIndex(count(ReceivingImport::COLUMNS));

        $sheet->getStyle('A2:'.$last.'3')->getFont()->setItalic(true)->getColor()->setARGB('FF9AA0AE');

        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
