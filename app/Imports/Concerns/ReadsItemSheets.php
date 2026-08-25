<?php

namespace App\Imports\Concerns;

use App\Models\StockItem;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Reading a hand-built item sheet: find the heading row, map its columns, and
 * turn cells into values.
 *
 * Extracted from UnitPriceImport when a second importer needed the same
 * reading. Nothing here decides WHAT to write — every method answers a question
 * about the sheet, never about the item master — so a new importer inherits the
 * file handling and still has to state its own write rules in full.
 *
 * IDENTITY IS THE POINT OF SHARING THIS. Items are matched on name and brand
 * together, and the two importers agreeing on what counts as the same item is
 * not a convenience: 107 item names in this master appear more than once and
 * 106 of those carry two or more distinct brands. Two copies of this rule that
 * drifted apart would put a price on one make and a date on another.
 *
 * The composing class supplies four constants:
 *
 *   HEADINGS             field => list of accepted heading spellings
 *   REQUIRED_HEADINGS    fields without which the sheet cannot be read at all
 *   HEADER_SEARCH_LIMIT  how far down to look for the heading row
 *   EXAMPLE_PREFIX       item names treated as template placeholders
 */
trait ReadsItemSheets
{
    /**
     * name+brand => id for the whole item master.
     *
     * @return \Illuminate\Support\Collection<string, int>
     */
    protected static function itemIdentityMap()
    {
        return StockItem::get(['id', 'name', 'brand'])
            ->mapWithKeys(fn (StockItem $item) => [self::identity($item->name, $item->brand) => (int) $item->id]);
    }

    /**
     * What makes one item the same as another: name and brand, case- and
     * spacing-blind. Matches ItemMasterImport::identity() in behaviour — if the
     * two ever disagree, an imported value lands on the wrong make.
     */
    protected static function identity(?string $name, ?string $brand): string
    {
        $norm = fn ($value) => preg_replace('/\s+/', ' ', mb_strtolower(trim((string) $value)));

        return $norm($name).'|'.$norm($brand);
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows
     * @return array{0: int|null, 1: array<string, int>}
     */
    protected static function locateHeader(array $rows): array
    {
        $limit = min(count($rows), self::HEADER_SEARCH_LIMIT);

        for ($index = 0; $index < $limit; $index++) {
            $map = self::mapHeadings($rows[$index] ?? []);

            if (count(array_intersect(self::REQUIRED_HEADINGS, array_keys($map))) === count(self::REQUIRED_HEADINGS)) {
                return [$index, $map];
            }
        }

        return [null, []];
    }

    /**
     * @param  array<int, mixed>  $row
     * @return array<string, int>
     */
    protected static function mapHeadings(array $row): array
    {
        $map = [];

        foreach ($row as $column => $heading) {
            $key = self::key((string) $heading);

            if ($key === '') {
                continue;
            }

            foreach (self::HEADINGS as $field => $accepted) {
                // First column wins, so a sheet repeating a heading later does
                // not steal the mapping from the real one.
                if (! isset($map[$field]) && in_array($key, $accepted, true)) {
                    $map[$field] = $column;
                    break;
                }
            }
        }

        return $map;
    }

    /** A heading reduced to letters and digits, so spacing and "*" cannot matter. */
    protected static function key(string $heading): string
    {
        return preg_replace('/[^a-z0-9]/', '', mb_strtolower(trim($heading))) ?? '';
    }

    /** @param array<int, mixed> $row */
    protected static function isBlank(array $row): bool
    {
        foreach ($row as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }

    protected static function text(mixed $value, int $limit = 255): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $limit);
    }

    /** @return float|null|false  null when blank, false when not a number */
    protected static function number(mixed $value): float|null|false
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $value = str_replace([',', ' '], '', $value);

        return is_numeric($value) ? (float) $value : false;
    }

    /**
     * A date cell as an ISO string.
     *
     * Same two-branch reading the item, issue and receiving importers already
     * use: a real Excel date cell arrives as a serial NUMBER, not a string, so
     * a numeric value is converted through PhpSpreadsheet rather than parsed as
     * text — Carbon would read the serial 46000 as a year.
     *
     * @return string|null|false  null when blank, false when unparseable
     */
    protected static function date(mixed $value): string|null|false
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        if (is_numeric($value)) {
            try {
                return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $value))->toDateString();
            } catch (\Throwable $e) {
                return false;
            }
        }

        try {
            return Carbon::parse(trim((string) $value))->toDateString();
        } catch (\Throwable $e) {
            return false;
        }
    }
}
