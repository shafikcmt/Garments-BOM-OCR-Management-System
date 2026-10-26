<?php

namespace App\Services;

use App\Models\BuyerImportProfile;
use App\Models\ExcelHeader;
use Illuminate\Support\Collection;

/**
 * Works out how a buyer's Excel columns line up with the system's headers.
 *
 * Pure matching, no file or request handling, so the upload wizard and its tests
 * share one answer. Names are compared the way the rest of the app compares
 * them (HeaderAliasResolver::normalize) and every header also answers to the
 * alias lists in HeaderAliasResolver, so a buyer's "BOM Qty" finds "BOM Quantity"
 * without anyone mapping it.
 */
class ImportColumnMatcher
{
    /** Share of a saved profile's columns that must be present to pick it automatically. */
    public const PROFILE_MATCH_THRESHOLD = 0.7;

    /** How many rows from the top are searched for the header row. */
    public const HEADER_SCAN_ROWS = 20;

    public function __construct(private HeaderAliasResolver $resolver)
    {
    }

    public function key(?string $text): string
    {
        return (string) $this->resolver->normalize($text);
    }

    /**
     * Every normalised name a system header answers to.
     *
     * @return list<string>
     */
    public function headerKeys(ExcelHeader $header): array
    {
        $keys = collect([$header->header_name, $header->header_key, $header->formula_key])
            ->map(fn ($v) => $this->key($v))
            ->filter()
            ->unique();

        foreach (array_keys($this->resolver->aliases()) as $canonical) {
            $group = $this->resolver->matchKeysFor($canonical);

            if ($keys->intersect($group)->isNotEmpty()) {
                $keys = $keys->merge($group);
            }
        }

        return $keys->unique()->values()->all();
    }

    /**
     * The row that holds the column titles: the one with the most recognisable
     * text cells among the first rows. Falls back to row 1.
     *
     * @param  array<int, array<int, string>>  $rows  row number => (column index => text)
     * @param  Collection<int, ExcelHeader>  $headers
     */
    public function detectHeaderRow(array $rows, Collection $headers): int
    {
        return $this->scoreHeaderRow($rows, $headers)['row'];
    }

    /**
     * The best header-row candidate and how convincing it is, so several sheets
     * of one workbook can be compared and the real data sheet picked.
     *
     * @param  array<int, array<int, string>>  $rows
     * @param  Collection<int, ExcelHeader>  $headers
     * @return array{row: int, score: int}
     */
    public function scoreHeaderRow(array $rows, Collection $headers): array
    {
        $known = $headers->flatMap(fn (ExcelHeader $h) => $this->headerKeys($h))->flip();

        $best = 1;
        $bestScore = 0;

        foreach ($rows as $rowNumber => $cells) {
            if ($rowNumber > self::HEADER_SCAN_ROWS) {
                break;
            }

            $titles = collect($cells)->filter(fn ($v) => trim((string) $v) !== '' && ! is_numeric(trim((string) $v)));

            if ($titles->count() < 3) {
                continue;
            }

            $score = $titles->count() + 3 * $titles->filter(fn ($v) => $known->has($this->key($v)))->count();

            if ($score > $bestScore) {
                $best = (int) $rowNumber;
                $bestScore = $score;
            }
        }

        return ['row' => $best, 'score' => $bestScore];
    }

    /**
     * A system header for every source column, where one can be found.
     *
     * @param  array<int, string>  $sourceColumns  column index => title in the buyer's file
     * @param  Collection<int, ExcelHeader>  $headers  headers the wizard may fill
     * @return array<int, array{title: string, header_id: ?int, reason: string}>
     */
    public function suggest(array $sourceColumns, Collection $headers, ?BuyerImportProfile $profile = null): array
    {
        $byKey = [];
        foreach ($headers as $header) {
            foreach ($this->headerKeys($header) as $key) {
                $byKey[$key] ??= $header->id;
            }
        }

        $saved = $profile
            ? $profile->columns->keyBy('source_key')
            : collect();
        $validIds = $headers->pluck('id')->flip();

        $taken = [];
        $result = [];

        foreach ($sourceColumns as $index => $title) {
            $key = $this->key($title);
            $headerId = null;
            $reason = 'none';

            if ($saved->has($key)) {
                $savedId = $saved[$key]->excel_header_id;
                $headerId = $savedId && $validIds->has($savedId) ? (int) $savedId : null;
                $reason = 'profile';
            } elseif (isset($byKey[$key])) {
                $headerId = (int) $byKey[$key];
                $reason = 'name';
            }

            // One system header takes one column; a later duplicate stays unmapped.
            if ($headerId !== null && isset($taken[$headerId])) {
                $headerId = null;
                $reason = 'none';
            }

            if ($headerId !== null) {
                $taken[$headerId] = true;
            }

            $result[$index] = ['title' => $title, 'header_id' => $headerId, 'reason' => $reason];
        }

        return $result;
    }

    /**
     * The buyer's saved profile that best fits this file, if any fits well enough.
     *
     * @param  Collection<int, BuyerImportProfile>  $profiles  with columns loaded
     * @param  array<int, string>  $sourceColumns
     */
    public function bestProfile(Collection $profiles, array $sourceColumns): ?BuyerImportProfile
    {
        $present = collect($sourceColumns)->map(fn ($t) => $this->key($t))->flip();

        return $profiles
            ->map(function (BuyerImportProfile $profile) use ($present) {
                $total = $profile->columns->count();
                $hits = $profile->columns->filter(fn ($c) => $present->has($c->source_key))->count();

                return ['profile' => $profile, 'share' => $total > 0 ? $hits / $total : 0];
            })
            ->filter(fn ($row) => $row['share'] >= self::PROFILE_MATCH_THRESHOLD)
            ->sortByDesc('share')
            ->first()['profile'] ?? null;
    }
}
