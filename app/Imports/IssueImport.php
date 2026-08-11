<?php

namespace App\Imports;

use App\Models\IndentPerson;
use App\Models\IndentSection;
use App\Models\IssueApprover;
use App\Models\ItemCategory;
use App\Models\StockItem;
use App\Services\GeneralStockReportService;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Bulk consumption upload for General Stock, and the format of its downloadable
 * template.
 *
 * Follows ReceivingImport closely and deliberately: headings matched by NAME
 * rather than position, the heading row searched for rather than assumed, rows
 * grouped into requisitions, and a requisition as the unit of success — one bad
 * line rejects its own requisition and leaves the rest of the file importable.
 *
 * ONE THING IS GENUINELY DIFFERENT, and it is why this is not a copy.
 * Receiving adds stock, so no line of it can fail on a balance. Issuing removes
 * stock, and the manual form refuses any submission that would take an item
 * below zero. A file can hold several requisitions drawing on the SAME item, so
 * checking each one against the balance in isolation would pass three
 * requisitions for 40 against 100 in stock and quietly leave it at -20. Demand
 * is therefore accumulated across the whole file before anything is accepted —
 * see settle().
 *
 * Masters are matched, never created. The manual form does create them, because
 * a user typing a new Indent Section there has said so explicitly and the
 * browser marks it. A spreadsheet carries no such signal, so a misspelling would
 * become a permanent section nobody chose — the same reasoning that stops an
 * unknown item name becoming an item.
 */
class IssueImport implements ToArray, WithCustomCsvSettings
{
    /**
     * Template columns, in the order the Record Issue form asks for them.
     * Also the heading row of the sample template download, so the two cannot
     * disagree.
     *
     * Month is written for people pasting from their own workbook and is never
     * read — stock_issues derives it from the issue date. Uom is read only to
     * cross-check the item master.
     *
     * @var list<string>
     */
    public const COLUMNS = [
        'Issue Date*',
        'Month',
        'Indent Section',
        'Indent Person',
        'Approved By',
        'Requisition Number',
        'Item Name*',
        'Uom',
        'Category',
        'Issued Qty*',
        'Type',
        'Remarks',
    ];

    /**
     * Header spellings accepted for each field, normalised by self::key().
     * The first entry of each list is what the template writes.
     *
     * "Month" is deliberately absent: it is a display column, and mapping it
     * would imply the importer does something with it.
     *
     * @var array<string, list<string>>
     */
    private const HEADINGS = [
        'issue_date' => ['issuedate', 'date', 'issuedt'],
        'indent_section' => ['indentsection', 'section', 'department', 'dept'],
        'indent_person' => ['indentperson', 'person', 'issuedto', 'requestedby'],
        'approver' => ['approvedby', 'approver', 'approval'],
        'requisition_no' => ['requisitionnumber', 'requisitionno', 'reqno', 'requisition'],
        'item_name' => ['itemname', 'nameofitem', 'nameofitems', 'item', 'particulars'],
        'uom' => ['uom', 'unit'],
        'category' => ['category', 'itemcategory'],
        'qty' => ['issuedqty', 'qty', 'quantity', 'issueqty'],
        'type' => ['type', 'requisitiontype', 'newreplace'],
        'remarks' => ['remarks', 'remark', 'note', 'notes'],
    ];

    /** Fields without which a row cannot be read at all. */
    private const REQUIRED_HEADINGS = ['issue_date', 'item_name', 'qty'];

    /**
     * One example row, so the template shows the expected shape. Marked with
     * EXAMPLE_PREFIX and skipped on import — a user who fills in rows beneath
     * it without deleting it would otherwise issue stock against a junk line.
     */
    public const SAMPLE_ROW = [
        '2026-08-01', 'Aug-26', 'Cutting', 'Rafiq Islam', 'Store Manager', 'REQ-1042',
        'EXAMPLE — delete this row', 'Pkt', 'Needle', 5, 'New', 'Optional note',
    ];

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
     * Parse and validate a sheet into ready-to-write requisitions.
     *
     * @param  array<int, array<int, mixed>>  $rows
     * @return array{requisitions: list<array<string, mixed>>, errors: list<string>, skipped: list<string>, notes: list<string>}
     */
    public static function parse(array $rows): array
    {
        $blank = ['requisitions' => [], 'errors' => [], 'skipped' => [], 'notes' => []];

        [$headerIndex, $map] = self::locateHeader($rows);

        if ($headerIndex === null) {
            return $blank + ['errors' => [
                'No heading row was found. The sheet needs a row naming at least Issue Date, Item Name and Issued Qty — download the sample template to see the expected columns.',
            ]];
        }

        $missing = array_values(array_diff(self::REQUIRED_HEADINGS, array_keys($map)));

        if ($missing) {
            return $blank + ['errors' => [
                'The heading row is missing these required columns: '
                    .implode(', ', array_map(fn ($f) => self::label($f), $missing)).'.',
            ]];
        }

        // One query each, not one per row.
        $items = StockItem::get(['id', 'name', 'uom', 'category'])
            ->keyBy(fn (StockItem $item) => mb_strtolower(trim($item->name)));

        $masters = [
            'indent_section' => self::masterIndex(IndentSection::class),
            'indent_person' => self::masterIndex(IndentPerson::class),
            'approver' => self::masterIndex(IssueApprover::class),
            'category' => self::masterIndex(ItemCategory::class),
        ];

        $alreadyRecorded = self::recordedRequisitionKeys();

        /** @var array<string, array<string, mixed>> $groups */
        $groups = [];
        $skipped = [];

        foreach ($rows as $index => $row) {
            if ($index <= $headerIndex || self::isBlank($row)) {
                continue;
            }

            // The row number as the user sees it in Excel.
            $line = $index + 1;

            $cell = fn (string $field) => isset($map[$field]) ? ($row[$map[$field]] ?? null) : null;

            $itemName = self::text($cell('item_name'));

            // A repeated heading row, or a subtotal line carrying figures but
            // no item — neither is data, and neither is an error worth raising.
            if ($itemName === null) {
                continue;
            }

            if (str_starts_with(mb_strtolower($itemName), self::EXAMPLE_PREFIX)) {
                $skipped[] = 'Row '.$line.': the template example row was ignored.';

                continue;
            }

            $issueDate = self::date($cell('issue_date'));

            // The five header fields of the manual form. Rows sharing all five
            // are the item lines of ONE requisition, exactly as the form posts
            // them. A blank Requisition Number is normal — it is optional there
            // too — so the other four carry the grouping when it is empty.
            $sectionName = self::text($cell('indent_section'), 160);
            $personName = self::text($cell('indent_person'), 160);
            $approverName = self::text($cell('approver'), 160);
            $requisitionNo = self::text($cell('requisition_no'), 100);

            $groupKey = implode('|', [
                is_string($issueDate) ? $issueDate : 'invalid-'.$line,
                mb_strtolower(trim((string) $requisitionNo)),
                mb_strtolower(trim((string) $sectionName)),
                mb_strtolower(trim((string) $personName)),
                mb_strtolower(trim((string) $approverName)),
            ]);

            if (! isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'issue_date' => $issueDate,
                    'requisition_no' => $requisitionNo,
                    'section_name' => $sectionName,
                    'person_name' => $personName,
                    'approver_name' => $approverName,
                    'indent_section_id' => null,
                    'indent_person_id' => null,
                    'issue_approver_id' => null,
                    'first_line' => $line,
                    'lines' => [],
                    'errors' => [],
                    'notes' => [],
                ];
            }

            $group = &$groups[$groupKey];
            $group['last_line'] = $line;

            if ($issueDate === false) {
                $group['errors'][] = 'Row '.$line.': Issue Date is not a valid date. Use YYYY-MM-DD.';
                unset($group);

                continue;
            }

            if ($issueDate === null) {
                $group['errors'][] = 'Row '.$line.': Issue Date is required.';
                unset($group);

                continue;
            }

            // Header masters. Matched, never created — see the class comment.
            // Resolved on the group rather than the line because they are one
            // per requisition, and reported once for the same reason.
            $headerMasters = [
                'indent_section' => ['Indent Section', $sectionName, 'indent_section_id'],
                'indent_person' => ['Indent Person', $personName, 'indent_person_id'],
                'approver' => ['Approved By', $approverName, 'issue_approver_id'],
            ];

            $headerFailed = false;

            foreach ($headerMasters as $field => [$label, $name, $target]) {
                if ($name === null) {
                    continue;
                }

                $id = $masters[$field][mb_strtolower(trim($name))] ?? null;

                if ($id === null) {
                    $group['errors'][] = 'Row '.$line.': "'.$name.'" is not in the '.$label
                        .' list. Add it under Issue Setup first, or correct the spelling.';
                    $headerFailed = true;

                    continue;
                }

                $group[$target] = $id;
            }

            if ($headerFailed) {
                unset($group);

                continue;
            }

            $item = $items->get(mb_strtolower($itemName));

            if (! $item) {
                // Never auto-created, for the reason ReceivingImport gives: an
                // item invented from a typo would carry no uom, category or
                // safety level and would appear in the Stock Report as real.
                $group['errors'][] = 'Row '.$line.': "'.$itemName.'" is not in the item master. Add it under Items first, or correct the spelling.';
                unset($group);

                continue;
            }

            $qty = self::number($cell('qty'));

            if ($qty === false) {
                $group['errors'][] = 'Row '.$line.': Issued Qty must be a number.';
                unset($group);

                continue;
            }

            if ($qty === null || $qty <= 0) {
                $group['errors'][] = 'Row '.$line.': Issued Qty must be greater than zero ("'.$itemName.'").';
                unset($group);

                continue;
            }

            // Category is a REAL field on an issue line, not a cross-check: the
            // manual form stores it per line, so the file's value is kept. That
            // is the one place this diverges from Receiving, where the same
            // column is only ever compared against the item master.
            $categoryName = self::text($cell('category'), 160);
            $categoryId = null;

            if ($categoryName !== null) {
                $categoryId = $masters['category'][mb_strtolower($categoryName)] ?? null;

                if ($categoryId === null) {
                    $group['errors'][] = 'Row '.$line.': "'.$categoryName.'" is not in the Category list. Add it under Issue Setup first, or correct the spelling.';
                    unset($group);

                    continue;
                }
            }

            $type = self::text($cell('type'), 20);

            if ($type !== null) {
                $matched = null;

                foreach (\App\Models\StockIssue::REQUISITION_TYPES as $allowed) {
                    if (mb_strtolower($type) === mb_strtolower($allowed)) {
                        $matched = $allowed;
                        break;
                    }
                }

                if ($matched === null) {
                    $group['errors'][] = 'Row '.$line.': Type must be '
                        .implode(' or ', \App\Models\StockIssue::REQUISITION_TYPES).', or blank.';
                    unset($group);

                    continue;
                }

                $type = $matched;
            }

            // Uom is reference-only — stock_issues has no column for it, and
            // the item master is the authority. A difference is worth saying
            // and never worth losing a requisition over.
            $fileUom = self::text($cell('uom'), 50);

            if ($fileUom !== null && $item->uom && mb_strtolower($fileUom) !== mb_strtolower(trim($item->uom))) {
                $group['notes'][] = 'Row '.$line.': Uom in the file is "'.$fileUom.'" but "'.$itemName
                    .'" is held in '.$item->uom.'. The item master was used.';
            }

            $group['lines'][] = [
                'stock_item_id' => $item->id,
                'item_name' => $item->name,
                'qty' => $qty,
                'item_category_id' => $categoryId,
                'requisition_type' => $type,
                'remarks' => self::text($cell('remarks'), 1000),
                'line' => $line,
            ];

            unset($group);
        }

        return self::settle($groups, $alreadyRecorded, $skipped);
    }

    /**
     * Turn the collected groups into requisitions to write and requisitions to
     * report as broken.
     *
     * This is where the cumulative stock check lives. Demand is added up across
     * the whole file, in file order, and a requisition is refused as soon as
     * accepting it would take an item past what is actually available. Its
     * demand is then NOT counted, so one over-large requisition does not
     * cascade into refusing every later one for the same item.
     *
     * @param  array<string, array<string, mixed>>  $groups
     * @param  array<string, true>  $alreadyRecorded
     * @param  list<string>  $skipped
     * @return array{requisitions: list<array<string, mixed>>, errors: list<string>, skipped: list<string>, notes: list<string>}
     */
    private static function settle(array $groups, array $alreadyRecorded, array $skipped): array
    {
        $requisitions = [];
        $errors = [];
        $notes = [];

        // Only groups that are otherwise sound can consume stock, so the
        // balance is read for their items alone.
        $wanted = [];

        foreach ($groups as $group) {
            if ($group['errors'] || empty($group['lines'])) {
                continue;
            }

            foreach ($group['lines'] as $line) {
                $wanted[(int) $line['stock_item_id']] = true;
            }
        }

        $available = self::availableStock(array_keys($wanted));

        // How much of each item this file has already committed.
        $taken = [];

        foreach ($groups as $group) {
            $label = self::describe($group);

            // Every line of a requisition goes in together or none of it does,
            // the same rule the manual form applies to its item table.
            if ($group['errors']) {
                $errors[] = $label.' was not imported:';

                foreach ($group['errors'] as $message) {
                    $errors[] = '   '.$message;
                }

                continue;
            }

            if (empty($group['lines'])) {
                continue;
            }

            // Before the stock check, deliberately. A requisition already in
            // Issue History issues nothing, so letting it reach the balance
            // would have it consume headroom a genuine later requisition needs
            // and then be skipped anyway — refusing a real issue on the
            // strength of one that is not going to happen.
            $recordedKey = self::recordedKey(
                $group['requisition_no'],
                $group['issue_date'],
                $group['indent_section_id'],
                $group['indent_person_id'],
                $group['issue_approver_id'],
                self::itemSetKey($group['lines'])
            );

            if (isset($alreadyRecorded[$recordedKey])) {
                $skipped[] = $label.' is already recorded in Issue History and was skipped.';

                continue;
            }

            // What this requisition asks for, summed per item: one requisition
            // can list the same item twice.
            $asks = [];

            foreach ($group['lines'] as $line) {
                $id = (int) $line['stock_item_id'];
                $asks[$id]['qty'] = ($asks[$id]['qty'] ?? 0) + (float) $line['qty'];
                $asks[$id]['name'] = $line['item_name'];
            }

            $shortfalls = [];

            foreach ($asks as $id => $ask) {
                $stock = $available[$id] ?? 0.0;
                $alreadyTaken = $taken[$id] ?? 0.0;
                $remaining = $stock - $alreadyTaken;

                if ($ask['qty'] > $remaining + 0.00001) {
                    $shortfalls[] = '   '.$ask['name'].': asked for '.self::fmt($ask['qty'])
                        .', only '.self::fmt(max($remaining, 0)).' available'
                        .($alreadyTaken > 0
                            ? ' after '.self::fmt($alreadyTaken).' already issued by earlier rows in this file'
                            : '')
                        .'.';
                }
            }

            if ($shortfalls) {
                $errors[] = $label.' was not imported — not enough stock:';

                foreach ($shortfalls as $message) {
                    $errors[] = $message;
                }

                continue;
            }

            foreach ($asks as $id => $ask) {
                $taken[$id] = ($taken[$id] ?? 0.0) + $ask['qty'];
            }

            foreach ($group['notes'] as $note) {
                $notes[] = $note;
            }

            $requisitions[] = [
                'issue_date' => $group['issue_date'],
                'requisition_no' => $group['requisition_no'],
                'indent_section_id' => $group['indent_section_id'],
                'indent_person_id' => $group['indent_person_id'],
                'issue_approver_id' => $group['issue_approver_id'],
                'lines' => $group['lines'],
                'label' => $label,
            ];
        }

        return ['requisitions' => $requisitions, 'errors' => $errors, 'skipped' => $skipped, 'notes' => $notes];
    }

    /**
     * Closing stock per item, from the same service the Stock Report and the
     * manual issue form read — so a refused import can always be explained by a
     * number the user can go and look at.
     *
     * @param  list<int>  $itemIds
     * @return array<int, float>
     */
    private static function availableStock(array $itemIds): array
    {
        if (empty($itemIds)) {
            return [];
        }

        return app(GeneralStockReportService::class)
            ->rows(now()->startOfMonth(), ['item_ids' => $itemIds, 'only_active' => false])
            ->mapWithKeys(fn ($row) => [(int) $row['item']->id => (float) $row['stock_as_on']])
            ->all();
    }

    /**
     * Requisitions already in stock_issues, so a re-uploaded file issues
     * nothing twice.
     *
     * Read in two passes, for the same reason ReceivingImport reads challans in
     * two — the two kinds of requisition are not identified the same way:
     *
     *   - A NUMBERED requisition is identified by its number, with the date and
     *     the three Issue Setup masters alongside it. requisition_no carries no
     *     unique index and is typed by hand, so it is scoped rather than
     *     trusted on its own.
     *   - An UNNUMBERED one has no such reference, and date + section + person
     *     + approver alone is not enough: one section can draw twice in a day
     *     against two separate slips, and treating the second as a repeat would
     *     silently drop a real issue. Its item set joins the key, so a repeat is
     *     only a repeat when the same items are on it.
     *
     * stock_issues has no rv_no, so unnumbered rows are gathered by the same
     * four fields the import groups incoming rows on — the closest analogue to
     * the rv_no grouping receiving uses.
     *
     * @return array<string, true>
     */
    private static function recordedRequisitionKeys(): array
    {
        $keys = \App\Models\StockIssue::query()
            ->whereNotNull('requisition_no')
            ->where('requisition_no', '<>', '')
            ->select('requisition_no', 'issue_date', 'indent_section_id', 'indent_person_id', 'issue_approver_id')
            ->distinct()
            ->get()
            ->mapWithKeys(fn ($row) => [
                self::recordedKey(
                    $row->requisition_no,
                    $row->issue_date?->toDateString(),
                    $row->indent_section_id,
                    $row->indent_person_id,
                    $row->issue_approver_id
                ) => true,
            ])
            ->all();

        $unnumbered = \App\Models\StockIssue::query()
            ->where(fn ($q) => $q->whereNull('requisition_no')->orWhere('requisition_no', ''))
            ->get(['requisition_no', 'issue_date', 'indent_section_id', 'indent_person_id', 'issue_approver_id', 'stock_item_id'])
            ->groupBy(fn ($row) => implode('|', [
                $row->issue_date?->toDateString(),
                (string) $row->indent_section_id,
                (string) $row->indent_person_id,
                (string) $row->issue_approver_id,
            ]));

        foreach ($unnumbered as $rows) {
            $first = $rows->first();

            $keys[self::recordedKey(
                null,
                $first->issue_date?->toDateString(),
                $first->indent_section_id,
                $first->indent_person_id,
                $first->issue_approver_id,
                self::itemSetKey($rows->all())
            )] = true;
        }

        return $keys;
    }

    /**
     * How a requisition is recognised as one already recorded.
     *
     * The item set participates ONLY when there is no requisition number. With
     * a number present it would do harm rather than good: adding a forgotten
     * line to a requisition already entered would change its item set, the
     * requisition would no longer look recorded, and re-uploading the file
     * would issue the whole thing a second time.
     */
    private static function recordedKey(
        ?string $requisitionNo,
        ?string $date,
        ?int $sectionId,
        ?int $personId,
        ?int $approverId,
        string $itemSet = ''
    ): string {
        $requisitionNo = mb_strtolower(trim((string) $requisitionNo));

        return implode('|', [
            $requisitionNo,
            (string) $date,
            (string) $sectionId,
            (string) $personId,
            (string) $approverId,
            $requisitionNo === '' ? $itemSet : '',
        ]);
    }

    /**
     * A requisition's items as one comparable value: distinct item ids, sorted,
     * so the same items listed in a different order still match.
     *
     * Ids rather than names — both sides of the comparison have already
     * resolved their item through the master, so an id cannot disagree with
     * itself over spelling or letter case the way a name can.
     *
     * @param  array<int, array<string, mixed>|\App\Models\StockIssue>  $lines
     */
    private static function itemSetKey(array $lines): string
    {
        $ids = [];

        foreach ($lines as $line) {
            $ids[] = (int) (is_array($line) ? $line['stock_item_id'] : $line->stock_item_id);
        }

        $ids = array_values(array_unique($ids));
        sort($ids);

        return implode(',', $ids);
    }

    /** How a requisition is named in every message about it. */
    private static function describe(array $group): string
    {
        $parts = array_filter([
            $group['requisition_no'] ? 'Requisition '.$group['requisition_no'] : null,
            is_string($group['issue_date']) ? $group['issue_date'] : null,
            $group['section_name'],
            $group['person_name'],
        ]);

        $rows = $group['first_line'] === ($group['last_line'] ?? $group['first_line'])
            ? 'row '.$group['first_line']
            : 'rows '.$group['first_line'].'-'.$group['last_line'];

        return ($parts ? implode(' · ', $parts) : 'Issue').' ('.$rows.')';
    }

    /**
     * name (lowercased) => id, for one of the Issue Setup masters.
     *
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $model
     * @return array<string, int>
     */
    private static function masterIndex(string $model): array
    {
        return $model::query()
            ->get(['id', 'name'])
            ->mapWithKeys(fn ($row) => [mb_strtolower(trim((string) $row->name)) => (int) $row->id])
            ->all();
    }

    /**
     * Find the heading row and map each known field to its column index.
     *
     * @param  array<int, array<int, mixed>>  $rows
     * @return array{0: int|null, 1: array<string, int>}
     */
    private static function locateHeader(array $rows): array
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
    private static function mapHeadings(array $row): array
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

    /** Heading text reduced to letters and digits: "Issued Qty*" -> "issuedqty". */
    private static function key(string $heading): string
    {
        return preg_replace('/[^a-z0-9]/', '', mb_strtolower(trim($heading))) ?? '';
    }

    private static function label(string $field): string
    {
        return match ($field) {
            'issue_date' => 'Issue Date',
            'item_name' => 'Item Name',
            'qty' => 'Issued Qty',
            default => $field,
        };
    }

    private static function fmt(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ','), '0'), '.');
    }

    /** @param array<int, mixed> $row */
    private static function isBlank(array $row): bool
    {
        foreach ($row as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }

    private static function text(mixed $value, int $limit = 255): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $limit);
    }

    /** @return float|null|false  null when blank, false when not a number */
    private static function number(mixed $value): float|null|false
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $value = str_replace([',', ' '], '', $value);

        return is_numeric($value) ? (float) $value : false;
    }

    /** @return string|null|false  null when blank, false when unparseable */
    private static function date(mixed $value): string|null|false
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        // A real Excel date cell arrives as a serial number, not a string.
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
