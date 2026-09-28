<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Requirement vs Issue workbook: an "All" sheet, then one sheet per section
 * in the result, each laid out like the Store's "Requirement against
 * consume qty" file. Follows the screen's filters exactly — it is handed the
 * same rows.
 */
class RequirementVsIssueExport implements WithMultipleSheets
{
    use Exportable;

    /** @param array<string, mixed> $data payload from RequirementVsIssueController */
    public function __construct(private readonly array $data)
    {
    }

    /** @return list<RequirementVsIssueSheet> */
    public function sheets(): array
    {
        $sheets = [new RequirementVsIssueSheet($this->data, 'All', 'All', $this->data['rows'])];
        $used = ['all' => true];

        $bySection = $this->data['rows']
            ->groupBy(fn ($r) => $r['section_id'] ?? 'na')
            ->sortBy(fn ($rows) => $rows->first()['section_id'] === null ? "\u{FFFF}" : mb_strtolower($rows->first()['section_name']));

        foreach ($bySection as $rows) {
            $name = $rows->first()['section_id'] === null ? 'Not assigned' : $rows->first()['section_name'];
            $sheets[] = new RequirementVsIssueSheet($this->data, $this->uniqueTitle($name, $used), $name, $rows->values());
        }

        return $sheets;
    }

    /**
     * Excel sheet names: at most 31 characters, none of : \ / ? * [ ], and
     * unique ignoring case — duplicate section names (Line-04 twice) get a
     * number.
     */
    private function uniqueTitle(string $name, array &$used): string
    {
        $base = mb_substr(trim(str_replace([':', '\\', '/', '?', '*', '[', ']'], '-', $name)) ?: 'Section', 0, 28);
        $title = $base;

        for ($i = 2; isset($used[mb_strtolower($title)]); $i++) {
            $title = $base.' '.$i;
        }

        $used[mb_strtolower($title)] = true;

        return $title;
    }
}
