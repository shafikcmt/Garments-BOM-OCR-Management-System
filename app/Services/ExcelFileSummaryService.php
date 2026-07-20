<?php

namespace App\Services;

use App\Models\ExcelCell;
use App\Models\ExcelFile;
use App\Models\ExcelHeader;
use App\Models\ExcelRow;
use Illuminate\Support\Collection;

/**
 * Summary line shown for each file in the workspace list.
 *
 * The list needs five values per file, all read from that file's first row.
 * The workspaces used to get them by eager-loading rows.cells.header, which
 * hydrates every cell of every file — 77,505 objects and 117 MB to produce
 * fifteen strings for three files, on six different pages. That cost scales
 * with the size of the BOM, not with the length of the list, so it fails
 * exactly when the system starts being used properly.
 *
 * This resolves the same values in three small queries that touch only the
 * first row of each file.
 */
class ExcelFileSummaryService
{
    /**
     * Header names the list column headings map onto.
     */
    private const FIELDS = [
        'Buyer Name',
        'Season Name',
        'Style Name',
        'Contract Number',
        'Contract Shipment Date',
    ];

    /**
     * @param  Collection<int, ExcelFile>|iterable  $files
     * @return array<int, array<string, string>> keyed by excel_file_id
     */
    public function for(iterable $files): array
    {
        $fileIds = collect($files)->pluck('id')->filter()->values();

        $blank = array_fill_keys(self::FIELDS, '');

        if ($fileIds->isEmpty()) {
            return [];
        }

        $summaries = $fileIds->mapWithKeys(fn ($id) => [$id => $blank])->all();

        // 1. The first row of each file, by row_number. Only ids are selected,
        //    so this stays cheap however many rows a file has.
        $firstRowByFile = ExcelRow::query()
            ->whereIn('excel_file_id', $fileIds->all())
            ->orderBy('row_number')
            ->get(['id', 'excel_file_id'])
            ->groupBy('excel_file_id')
            ->map(fn ($rows) => $rows->first()->id);

        if ($firstRowByFile->isEmpty()) {
            return $summaries;
        }

        // 2. The header ids behind the five column names.
        $headers = ExcelHeader::query()
            ->whereIn('header_name', self::FIELDS)
            ->pluck('header_name', 'id');

        if ($headers->isEmpty()) {
            return $summaries;
        }

        // 3. Only those cells, only on those rows.
        $cells = ExcelCell::query()
            ->whereIn('row_id', $firstRowByFile->values()->all())
            ->whereIn('header_id', $headers->keys()->all())
            ->get(['row_id', 'header_id', 'value'])
            ->groupBy('row_id');

        foreach ($firstRowByFile as $fileId => $rowId) {
            foreach ($cells->get($rowId, collect()) as $cell) {
                $field = $headers[$cell->header_id] ?? null;

                if ($field !== null && ($summaries[$fileId][$field] ?? '') === '') {
                    $summaries[$fileId][$field] = (string) ($cell->value ?? '');
                }
            }
        }

        return $summaries;
    }
}
