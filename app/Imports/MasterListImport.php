<?php

namespace App\Imports;

use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;

/**
 * Reads a bulk upload for the Issue Setup masters into a plain array of rows.
 *
 * The file is intentionally forgiving: store staff export these lists from
 * their own spreadsheets, so anything with the names in the first column works,
 * with or without a header row. Only the first column is read.
 */
class MasterListImport implements ToArray, WithCustomCsvSettings
{
    /**
     * The delimiter is pinned rather than auto-detected. A one-column list of
     * names contains no commas at all, and PhpSpreadsheet's detection then
     * guesses the space character — which silently truncates "Line 04 Cutting"
     * to "Line". These files are one name per line, so a comma delimiter is
     * both correct for real CSVs and harmless for single-column ones.
     *
     * @return array<string, string>
     */
    public function getCsvSettings(): array
    {
        return ['delimiter' => ','];
    }

    /** Exact first-cell values that mean "this row is a column heading". */
    private const HEADER_WORDS = ['name', 'names', 'title', 'section', 'indent section',
        'person', 'indent person', 'approved by', 'approver', 'category', 'sl', 'sl no',
        'indent section name', 'indent person name', 'approved by name', 'category name',
        'approver name', 'section name', 'person name'];

    /**
     * Longest a real section / person / approver / category name can be.
     * Anything past this is a sentence, not a name — a title line or an
     * instruction the author left above the data.
     */
    private const MAX_NAME_LENGTH = 60;

    /**
     * Phrases that only ever appear in a file's own title or instruction rows.
     * Matched case-insensitively anywhere in the cell.
     *
     * @var list<string>
     */
    private const NOTE_PHRASES = ['bulk upload', 'template', 'dummy data', 'review before',
        'delete this row', 'example', 'do not', 'instruction', '.xlsx', '.csv'];

    /**
     * Is this row the file's own furniture — a title, an instruction sentence,
     * or a column heading — rather than a name to import?
     *
     * Files exported by hand routinely carry two or three such lines above the
     * data. Checking only the very first row (as this used to) let the rest
     * through, and they were imported as real master entries.
     */
    private static function isFurniture(string $value): bool
    {
        // "Indent Section Name*" — trailing marker on a required column.
        $plain = mb_strtolower(rtrim(trim($value), '*: '));

        if (in_array($plain, self::HEADER_WORDS, true)) {
            return true;
        }

        if (mb_strlen($value) > self::MAX_NAME_LENGTH) {
            return true;
        }

        foreach (self::NOTE_PHRASES as $phrase) {
            if (str_contains($plain, $phrase)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows
     * @return array<int, array<int, mixed>>
     */
    public function array(array $rows): array
    {
        return $rows;
    }

    /**
     * Flatten a sheet into a clean, de-duplicated list of names, plus whatever
     * was discarded as the file's own title / instruction / heading rows so the
     * screen can say what it ignored rather than dropping it silently.
     *
     * @param  array<int, array<int, mixed>>  $rows
     * @return array{names: list<string>, ignored: list<string>}
     */
    public static function parse(array $rows): array
    {
        $names = [];
        $ignored = [];

        foreach ($rows as $index => $row) {
            $value = trim((string) ($row[0] ?? ''));

            if ($value === '') {
                continue;
            }

            // Title and instruction lines are usually stacked above the header,
            // so this is checked over the whole opening block, not just row 1.
            // Past that a real entry called "Name" still imports normally.
            if ($index < 10 && self::isFurniture($value)) {
                $ignored[] = 'Row '.($index + 1).': ignored "'.mb_substr($value, 0, 60)
                    .(mb_strlen($value) > 60 ? '…' : '').'" — this looks like a heading or a note, not a name.';
                continue;
            }

            // First spelling wins. A file listing "Line-04 Cutting" and later
            // "line-04 cutting" should keep the properly-cased first one.
            $key = mb_strtolower($value);
            $names[$key] ??= mb_substr($value, 0, 150);
        }

        return ['names' => array_values($names), 'ignored' => $ignored];
    }
}
