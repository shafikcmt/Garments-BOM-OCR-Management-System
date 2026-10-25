<?php

namespace App\Exports;

use App\Imports\ItemMasterImport;
use App\Models\StockItem;
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
 * The item master as it stands, in the import's own column order.
 *
 * Written for the job of filling something in across many items at once —
 * Unit Price being the case that prompted it — where the alternative is opening
 * a modal 1,241 times. Downloading the real list rather than the blank template
 * is what makes that safe: the Item Name and Brand/Specification columns come
 * out spelled exactly as stored, and those two together are how every importer
 * here identifies an item.
 *
 * THIS FILE IS NOT A ROUND TRIP. Re-uploading it through Items → Import writes
 * nothing: that importer only ever creates, and every row here already exists,
 * so all of them come back as "already exists in the item master". Use it to
 * read, and to build the small Unit Price file the price importer takes.
 *
 * Dates and numbers are written as dates and numbers, the discipline the issue
 * and receiving templates settled on — a column that comes back as text is one
 * no arithmetic, sort or filter understands.
 */
class ItemMasterExport implements FromArray, ShouldAutoSize, WithColumnFormatting, WithHeadings, WithStyles, WithTitle
{
    /** @return list<string> */
    public function headings(): array
    {
        // The importer's own columns, so what comes out lines up with what goes
        // in and neither can drift from the other.
        return ['SKU', ...ItemMasterImport::COLUMNS];
    }

    /** @return array<int, array<int, mixed>> */
    public function array(): array
    {
        // +1: the SKU column sits first, ahead of the importer's own columns.
        $at = fn (string $heading) => 1 + (int) array_search($heading, ItemMasterImport::COLUMNS, true);

        // Inactive items included. They are still part of the master, they
        // still hold stock the report values, and leaving them out of a file
        // people fill in would mean a price could never be given to one.
        return StockItem::query()
            ->orderBy('name')
            ->get()
            ->map(function (StockItem $item) use ($at) {
                $row = array_fill(0, count(ItemMasterImport::COLUMNS) + 1, null);

                $row[0] = $item->sku;

                $row[$at('Item Name*')] = $item->name;
                $row[$at('Brand/Specification')] = $item->brand;
                $row[$at('Uom*')] = $item->uom;
                $row[$at('Category*')] = $item->category;
                $row[$at('Opening Stock')] = $item->opening_qty !== null ? (float) $item->opening_qty : null;
                $row[$at('Counted On')] = $item->opening_as_on
                    ? ExcelDate::PHPToExcel(Carbon::parse($item->opening_as_on)->startOfDay())
                    : null;
                // Left blank when blank, never zeroed: null is what makes the
                // Stock Report calculate these, and a 0 written here would come
                // back as a pinned zero meaning "never re-order".
                $row[$at('Safety Stock')] = $item->safety_stock_qty !== null ? (float) $item->safety_stock_qty : null;
                $row[$at('Re-order Level')] = $item->reorder_level !== null ? (float) $item->reorder_level : null;
                $row[$at('Lead Time')] = $item->lead_time_days;
                $row[$at('Unit Price')] = $item->unit_price !== null ? (float) $item->unit_price : null;
                $row[$at('Remarks')] = $item->remarks;

                return $row;
            })
            ->all();
    }

    /** @return array<string, string> */
    public function columnFormats(): array
    {
        $letter = fn (string $heading) => Coordinate::stringFromColumnIndex(
            (int) array_search($heading, ItemMasterImport::COLUMNS, true) + 2
        );

        return [
            $letter('Counted On') => IssueTemplateExport::DATE_FORMAT,
            $letter('Unit Price') => \App\Support\Quantity::EXCEL_MONEY,
        ];
    }

    public function title(): string
    {
        return 'Item Master';
    }

    /** @return array<int, array<string, mixed>> */
    public function styles(Worksheet $sheet): array
    {
        // The two identity columns are frozen and greyed: change either and the
        // price importer stops recognising the row. Nothing enforces that in a
        // spreadsheet, so it is at least said in the only way a spreadsheet can.
        $sheet->freezePane('D2');

        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
