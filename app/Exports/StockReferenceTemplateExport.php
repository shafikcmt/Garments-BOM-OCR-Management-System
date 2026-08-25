<?php

namespace App\Exports;

use App\Imports\StockReferenceImport;
use App\Models\StockItem;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * The upload sheet for the Stock Report reference import, pre-filled.
 *
 * It used to be blank but for an example row, which left somebody to find and
 * retype the name of every item missing a figure — across 1,241 items, most of
 * them complete, that is the whole job done by hand before the import can do
 * any of it.
 *
 * WHAT IT LISTS: every item missing AT LEAST ONE of the three fields, and
 * nothing else. An item the report already shows in full is not on the sheet at
 * all, because there is nothing to type for it.
 *
 * WHAT IS IN THE CELLS: the identifying columns are always filled. Each of the
 * three value columns carries the item's CURRENT value where it has one, greyed
 * as read-only context, and is left genuinely empty only for the fields that
 * are actually missing. So the blank cells are the work, and their emptiness is
 * the instruction.
 *
 * SHOWING EXISTING VALUES IS SAFE, and this is worth stating because a
 * spreadsheet cell is always editable. The import decides what it may write
 * from the DATABASE, never from the file: StockReferenceImport::plan() skips any
 * field whose stored value is already set, whatever the cell beside it says. A
 * pre-filled figure that somebody overtypes by accident is reported as skipped
 * and not written, and re-uploading this sheet untouched changes nothing.
 *
 * Both the list and the greying come from StockReferenceImport::gaps(), the
 * same reading the import itself uses, so the sheet cannot offer a column the
 * import would then refuse.
 *
 * ONE NOTE ROW, ABOVE THE HEADINGS. Above, because the importer looks for its
 * heading row within the first 30 rows and skips everything before it — a note
 * placed below the headings would be read as a data row and rejected as an
 * unknown item. The example row is gone: on a sheet of real items it is one
 * more row to explain rather than a clarification.
 */
class StockReferenceTemplateExport implements FromArray, ShouldAutoSize, WithColumnFormatting, WithStyles, WithTitle
{
    /** Sheet row holding the note; the headings follow it, then the data. */
    private const NOTE_ROW = 1;

    private const HEADING_ROW = 2;

    private const FIRST_DATA_ROW = 3;

    /** Read once and shared by array() and styles(), which must agree exactly. */
    private ?array $rows = null;

    private const NOTE = 'Only items missing at least one value are listed. '
        .'Grey cells are already recorded — leave them as they are. '
        .'Fill in the empty cells only, then upload this file.';

    /**
     * The note, the headings, then one row per incomplete item.
     *
     * @return array<int, array<int, mixed>>
     */
    public function array(): array
    {
        $note = array_fill(0, count(StockReferenceImport::COLUMNS), null);
        $note[0] = self::NOTE;

        return array_merge(
            [$note, StockReferenceImport::COLUMNS],
            array_map(fn (array $row) => $row['cells'], $this->incompleteItems())
        );
    }

    /**
     * Items missing at least one of the three fields, with their current values.
     *
     * Two queries plus the four grouped reads inside gaps(): the item list and
     * its state, never a query per item.
     *
     * No filter on is_active, matching the importer — an inactive item can still
     * be sitting on the report with a blank column, and refusing to list it here
     * while accepting it on upload would be an inconsistency with no reason
     * behind it.
     *
     * @return list<array{cells: list<mixed>, filled: list<int>}>
     */
    private function incompleteItems(): array
    {
        if ($this->rows !== null) {
            return $this->rows;
        }

        $state = StockReferenceImport::gaps();

        /** @var Collection<int, StockItem> $items */
        $items = StockItem::query()
            ->orderBy('name')
            ->orderBy('brand')
            ->get(['id', 'name', 'brand']);

        $rows = [];

        foreach ($items as $item) {
            $item_state = $state[(int) $item->id] ?? null;

            if ($item_state === null) {
                continue;
            }

            // Complete items are left off entirely: there is nothing to type.
            if (! in_array(false, $item_state['has'], true)) {
                continue;
            }

            $cells = [$item->name, $item->brand];
            $filled = [];

            foreach (StockReferenceImport::FIELDS as $offset => $field) {
                $value = $item_state['has'][$field] ? $item_state['current'][$field] : null;

                $cells[] = $value;

                // Greyed only where something is actually printed. A field that
                // is "already set" with nothing to show — the rare item whose
                // only purchase carries no date — prints an empty cell, which
                // the import still refuses to write to.
                if ($value !== null) {
                    // +3: the two identifying columns, and 1-based columns.
                    $filled[] = $offset + 3;
                }
            }

            $rows[] = ['cells' => $cells, 'filled' => $filled];
        }

        return $this->rows = $rows;
    }

    /** @return array<string, string> */
    public function columnFormats(): array
    {
        return [
            $this->dataRange('Unit Price') => NumberFormat::FORMAT_NUMBER_00,
            $this->dataRange('Last Addition Date') => NumberFormat::FORMAT_DATE_YYYYMMDD,
            $this->dataRange('Last Consumption Date') => NumberFormat::FORMAT_DATE_YYYYMMDD,
        ];
    }

    /** The data rows of one column, addressed by its heading. */
    private function dataRange(string $heading): string
    {
        $letter = $this->columnLetter(
            (int) array_search($heading, StockReferenceImport::COLUMNS, true) + 1
        );

        $last = max(self::FIRST_DATA_ROW, self::FIRST_DATA_ROW + count($this->incompleteItems()) - 1);

        return $letter.self::FIRST_DATA_ROW.':'.$letter.$last;
    }

    private function columnLetter(int $index): string
    {
        return Coordinate::stringFromColumnIndex($index);
    }

    public function title(): string
    {
        return 'Stock Reference';
    }

    /**
     * Bold headings, a muted note, and a grey fill on every cell that already
     * carries a value.
     *
     * The fill is applied cell by cell rather than by column, because whether a
     * value is present differs per item — that is the entire point of the
     * sheet.
     *
     * @return array<int, array<string, mixed>>
     */
    public function styles(Worksheet $sheet): array
    {
        $rows = $this->incompleteItems();

        foreach ($rows as $offset => $row) {
            $line = self::FIRST_DATA_ROW + $offset;

            foreach ($row['filled'] as $column) {
                $sheet->getStyle($this->columnLetter($column).$line)->applyFromArray([
                    'fill' => [
                        'fillType' => Fill::FILL_SOLID,
                        'startColor' => ['rgb' => 'EDEFF2'],
                    ],
                    'font' => ['color' => ['rgb' => '6B7280']],
                ]);
            }
        }

        return [
            self::NOTE_ROW => ['font' => ['italic' => true, 'color' => ['rgb' => '6B7280']]],
            self::HEADING_ROW => ['font' => ['bold' => true]],
        ];
    }
}
