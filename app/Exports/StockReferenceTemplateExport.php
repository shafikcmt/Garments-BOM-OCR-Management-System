<?php

namespace App\Exports;

use App\Imports\StockReferenceImport;
use App\Models\StockItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
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
class StockReferenceTemplateExport implements FromArray, WithColumnFormatting, WithStyles, WithTitle
{
    /** Sheet row holding the note; the headings follow it, then the data. */
    private const NOTE_ROW = 1;

    private const HEADING_ROW = 2;

    private const FIRST_DATA_ROW = 3;

    /**
     * The two date columns, named by the field they carry in
     * StockReferenceImport::FIELDS.
     *
     * Their cells are written as REAL Excel dates and displayed by
     * ReceivingTemplateExport::DATE_FORMAT, so this sheet reads exactly like
     * the Receiving and Issue templates ("1/Aug/26") instead of in a format of
     * its own.
     *
     * @var list<string>
     */
    private const DATE_FIELDS = ['last_addition', 'last_consumption'];

    /**
     * Column width bounds, in Excel character units — the same clamping, and
     * the same upper bound, that FormatsStoreSheet applies to the Store
     * reports, so the two downloads are proportioned alike.
     *
     * The floor is higher than a report's because every column here is one
     * somebody types into: a Unit Price column measured on "7.25" alone would
     * come out four characters wide.
     */
    private const WIDTH_MIN = 12;

    private const WIDTH_MAX = 42;

    private const WIDTH_PADDING = 2;

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

                if ($value !== null && in_array($field, self::DATE_FIELDS, true)) {
                    $value = self::serial($value);
                }

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

    /**
     * A stored ISO date as the Excel serial number a real date cell holds.
     *
     * Written as a date rather than as text so the sheet DISPLAYS it in the
     * same format the Receiving and Issue templates use, and so a date
     * function, sort or filter still understands the column once the user has
     * typed into the blank cells beside it. The importer already reads a serial
     * through PhpSpreadsheet — see ReadsItemSheets::date() — so the round trip
     * is unchanged.
     */
    private static function serial(string $iso): float
    {
        return (float) ExcelDate::PHPToExcel(Carbon::parse($iso)->startOfDay());
    }

    /** @return array<string, string> */
    public function columnFormats(): array
    {
        return [
            $this->dataRange('Unit Price') => \App\Support\Quantity::EXCEL_MONEY,
            // The Receiving template's format, not a copy of it: one spelling
            // of the store's date display, changed in one place.
            $this->dataRange('Last Addition Date') => ReceivingTemplateExport::DATE_FORMAT,
            $this->dataRange('Last Consumption Date') => ReceivingTemplateExport::DATE_FORMAT,
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

        $this->sizeColumns($sheet);

        return [
            self::NOTE_ROW => ['font' => ['italic' => true, 'color' => ['rgb' => '6B7280']]],
            // Wrapped, so a long heading is read on two lines instead of
            // deciding how wide the column under it has to be. "Last
            // Consumption Date" is 21 characters over a column of 9-character
            // dates.
            self::HEADING_ROW => [
                'font' => ['bold' => true],
                'alignment' => ['wrapText' => true, 'vertical' => Alignment::VERTICAL_BOTTOM],
            ],
        ];
    }

    /**
     * Explicit widths, measured from the DATA.
     *
     * ShouldAutoSize used to do this and got column A badly wrong: auto-size
     * measures every cell in the column, and A1 holds the ~190-character
     * instruction note, so Item Name came out wide enough to hold a sentence
     * and pushed the other four columns off the screen. Neither the note nor
     * the headings are measured here for that reason — the note overflows
     * across the empty cells beside it as it always did, and the headings wrap.
     *
     * Same shape as FormatsStoreSheet::sizeColumns(), which reached the same
     * conclusion about auto-size on the Store reports: measure the content,
     * clamp it, and turn auto-size off so nothing overrides the result.
     */
    private function sizeColumns(Worksheet $sheet): void
    {
        $longest = array_fill(0, count(StockReferenceImport::COLUMNS), 0);

        foreach ($this->incompleteItems() as $row) {
            foreach ($row['cells'] as $column => $value) {
                $longest[$column] = max($longest[$column], mb_strlen($this->displayed($column, $value)));
            }
        }

        foreach ($longest as $column => $width) {
            $letter = $this->columnLetter($column + 1);
            $wanted = $width + self::WIDTH_PADDING;

            $sheet->getColumnDimension($letter)
                ->setAutoSize(false)
                ->setWidth(max(self::WIDTH_MIN, min(self::WIDTH_MAX, $wanted)));

            // The handful of item names longer than the cap wrap rather than
            // being cut off by the filled Brand cell beside them.
            if ($wanted > self::WIDTH_MAX) {
                $sheet->getStyle($this->dataRange(StockReferenceImport::COLUMNS[$column]))
                    ->getAlignment()->setWrapText(true);
            }
        }
    }

    /**
     * A cell as Excel will DRAW it, which is what a column has to be wide
     * enough for.
     *
     * The two date columns hold serial numbers, not text: measuring the stored
     * 46082 would ask for five characters where the cell shows "15/Mar/26".
     * Unit Price is stored bare and drawn to two decimals for the same reason.
     */
    private function displayed(int $column, mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        // The two identifying columns sit before FIELDS, so they resolve to
        // null here and are measured as the plain text they are.
        $field = StockReferenceImport::FIELDS[$column - 2] ?? null;

        if ($field !== null && in_array($field, self::DATE_FIELDS, true)) {
            return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $value))->format('j/M/y');
        }

        if ($field === 'unit_price') {
            return number_format((float) $value, 2);
        }

        return (string) $value;
    }
}
