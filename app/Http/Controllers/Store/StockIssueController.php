<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AuthorizesStoreCorrections;
use App\Http\Controllers\Concerns\ResolvesIssueSetupMasters;
use App\Exports\IssueTemplateExport;
use App\Imports\IssueImport;
use App\Models\IndentPerson;
use App\Models\IndentSection;
use App\Models\IssueApprover;
use App\Models\ItemCategory;
use App\Models\StockItem;
use App\Models\StockIssue;
use App\Services\GeneralStockReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;

/**
 * General Stock (module A) — requisition-style issue (Excel "Consumption"
 * sheet).
 *
 * General Stock has one issue type: every issue is against an item in the item
 * master. Buyer- and style-wise consumption belongs to the separate Buyer/Style
 * module and is not recorded here.
 *
 * Indent Section / Indent Person / Approved By / Category all come from the
 * Issue Setup masters and all four accept a typed-in value, which is saved to
 * its master on the way through (see ResolvesIssueSetupMasters). The selected
 * section and person names are also copied into the older free-text
 * `department` / `issued_to` columns so those stay readable.
 */
class StockIssueController extends Controller
{
    use AuthorizesStoreCorrections, ResolvesIssueSetupMasters;

    /** Section this controller belongs to, for the section-scoped correction
     *  permissions. The flat store.edit / store.delete still apply too. */
    protected string $storeSection = 'store.issues';

    /**
     * Header dropdowns, entered once per transaction. Each accepts an existing
     * id or a typed-in "new:<name>".
     *
     * Category is deliberately NOT here: it belongs to the item line, since one
     * requisition can cover items from several categories.
     */
    private const MASTER_FIELDS = [
        'indent_section_id' => IndentSection::class,
        'indent_person_id' => IndentPerson::class,
        'issue_approver_id' => IssueApprover::class,
    ];

    public function __construct(private readonly GeneralStockReportService $report)
    {
    }

    public function index(Request $request)
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'month' => ['nullable', 'date_format:Y-m'],
        ]);

        $issues = StockIssue::with(['stockItem', 'indentSection', 'indentPerson', 'approver', 'itemCategory', 'createdBy'])
            ->when($filters['search'] ?? null, function ($q, $search) {
                $like = '%'.$search.'%';
                // whereLike compiles to ILIKE on PostgreSQL, where a plain LIKE
                // is case-sensitive, and stays LIKE everywhere else.
                $q->where(fn ($w) => $w->whereLike('requisition_no', $like)
                    ->orWhereHas('stockItem', fn ($i) => $i->whereLike('name', $like)));
            })
            ->when($filters['month'] ?? null, function ($q, $month) {
                [$year, $m] = explode('-', $month);
                $q->whereYear('issue_date', $year)->whereMonth('issue_date', $m);
            })
            ->latest('issue_date')
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        ['edit' => $canEdit, 'delete' => $canDelete] = $this->storeCorrectionAbilities();

        return view('store.stock.issues', [
            'issues' => $issues,
            'items' => StockItem::where('is_active', true)->orderBy('name')
                ->get(['id', 'name', 'uom', 'item_category_id', 'brand']),
            'sections' => IndentSection::selectable()->get(['id', 'name']),
            'persons' => IndentPerson::selectable()->get(['id', 'name']),
            'approvers' => IssueApprover::selectable()->get(['id', 'name']),
            'categories' => ItemCategory::selectable()->get(['id', 'name']),
            'requisitionTypes' => StockIssue::REQUISITION_TYPES,
            'filters' => $filters,
            'canEdit' => $canEdit,
            'canDelete' => $canDelete,
        ]);
    }

    /**
     * Record one issue transaction covering one or more items.
     *
     * The header (date, section, person, approver, requisition) is entered
     * once; each item line is still saved as its own stock_issues row carrying
     * a copy of that header. Nothing about the table or the reports changes —
     * a five-item requisition simply becomes five rows that share a requisition
     * number, which is exactly what Issue History and the Consumption report
     * already expect.
     *
     * All-or-nothing inside a transaction: a requisition half-recorded, with no
     * clear record of which lines made it, is harder to unpick than a rejected
     * submission.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'issue_date' => ['required', 'date'],
            // The four masters accept either an existing id or "new:<name>",
            // so they are validated as free strings and resolved below.
            'indent_section_id' => ['nullable', 'string', 'max:160'],
            'indent_person_id' => ['nullable', 'string', 'max:160'],
            'issue_approver_id' => ['nullable', 'string', 'max:160'],
            'requisition_no' => ['nullable', 'string', 'max:100'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.stock_item_id' => ['required', 'exists:stock_items,id'],
            'items.*.qty' => ['required', 'numeric', 'min:0.0001'],
            'items.*.item_category_id' => ['nullable', 'string', 'max:160'],
            // New / Replace is per line, not per requisition: one issue can
            // replace a worn part on one line and hand out a new one on the
            // next. Constrained to the two known values so a hand-crafted post
            // cannot put free text into a column the reports filter on.
            'items.*.requisition_type' => ['nullable', Rule::in(StockIssue::REQUISITION_TYPES)],
            'items.*.remarks' => ['nullable', 'string', 'max:1000'],
        ], [
            'items.required' => 'Add at least one item to issue.',
            'items.min' => 'Add at least one item to issue.',
        ], $this->lineAttributes($request));

        // Header masters resolved once, not once per line.
        $header = [];
        foreach (self::MASTER_FIELDS as $field => $model) {
            $header[$field] = $this->resolveMasterValue($model, $data[$field] ?? null);
        }

        // Denormalised copies for the pre-existing free-text columns.
        $header['department'] = $header['indent_section_id']
            ? IndentSection::find($header['indent_section_id'])?->name
            : null;
        $header['issued_to'] = $header['indent_person_id']
            ? IndentPerson::find($header['indent_person_id'])?->name
            : null;

        $header['issue_date'] = $data['issue_date'];
        $header['requisition_no'] = $data['requisition_no'] ?? null;
        $header['created_by'] = auth()->id();

        $itemIds = [];

        DB::transaction(function () use ($data, $header, &$itemIds) {
            // Nothing is written until every line is covered by stock. Inside
            // the transaction, and immediately before the first insert, so the
            // position it reads is the closest thing to the one it writes
            // against.
            $this->assertWithinStock($data['items']);

            foreach ($data['items'] as $line) {
                StockIssue::create($header + [
                    'stock_item_id' => $line['stock_item_id'],
                    'qty' => $line['qty'],
                    // Category and type are per line: they follow the item, not
                    // the header.
                    'item_category_id' => $this->resolveMasterValue(ItemCategory::class, $line['item_category_id'] ?? null),
                    'requisition_type' => $line['requisition_type'] ?? null,
                    'remarks' => $line['remarks'] ?? null,
                ]);

                $itemIds[] = (int) $line['stock_item_id'];
            }
        });

        $count = count($data['items']);
        $message = $count === 1 ? 'Issue recorded.' : $count.' item(s) issued on this requisition.';

        // Reordering advice about what is LEFT, raised after a valid issue —
        // not a shortfall check. An issue that would have gone negative was
        // already refused by assertWithinStock above, so this now reports items
        // the issue emptied or pushed under their safety level. Reported per
        // item so a five-line requisition names exactly which need reordering.
        $warnings = $this->lowStockWarnings(array_unique($itemIds));

        if ($warnings) {
            return back()
                ->with('success', $message)
                ->with('warning', count($warnings) === 1
                    ? $warnings[0].' Please raise a purchase requisition.'
                    : count($warnings).' of the issued items need a purchase requisition.')
                ->with('issue_stock_warnings', $warnings);
        }

        return back()->with('success', $message);
    }

    public function template()
    {
        return Excel::download(new IssueTemplateExport, 'Issue-Bulk-Upload-Template.xlsx');
    }

    /**
     * Bulk consumption upload — many requisitions, many item lines each, in one
     * file.
     *
     * The REQUISITION is the unit of success, not the file: a requisition with
     * a bad line is reported and left out, and every other requisition in the
     * same file still goes in. Same arrangement as the receiving import, and
     * the same reason — one unknown item name should not cost somebody a day's
     * typing.
     *
     * The stock check that makes this different from receiving happens in
     * IssueImport::settle(), which accumulates demand across the whole file
     * before accepting anything. Issuing removes stock, and several
     * requisitions in one file can draw on the same item.
     *
     * Everything is written inside ONE transaction, so a file either lands as a
     * consistent set of requisitions or not at all.
     */
    public function import(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:8192'],
        ], [], ['file' => 'upload file']);

        try {
            $sheets = Excel::toArray(new IssueImport, $request->file('file'));
        } catch (\Throwable $e) {
            return back()->with('warning', 'That file could not be read. Please upload a CSV or Excel file based on the sample template.');
        }

        [
            'requisitions' => $requisitions,
            'errors' => $errors,
            'skipped' => $skipped,
            'notes' => $notes,
        ] = IssueImport::parse($sheets[0] ?? []);

        if (empty($requisitions)) {
            return back()
                ->with('warning', $errors
                    ? 'Nothing was imported. Please correct the rows listed below and upload the file again.'
                    : 'No issues were found in that file.')
                ->with('import_errors', $errors)
                ->with('import_skipped', $skipped)
                ->with('import_notes', $notes);
        }

        $userId = auth()->id();
        $lineCount = 0;

        DB::transaction(function () use ($requisitions, $userId, &$lineCount) {
            foreach ($requisitions as $requisition) {
                // Denormalised copies of the two names, exactly as the manual
                // form writes them, so an imported issue reads the same as a
                // typed one everywhere the free-text columns are shown.
                $header = [
                    'issue_date' => $requisition['issue_date'],
                    'requisition_no' => $requisition['requisition_no'],
                    'indent_section_id' => $requisition['indent_section_id'],
                    'indent_person_id' => $requisition['indent_person_id'],
                    'issue_approver_id' => $requisition['issue_approver_id'],
                    'department' => $requisition['indent_section_id']
                        ? IndentSection::find($requisition['indent_section_id'])?->name
                        : null,
                    'issued_to' => $requisition['indent_person_id']
                        ? IndentPerson::find($requisition['indent_person_id'])?->name
                        : null,
                    'created_by' => $userId,
                ];

                foreach ($requisition['lines'] as $line) {
                    StockIssue::create($header + [
                        'stock_item_id' => $line['stock_item_id'],
                        'qty' => $line['qty'],
                        'item_category_id' => $line['item_category_id'],
                        'requisition_type' => $line['requisition_type'],
                        'remarks' => $line['remarks'],
                    ]);

                    $lineCount++;
                }
            }
        });

        $count = count($requisitions);

        return back()
            ->with('success', $count.' '.($count === 1 ? 'requisition' : 'requisitions')
                .' imported, covering '.$lineCount.' item line(s).')
            // Errors are not a failure here — they name the requisitions that
            // were left out while the rest went in, so they stay on screen.
            ->with('import_errors', $errors)
            ->with('import_skipped', $skipped)
            ->with('import_notes', $notes);
    }

    /**
     * Reject the whole submission unless every line is covered by the stock
     * actually on hand.
     *
     * General Stock issues used to be recorded whatever the book balance said,
     * on the reasoning that the store must be able to write down what physically
     * left the shelf. That let the balance go negative, which no report can
     * present sensibly and no purchase plan can be built on. An issue is now
     * blocked at the point of entry instead, and a genuine shortfall has to be
     * received in first.
     *
     * Quantities are summed PER ITEM before comparing. Two lines asking for 30
     * of the same item against a stock of 50 are individually fine and together
     * are not, and it is the total that leaves the shelf.
     *
     * All-or-nothing: one short line rejects the submission, so the operator
     * fixes it and resubmits with every line intact rather than discovering half
     * a requisition was recorded.
     *
     * @param  array<int, array<string, mixed>>  $lines
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    private function assertWithinStock(array $lines): void
    {
        // Per item: how much this submission asks for, and which lines asked.
        $wanted = [];
        foreach ($lines as $index => $line) {
            $id = (int) $line['stock_item_id'];

            $wanted[$id]['qty'] = ($wanted[$id]['qty'] ?? 0) + (float) $line['qty'];
            $wanted[$id]['lines'][] = $index;
        }

        // One report call for every item on the form, not one per line. Same
        // service, same month and the same `stock_as_on` figure the Stock Report
        // prints and the Issue form's own warning already showed — so a blocked
        // issue can always be explained by a number the user can go and look at.
        $rows = $this->report->rows(now()->startOfMonth(), [
            'item_ids' => array_keys($wanted),
            'only_active' => false,
        ])->keyBy(fn ($row) => $row['item']->id);

        $format = fn ($v) => rtrim(rtrim(number_format((float) $v, 4, '.', ','), '0'), '.');

        $errors = [];
        foreach ($wanted as $id => $ask) {
            $row = $rows->get($id);

            // No row means no readable position for an item the validator just
            // confirmed exists. Treated as nothing available rather than waved
            // through — issuing against an unknown balance is the case this
            // check exists to stop.
            $stock = $row ? (float) $row['stock_as_on'] : 0.0;

            if ($ask['qty'] <= $stock) {
                continue;
            }

            $name = $row ? $row['item']->name : 'This item';
            $uom = $row && $row['item']->uom ? ' '.$row['item']->uom : '';

            $message = $name.' — only '.$format(max($stock, 0)).$uom.' in stock, cannot issue '.$format($ask['qty']).'.';

            // Flagged on every line that named the item, so the operator is not
            // sent hunting for which of two rows the message belongs to.
            foreach ($ask['lines'] as $index) {
                $errors['items.'.$index.'.qty'] = $message;
            }
        }

        if ($errors) {
            // The inline note under each qty box is easy to miss on a
            // fifteen-line requisition, so the same shortfalls are also flashed
            // for the summary the form shows above the table.
            session()->flash('issue_stock_errors', array_values(array_unique($errors)));

            throw \Illuminate\Validation\ValidationException::withMessages($errors);
        }
    }

    /**
     * Validation attribute names that point at a line number, so an error reads
     * "The item on line 3 field is required" instead of naming items.2.
     *
     * @return array<string, string>
     */
    private function lineAttributes(Request $request): array
    {
        $attributes = [
            'indent_section_id' => 'indent section',
            'indent_person_id' => 'indent person',
            'issue_approver_id' => 'approved by',
        ];

        foreach (array_keys((array) $request->input('items', [])) as $index) {
            $line = ((int) $index) + 1;
            $attributes['items.'.$index.'.stock_item_id'] = 'item on line '.$line;
            $attributes['items.'.$index.'.qty'] = 'issued qty on line '.$line;
            $attributes['items.'.$index.'.item_category_id'] = 'category on line '.$line;
            $attributes['items.'.$index.'.requisition_type'] = 'type on line '.$line;
        }

        return $attributes;
    }

    /**
     * Ready-to-show messages for whichever of the issued items is now at or
     * below its safety stock.
     *
     * @param  list<int>  $itemIds
     * @return list<string>
     */
    private function lowStockWarnings(array $itemIds): array
    {
        $alerting = [GeneralStockReportService::STATUS_OUT, GeneralStockReportService::STATUS_PLACE_ORDER];

        $warnings = [];
        foreach ($itemIds as $id) {
            $status = $this->stockStatusFor($id);

            if (in_array($status['status'], $alerting, true) && $status['message']) {
                $warnings[] = $status['message'];
            }
        }

        return $warnings;
    }

    /**
     * Live stock position for one item, read by the Issue form the moment an
     * item is chosen so the user is warned before confirming.
     *
     * Read-only. Uses the same service as the Consumable Stock Report, so the
     * warning always agrees with the report.
     */
    public function itemStatus(StockItem $stockItem): JsonResponse
    {
        return response()->json($this->stockStatusFor($stockItem->id) + [
            'uom' => $stockItem->uom,
            'category' => $stockItem->category,
        ]);
    }

    public function destroy(StockIssue $stockIssue)
    {
        $this->authorizeStoreDelete('stock issue');

        $stockIssue->delete();

        return back()->with('success', 'Issue entry removed.');
    }

    /**
     * Current-month stock position for one item, plus a ready-to-show message.
     *
     * @return array<string, mixed>
     */
    private function stockStatusFor(?int $stockItemId): array
    {
        $blank = ['status' => null, 'stock' => null, 'safety' => null, 'message' => null];

        if (! $stockItemId) {
            return $blank;
        }

        $row = $this->report->rows(now()->startOfMonth(), [
            'item_ids' => [$stockItemId],
            'only_active' => false,
        ])->first();

        if (! $row) {
            return $blank;
        }

        $format = fn ($v) => $v === null ? '—' : rtrim(rtrim(number_format((float) $v, 4, '.', ','), '0'), '.');

        $message = match ($row['status']) {
            GeneralStockReportService::STATUS_OUT =>
                'Out of Stock: '.$row['item']->name.' has no stock left ('.$format($row['stock_as_on']).' '.($row['item']->uom ?: 'qty').').',
            GeneralStockReportService::STATUS_PLACE_ORDER =>
                'Low Stock: '.$row['item']->name.' is at '.$format($row['stock_as_on']).' '.($row['item']->uom ?: 'qty')
                    .', below its Safety Stock Level of '.$format($row['safety']).'.',
            GeneralStockReportService::STATUS_LOW =>
                $row['item']->name.' is at '.$format($row['stock_as_on']).' '.($row['item']->uom ?: 'qty')
                    .', below its Re-order Level of '.$format($row['reorder']).'.',
            default => null,
        };

        return [
            'status' => $row['status'],
            'stock' => (float) $row['stock_as_on'],
            'safety' => $row['safety'] === null ? null : (float) $row['safety'],
            'message' => $message,
        ];
    }
}
