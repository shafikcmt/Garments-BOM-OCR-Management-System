<?php

namespace App\Imports;

use App\Imports\Concerns\ReadsItemSheets;
use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;

/**
 * Setting Unit Price across many items at once.
 *
 * A deliberately tiny importer, and the smallness is the safety. It reads three
 * columns and writes ONE FIELD. A stale file, a wrong column, a cell somebody
 * cleared — none of it can reach Uom, Category, Opening Stock, Safety Stock or
 * anything else, because this never writes them.
 *
 * That is why it exists rather than teaching ItemMasterImport to update on a
 * match. That importer creates and never updates, on purpose: it means no
 * re-uploaded file can silently rewrite the master. Making it update would put
 * every field on the table for every upload, and the first stale export with an
 * empty Safety Stock cell would null a figure somebody had pinned.
 *
 * IT MATCHES ON NAME AND BRAND TOGETHER, exactly as ItemMasterImport does, and
 * this is not optional here. In the current data 107 item names appear more
 * than once and 106 of those carry two or more distinct brands — 217 items,
 * one in six. "B/S Needle DPX17-09" is stocked as Generic and as Organ and they
 * are different items with different costs, so a name-only file would price the
 * wrong one and look like it had worked.
 *
 * IT NEVER CREATES. A row that matches nothing is a misspelled name or brand,
 * and inventing an item that has a price and no uom, category or safety level
 * would put a phantom into the Stock Report — the same reasoning the issue and
 * receiving imports use for refusing to coin an item.
 */
class UnitPriceImport implements ToArray, WithCustomCsvSettings
{
    /**
     * Heading location, column mapping and cell reading. Extracted when the
     * Stock Report's reference import needed the same reading — in particular
     * the SAME name+brand identity, so a price and a date can never land on
     * different makes of one item.
     */
    use ReadsItemSheets;

    /**
     * Three columns. Brand is not optional padding — see the class comment.
     *
     * @var list<string>
     */
    public const COLUMNS = [
        'Item Name*',
        'Brand/Specification',
        'Unit Price*',
    ];

    /**
     * Header spellings accepted for each field, normalised by self::key().
     * The first entry of each list is what the template writes; the rest let a
     * file built from the Item Master export be used as-is.
     *
     * @var array<string, list<string>>
     */
    private const HEADINGS = [
        'name' => ['itemname', 'nameofitem', 'item', 'particulars'],
        'brand' => ['brandspecification', 'brand', 'brandname', 'make', 'specification'],
        'price' => ['unitprice', 'price', 'rate', 'unitrate'],
    ];

    /** Fields without which a row cannot be read at all. */
    private const REQUIRED_HEADINGS = ['name', 'price'];

    /** One example row, so the expected shape is obvious. */
    public const SAMPLE_ROW = ['EXAMPLE — delete this row', 'Organ', 12.5];

    /** Item names starting with this are treated as template placeholders. */
    public const EXAMPLE_PREFIX = 'example';

    /** How far down the sheet to look for the heading row. */
    private const HEADER_SEARCH_LIMIT = 30;

    /** @return array<string, string> */
    public function getCsvSettings(): array
    {
        return ['delimiter' => ','];
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
     * Read a sheet into ready-to-apply price updates.
     *
     * @param  array<int, array<int, mixed>>  $rows
     * @return array{updates: list<array<string, mixed>>, errors: list<string>, skipped: list<string>}
     */
    public static function parse(array $rows): array
    {
        $blank = ['updates' => [], 'errors' => [], 'skipped' => []];

        [$headerIndex, $map] = self::locateHeader($rows);

        if ($headerIndex === null) {
            return array_merge($blank, ['errors' => [
                'No heading row was found. The sheet needs a row naming at least Item Name and Unit Price — download the sample template to see the expected columns.',
            ]]);
        }

        $items = self::itemIdentityMap();

        $updates = [];
        $errors = [];
        $skipped = [];
        $seen = [];

        foreach ($rows as $index => $row) {
            if ($index <= $headerIndex || self::isBlank($row)) {
                continue;
            }

            $line = $index + 1;
            $cell = fn (string $field) => isset($map[$field]) ? ($row[$map[$field]] ?? null) : null;

            $name = self::text($cell('name'));

            // A repeated heading row, or a trailing note — neither is data.
            if ($name === null) {
                continue;
            }

            if (str_starts_with(mb_strtolower($name), self::EXAMPLE_PREFIX)) {
                $skipped[] = 'Row '.$line.': the template example row was ignored.';

                continue;
            }

            $brand = self::text($cell('brand'));
            $label = '"'.$name.'" ('.($brand ?? 'no brand').')';
            $key = self::identity($name, $brand);

            $price = self::number($cell('price'));

            // A blank price is not a way of clearing one. Someone filling a
            // column of 1,241 rows will leave most of them empty on the first
            // pass, and reading that as "set these to nothing" would wipe every
            // price already recorded.
            if ($price === null) {
                $skipped[] = 'Row '.$line.': '.$label.' has no Unit Price, so it was left as it is.';

                continue;
            }

            if ($price === false || $price < 0) {
                $errors[] = 'Row '.$line.': Unit Price for '.$label.' must be a number of 0 or more.';

                continue;
            }

            if (! isset($items[$key])) {
                // Reported, never created — see the class comment. Named in full
                // so the spelling that failed is the spelling shown.
                $errors[] = 'Row '.$line.': '.$label.' is not in the item master. '
                    .'Check the Item Name and Brand/Specification against the exported list — an item stocked under two brands needs the right one.';

                continue;
            }

            if (isset($seen[$key])) {
                $skipped[] = 'Row '.$line.': '.$label.' is listed more than once in this file; the first price was used.';

                continue;
            }

            $seen[$key] = true;

            $updates[] = [
                'id' => $items[$key],
                'unit_price' => $price,
                'label' => $label,
            ];
        }

        return ['updates' => $updates, 'errors' => $errors, 'skipped' => $skipped];
    }
}
