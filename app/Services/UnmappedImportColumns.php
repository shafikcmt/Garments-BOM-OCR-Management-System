<?php

namespace App\Services;

use App\Models\ExcelHeader;
use App\Models\ImportUnmappedColumn;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * The columns of an imported file that the system does not know.
 *
 * A column is "unknown" when its title matches no active header of any
 * department by name, formula key or Other Name. A title that does match a
 * header but was skipped on purpose (for example another department's column)
 * is not recorded. Admin then teaches the name to a header (it becomes an Other
 * Name, so the next import matches it) or ignores it.
 */
class UnmappedImportColumns
{
    public function __construct(private ImportColumnMatcher $matcher)
    {
    }

    /**
     * Record the file's columns that were not imported and match nothing.
     * Never raises: the import has already succeeded.
     *
     * @param  array<int, string>  $titles  file column => title
     * @param  array<int, int>  $mapping  file column => header id (the imported columns)
     * @return int number of titles recorded
     */
    public function record(?int $buyerId, array $titles, array $mapping, ?int $fileId): int
    {
        try {
            $known = ExcelHeader::where('is_active', true)->get()->flatMap(fn (ExcelHeader $h) => $this->matcher->headerKeys($h))->flip();
            $count = 0;

            foreach ($titles as $column => $title) {
                if (isset($mapping[$column])) {
                    continue;
                }
                $key = $this->matcher->key($title);
                if ($key === '' || isset($known[$key])) {
                    continue;
                }

                $row = ImportUnmappedColumn::firstOrNew(['buyer_id' => $buyerId, 'title_key' => $key]);
                if ($row->exists) {
                    $row->times_seen++;
                    // Seen again after it was taught: it still matches nothing, so it is open again.
                    if ($row->status === ImportUnmappedColumn::MAPPED) {
                        $row->fill(['status' => ImportUnmappedColumn::OPEN, 'mapped_header_id' => null, 'resolved_by' => null, 'resolved_at' => null]);
                    }
                } else {
                    $row->fill(['title' => mb_substr(trim((string) $title), 0, 191), 'first_seen_at' => now()]);
                }
                $row->fill(['last_seen_at' => now(), 'last_file_id' => $fileId])->save();
                $count++;
            }

            return $count;
        } catch (\Throwable $e) {
            Log::warning('Unmapped column record failed: ' . $e->getMessage());

            return 0;
        }
    }

    /**
     * Teach the title to a header as an Other Name; every buyer's row with the
     * same title is resolved with it.
     *
     * @return int rows resolved
     */
    public function teach(string $titleKey, string $title, ExcelHeader $header, User $by): int
    {
        if (! in_array($this->matcher->key($title), array_map(fn ($a) => $this->matcher->key($a), $header->aliasList()), true)) {
            $header->update(['aliases' => trim(((string) $header->aliases) . "\n" . trim($title))]);
        }

        return ImportUnmappedColumn::where('title_key', $titleKey)->where('status', ImportUnmappedColumn::OPEN)->update([
            'status' => ImportUnmappedColumn::MAPPED, 'mapped_header_id' => $header->id, 'resolved_by' => $by->id, 'resolved_at' => now(),
        ]);
    }

    public function ignore(string $titleKey, User $by): int
    {
        return ImportUnmappedColumn::where('title_key', $titleKey)->where('status', ImportUnmappedColumn::OPEN)->update([
            'status' => ImportUnmappedColumn::IGNORED, 'resolved_by' => $by->id, 'resolved_at' => now(),
        ]);
    }

    public function reopen(string $titleKey): int
    {
        return ImportUnmappedColumn::where('title_key', $titleKey)->where('status', ImportUnmappedColumn::IGNORED)->update([
            'status' => ImportUnmappedColumn::OPEN, 'resolved_by' => null, 'resolved_at' => null,
        ]);
    }
}
