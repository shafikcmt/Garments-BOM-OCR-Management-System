<?php

namespace App\Exports\Concerns;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * The plain black-and-white print look for the Purchase Requisition documents
 * (the single / Final requisition and the Monthly Report): light grey headings,
 * black ink and borders, no merged cells, taller item rows, signature boxes
 * edge to edge. Used together with FormatsStoreSheet, after it has run.
 */
trait PlainPrintLook
{
    /** The one light grey used for headings, category sums and the total. */
    private const PLAIN_FILL = 'FFEDEDED';

    private const PLAIN_INK = 'FF000000';

    /**
     * The requisition as it is printed on the office's plain black-and-white
     * printer: black ink on light grey, black borders, and no merged cells.
     *
     * Runs after formatStoreSheet(), which the other reports share and which
     * keeps its navy look for them. A merged cell is replaced by text that is
     * centred ACROSS its columns (a heading over several columns) or left to
     * run on into the empty cells beside it; a heading that was merged down two
     * rows keeps its text in the upper cell, sitting on the line between them.
     * Every box is then drawn again around the cells it used to cover.
     */
    private function plainPrintLook(Worksheet $sheet): void
    {
        $lastRow = $sheet->getHighestDataRow();
        $lastColumn = $sheet->getHighestDataColumn();
        $lastIndex = Coordinate::columnIndexFromString($lastColumn);

        [$headerStart, $headerEnd] = $this->locateHeader($sheet, $lastRow, $lastIndex);

        if ($headerStart === null) {
            return;
        }

        $firstData = $headerEnd + 1;
        $signatureRow = $this->locateSignatureRow($sheet, $firstData, $lastRow);
        $totalRow = $this->locateLabelRow($sheet, $firstData, ($signatureRow ?? $lastRow + 1) - 1, 'total');
        $tableEnd = $totalRow ?? (($signatureRow ?? $lastRow + 1) - 1);

        $black = ['argb' => self::PLAIN_INK];
        $thin = ['borderStyle' => Border::BORDER_THIN, 'color' => $black];

        // 1. Take the merges apart, remembering what each covered.
        $boxes = [];
        foreach (array_keys($sheet->getMergeCells()) as $range) {
            [$from, $to] = Coordinate::rangeBoundaries($range);
            $boxes[] = ['range' => $range, 'c1' => $from[0], 'r1' => $from[1], 'c2' => $to[0], 'r2' => $to[1]];
            $sheet->unmergeCells($range);
        }

        // 2. Light grey, black ink: the heading rows and the total.
        $sheet->getStyle('A'.$headerStart.':'.$lastColumn.$headerEnd)->applyFromArray([
            'font' => ['bold' => true, 'color' => $black],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::PLAIN_FILL]],
        ]);

        if ($totalRow !== null) {
            $sheet->getStyle('A'.$totalRow.':'.$lastColumn.$totalRow)->applyFromArray([
                'font' => ['bold' => true, 'color' => $black],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::PLAIN_FILL]],
            ]);
        }

        // A Category Sum row was cream with an amber rule; grey and black now.
        for ($row = $firstData; $row < $tableEnd + ($totalRow === null ? 1 : 0); $row++) {
            if ($row === $totalRow) {
                continue;
            }
            $style = $sheet->getStyle('A'.$row);
            if ($style->getFill()->getFillType() === Fill::FILL_SOLID) {
                $sheet->getStyle('A'.$row.':'.$lastColumn.$row)->applyFromArray([
                    'font' => ['bold' => true, 'color' => $black],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::PLAIN_FILL]],
                ]);
            }
        }

        // 3. Black grid on the letterhead block and on the table. The gap row
        // between them stays open.
        $letterheadEnd = $headerStart - 1;
        while ($letterheadEnd > 1 && trim((string) $sheet->getCell('A'.$letterheadEnd)->getValue().$sheet->getCell('E'.$letterheadEnd)->getValue()) === '') {
            $letterheadEnd--;
        }
        $gridRanges = ['A1:'.$lastColumn.$letterheadEnd, 'A'.$headerStart.':'.$lastColumn.$tableEnd];
        foreach ($gridRanges as $grid) {
            $sheet->getStyle($grid)->applyFromArray(['borders' => ['allBorders' => $thin]]);
        }

        // 4. Each former merge: text across its columns, a box round the group.
        foreach ($boxes as $box) {
            $first = Coordinate::stringFromColumnIndex($box['c1']).$box['r1'];
            $range = Coordinate::stringFromColumnIndex($box['c1']).$box['r1'].':'.Coordinate::stringFromColumnIndex($box['c2']).$box['r2'];
            $acrossColumns = $box['c2'] > $box['c1'];
            $downRows = $box['r2'] > $box['r1'];
            $inTable = $box['r1'] >= $headerStart && $box['r1'] <= $tableEnd;
            $inLetterhead = $box['r1'] <= $letterheadEnd;

            if (! $inTable && ! $inLetterhead) {
                continue; // the signature block: plain text, no boxes
            }

            $alignment = $sheet->getStyle($first)->getAlignment();
            $centred = $alignment->getHorizontal() === Alignment::HORIZONTAL_CENTER;
            $toRight = $alignment->getHorizontal() === Alignment::HORIZONTAL_RIGHT;
            $lastCell = Coordinate::stringFromColumnIndex($box['c2']).$box['r1'];

            // "Total-" is right-aligned over the columns before the amount.
            // Text only runs on to the right, so it moves to the last cell.
            if ($acrossColumns && $toRight && ! $downRows) {
                // The value is read into a variable first: holding two cell
                // objects at once can leave the first one detached.
                $moved = $sheet->getCell($first)->getValue();
                $sheet->setCellValue($first, null);
                $sheet->setCellValue($lastCell, $moved);
                $sheet->getStyle($lastCell)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                $sheet->getStyle($lastCell)->getFont()->setBold(true)->setSize($sheet->getStyle($first)->getFont()->getSize());
            }

            if ($acrossColumns && ! $toRight) {
                $value = $sheet->getCell($first)->getValue();
                if (is_string($value)) {
                    $sheet->setCellValue($first, str_replace(["\r\n", "\n"], ' ', $value));
                }

                $across = $sheet->getStyle($range)->getAlignment();
                $across->setHorizontal($centred ? Alignment::HORIZONTAL_CENTER_CONTINUOUS : Alignment::HORIZONTAL_LEFT)
                    ->setWrapText(false)
                    ->setVertical(Alignment::VERTICAL_CENTER);
            }

            if ($downRows) {
                $sheet->getStyle($range)->getAlignment()->setVertical(Alignment::VERTICAL_BOTTOM)->setWrapText(true);
            }

            // Only the outline: the lines between the cells it covered go.
            $sheet->getStyle($range)->applyFromArray(['borders' => [
                'vertical' => ['borderStyle' => Border::BORDER_NONE],
                'horizontal' => ['borderStyle' => Border::BORDER_NONE],
                'outline' => $thin,
            ]]);
        }

        // The serial number sits in the middle of its column, not against the item name.
        if ($tableEnd >= $firstData) {
            $sheet->getStyle('A'.$firstData.':A'.$tableEnd)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }

        // 5. The whole document boxed in a firmer line.
        $sheet->getStyle('A1:'.$lastColumn.$tableEnd)->applyFromArray(['borders' => [
            'outline' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => $black],
        ]]);

        // 6. Less air: a taller first heading row for the tall headings and a
        // shorter second one, a thin gap below the letterhead, and a smaller
        // signing space. The gap rows are the blank ones above the signature labels.
        // Tall enough for a three-line heading ("Type (Replace/ New)") in a
        // narrow column; the heading text is 9pt so nothing is clipped.
        $sheet->getStyle('A'.$headerStart.':'.$lastColumn.$headerStart)->getFont()->setSize(9);
        $sheet->getRowDimension($headerStart)->setRowHeight(48);
        if ($headerEnd > $headerStart) {
            $sheet->getRowDimension($headerEnd)->setRowHeight(26);
        }
        // Opens zoomed out so the whole width fits an ordinary monitor without
        // a sideways scroll bar; the zoom slider at the bottom still changes it.
        $sheet->getSheetView()->setZoomScale(70);

        for ($row = $letterheadEnd + 1; $row < $headerStart; $row++) {
            $sheet->getRowDimension($row)->setRowHeight($row === $letterheadEnd + 1 ? 5 : 2);
        }

        if ($signatureRow !== null) {
            $space = ($totalRow ?? $tableEnd) + 1;
            for ($row = $space; $row < $signatureRow; $row++) {
                $sheet->getRowDimension($row)->setRowHeight($row === $space ? 5 : 14);
            }
            $sheet->getStyle('A'.$signatureRow.':'.$lastColumn.$signatureRow)->applyFromArray([
                'borders' => ['top' => $thin],
            ]);
        }

        // Item rows: roomier than a bare text line, so a printed row can be read
        // and written on. A row grows with its longest wrapped text (the
        // remarks) at about one line per column-width of characters.
        for ($row = $firstData; $row <= $tableEnd; $row++) {
            if ($row === $totalRow || $sheet->getStyle('A'.$row)->getFill()->getFillType() === Fill::FILL_SOLID) {
                $sheet->getRowDimension($row)->setRowHeight(22);

                continue;
            }

            $lines = 1;
            for ($col = 1; $col <= $lastIndex; $col++) {
                $value = $sheet->getCell(Coordinate::stringFromColumnIndex($col).$row)->getValue();
                $width = $sheet->getColumnDimensionByColumn($col)->getWidth();

                if (is_string($value) && $width > 0 && $sheet->getStyle(Coordinate::stringFromColumnIndex($col).$row)->getAlignment()->getWrapText()) {
                    $lines = max($lines, (int) ceil(mb_strlen($value) * 1.1 / max(1, $width - 1)));
                }
            }

            $sheet->getRowDimension($row)->setRowHeight(max(26, 13 * $lines + 6));
            $sheet->getStyle('A'.$row.':'.$lastColumn.$row)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        }

        // 7. The Signature and Pending Qty columns of the two acknowledgement
        // blocks are wide enough to sign in, and to carry the long heading
        // above them on one line.
        for ($col = 1; $col <= $lastIndex; $col++) {
            $letter = Coordinate::stringFromColumnIndex($col);
            $text = (string) $sheet->getCell($letter.$headerEnd)->getValue();
            $width = $text === 'Signature' ? 14 : ($text === 'Pending Qty' ? 12 : 0);

            if ($width > 0 && ($sheet->getColumnDimension($letter)->getWidth() ?: 0) < $width) {
                $sheet->getColumnDimension($letter)->setWidth($width);
            }
        }

        if ($signatureRow !== null) {
            // Caption bold; the name bold italic; the designation plain.
            $sheet->getStyle('A'.$signatureRow.':'.$lastColumn.$signatureRow)->getFont()->setBold(true)->setItalic(false)->setSize(10);
            $sheet->getStyle('A'.($signatureRow + 1).':'.$lastColumn.($signatureRow + 1))->getFont()->setBold(true)->setItalic(true)->setSize(10);
            $sheet->getStyle('A'.($signatureRow + 2).':'.$lastColumn.($signatureRow + 2))->getFont()->setBold(false)->setItalic(false)->setSize(10);
            foreach ([1, 2] as $offset) {
                $sheet->getRowDimension($signatureRow + $offset)->setRowHeight(16);
            }
        }

        if ($signatureRow !== null) {
            $this->spreadSignatureBoxes($sheet, $signatureRow, $lastIndex);
        }
    }

    /**
     * The signature boxes share the page's WIDTH equally. The Blade gives each
     * box the same number of columns, but the columns are not equally wide, so
     * the last box ended well short of the right edge. Each box's three lines
     * (caption, name, signature and date) move to the column that starts
     * nearest to its equal share of the total width.
     */
    private function spreadSignatureBoxes(Worksheet $sheet, int $labelRow, int $lastIndex): void
    {
        $default = $sheet->getDefaultColumnDimension()->getWidth();
        $default = $default > 0 ? $default : 8.43;

        $widths = [];
        $total = 0.0;
        for ($col = 1; $col <= $lastIndex; $col++) {
            $w = $sheet->getColumnDimensionByColumn($col)->getWidth();
            $widths[$col] = $w > 0 ? $w : $default;
            $total += $widths[$col];
        }

        $starts = [];
        for ($col = 1; $col <= $lastIndex; $col++) {
            if (trim((string) $sheet->getCell(Coordinate::stringFromColumnIndex($col).$labelRow)->getValue()) !== '') {
                $starts[] = $col;
            }
        }

        $count = count($starts);
        if ($count < 2) {
            return;
        }

        // Left edge of each column, then the column nearest each box's share.
        $edges = [];
        $run = 0.0;
        foreach ($widths as $col => $w) {
            $edges[$col] = $run;
            $run += $w;
        }

        // The first box starts at the left edge and the last ends at the right
        // edge (its text is right-aligned into the last column); the ones
        // between are spaced evenly, so no gap is left on either side.
        $boxWidth = $total * 0.16;
        $targets = [];
        for ($i = 0; $i < $count - 1; $i++) {
            $want = ($total - $boxWidth) * $i / ($count - 1);
            $best = 1;
            foreach ($edges as $col => $edge) {
                if (abs($edge - $want) < abs($edges[$best] - $want)) {
                    $best = $col;
                }
            }
            $targets[] = max($best, $i === 0 ? 1 : $targets[$i - 1] + 1);
        }
        $targets[] = $lastIndex;

        // Read all the text first, clear, then write: a target can be a column
        // another box is leaving.
        $moved = [];
        foreach ($starts as $i => $from) {
            $letter = Coordinate::stringFromColumnIndex($from);
            for ($row = $labelRow; $row <= $labelRow + 2; $row++) {
                $moved[] = [$row, $targets[$i], $sheet->getCell($letter.$row)->getValue()];
                $sheet->setCellValue($letter.$row, null);
            }
        }
        foreach ($moved as [$row, $col, $value]) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($col).$row, $value);
        }

        $lastLetter = Coordinate::stringFromColumnIndex($lastIndex);
        $sheet->getStyle($lastLetter.$labelRow.':'.$lastLetter.($labelRow + 2))->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_RIGHT)->setWrapText(false);
    }
}
