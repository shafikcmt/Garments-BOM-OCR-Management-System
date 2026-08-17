<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AuthorizesStoreCorrections;
use App\Http\Controllers\Concerns\ManagesFormDrafts;
use App\Http\Controllers\Concerns\ResolvesIssueSetupMasters;
use App\Exports\IssueTemplateExport;
use App\Exports\SkippedIssueRowsExport;
use App\Imports\IssueImport;
use App\Models\IndentPerson;
use App\Models\IndentSection;
use App\Models\IssueApprover;
use App\Models\ItemCategory;
use App\Models\StockItem;
use App\Models\StockIssue;
use App\Models\StoreFormDraft;
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
    use AuthorizesStoreCorrections, ManagesFormDrafts, ResolvesIssueSetupMasters;

    /** Section this controller belongs to, for the section-scoped correction
     *  permissions. The flat store.edit / store.delete still apply too. */
    protected string $storeSection = 'store.issues';

    /** Which form ManagesFormDrafts is saving for. */
    protected string $draftForm = StoreFormDraft::FORM_ISSUE;

    /** Where resuming a draft lands. */
    protected function draftReturnUrl(): string
    {
        return route('store.stock.issues.index');
    }

    /**
     * A draft is the same act as recording an issue, half-done, so it carries
     * the same right — and the route middleware asks for it too. Checked here
     * as well because the trait is shared and must not depend on every route
     * that reaches it being guarded.
     */
    protected function authorizeDraftAction(): void
    {
        abort_unless(auth()->user()?->can('store.issues.create') ?? false, 403,
            'You do not have permission to record issues.');
    }

    /** How a saved issue draft is described in the list. */
    protected function draftLabel(array $payload): string
    {
        $lines = count($payload['items'] ?? []);

        $parts = array_filter([
            $payload['requisition_no'] ?? null,
            $payload['issue_date'] ?? null,
            $lines ? $lines.' item'.($lines === 1 ? '' : 's') : 'no items yet',
        ]);

        return mb_substr(implode(' · ', $parts) ?: 'Untitled draft', 0, 255);
    }

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
            // This user's own half-finished forms. Nothing here touches stock —
            // see the store_form_drafts migration.
            'drafts' => $this->myDrafts(),
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

        // The real record is written, so the draft it came from has served its
        // purpose. After the transaction, never before — a rejected submission
        // must leave the draft where it was.
        $this->discardDraftAfterSubmit($request);

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
            'skipped_rows' => $skippedRows,
        ] = IssueImport::parse($sheets[0] ?? []);

        $this->holdSkippedRows($skippedRows);

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
     * How many skipped rows are kept for the download.
     *
     * A bound rather than a business rule. The rows live in the session, whose
     * driver is the database, and the whole payload is read and written on
     * every subsequent request — so a pathological file where nothing at all
     * imports must not leave a quarter of a megabyte riding on each page load.
     * A real file is nowhere near this: the 754-row upload this was built for
     * skipped 39.
     */
    private const SKIPPED_ROW_LIMIT = 500;

    /**
     * Keep the rows an import could not take, for the download button on the
     * screen the user is about to be redirected to.
     *
     * put() rather than flash(): the download is a separate request made AFTER
     * the page has rendered, which is one request too late for flash data. It
     * is overwritten by the next import and cleared when an import skips
     * nothing, so at most one file's worth is ever held.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function holdSkippedRows(array $rows): void
    {
        if (empty($rows)) {
            session()->forget(['import_skipped_rows', 'import_skipped_row_count']);

            return;
        }

        session()->put('import_skipped_rows', array_slice($rows, 0, self::SKIPPED_ROW_LIMIT));

        // The true count, kept separately, so the screen can say when the file
        // holds fewer rows than were actually skipped.
        session()->put('import_skipped_row_count', count($rows));
    }

    /**
     * Download the rows the last import could not take, as a workbook in the
     * template's own format.
     *
     * The point is the round trip: fix the rows in this file, upload this file.
     * It carries every row of every requisition that did not land — not only
     * the faulty lines — because a requisition is imported whole or not at all,
     * so re-uploading a fragment of one would record half a slip.
     */
    public function skippedRows()
    {
        $rows = session('import_skipped_rows', []);

        if (empty($rows)) {
            return back()->with('warning', 'There are no skipped rows to download. Please run the import again.');
        }

        return Excel::download(
            new SkippedIssueRowsExport($rows),
            'Issue-Skipped-Rows-'.now()->format('Y-m-d-Hi').'.xlsx'
        );
    }

    /**
     * Put the skipped-rows notice away.
     *
     * It is held in the session rather than flashed, because the download is a
     * separate request made after the page has already rendered. That is what
     * the notice needs to work at all, and it is also why it cannot be closed
     * by hiding it: it would be back on the next page load.
     *
     * So dismissing CLEARS THE ROWS, it does not merely mark them read. The
     * notice is the only route to the Download button, so rows kept past it are
     * unreachable — and they are re-read out of the session on every subsequent
     * request until something clears them. Unrecoverable except by running the
     * import again, which is what a deliberate press of a close button on a
     * notice about a file you have already downloaded should mean.
     *
     * Deliberately NOT called when the Download button is clicked. A download
     * link reports no completion, only that it was started, so clearing there
     * would throw the rows away on the strength of an event that may have
     * failed. The screen fades the notice on that click and leaves the session
     * alone, so a reload brings it back.
     */
    public function dismissSkippedRows()
    {
        session()->forget(['import_skipped_rows', 'import_skipped_row_count']);

        return back();
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
     * $excluding is the issue being EDITED, and is what makes this reusable for
     * a correction. The balance still counts that row's current quantity as
     * gone, so checking a new quantity against it would refuse 10 -> 11 on an
     * item with nothing spare — the old 10 being counted twice. Its quantity is
     * added back, leaving the check asking the real question: is the NEW figure
     * affordable once the OLD one is undone.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @param  callable(int|string): string|null  $errorKey  where a shortfall is reported
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    private function assertWithinStock(array $lines, ?StockIssue $excluding = null, ?callable $errorKey = null): void
    {
        $errorKey ??= fn ($index) => 'items.'.$index.'.qty';

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

            // The edited row's own consumption, undone. Only when the report
            // actually counted it: an issue dated beyond this month's end is
            // outside the window stock_as_on sums, so adding it back would
            // invent stock that was never deducted.
            if ($excluding
                && (int) $excluding->stock_item_id === $id
                && $excluding->issue_date
                && $excluding->issue_date->lte(now()->endOfMonth())) {
                $stock += (float) $excluding->qty;
            }

            if ($ask['qty'] <= $stock) {
                continue;
            }

            $name = $row ? $row['item']->name : 'This item';
            $uom = $row && $row['item']->uom ? ' '.$row['item']->uom : '';

            $message = $name.' — only '.$format(max($stock, 0)).$uom.' in stock, cannot issue '.$format($ask['qty']).'.';

            // Flagged on every line that named the item, so the operator is not
            // sent hunting for which of two rows the message belongs to.
            foreach ($ask['lines'] as $index) {
                $errors[$errorKey($index)] = $message;
            }
        }

        if ($errors) {
            // The inline note under each qty box is easy to miss on a
            // fifteen-line requisition, so the same shortfalls are also flashed
            // for the summary the form shows above the table.
            //
            // Only for a NEW issue. That summary lives inside the Record Issue
            // form, so filling it after a rejected correction would light up
            // "Not enough stock to issue" over a form the user never touched.
            // A rejected edit is reported by the page's own validation banner
            // instead, which is where its modal sent it.
            if ($excluding === null) {
                session()->flash('issue_stock_errors', array_values(array_unique($errors)));
            }

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

    /**
     * Correct one recorded issue.
     *
     * ONE ROW, not a requisition. A requisition is several stock_issues rows
     * sharing a header, and Issue History lists it a row at a time — so this
     * corrects the row the user clicked Edit on and leaves its siblings alone.
     * Changing the header on all of them at once is a different, larger feature;
     * doing it silently here would edit rows the user never opened.
     *
     * THE ITEM IS NOT EDITABLE. Moving a recorded issue to a different item is
     * not a correction of this record — it is one item's consumption undone and
     * another's created, against two different balances. Delete and re-record
     * says that plainly; a quiet swap in an update does not. The field is absent
     * from the form and from the validation, so a hand-crafted request naming
     * one is ignored rather than honoured.
     *
     * Stock balance needs no recalculation: nothing stores it. The Consumable
     * Stock Report derives every figure by summing stock_purchases and
     * stock_issues at read time, so a corrected quantity is already true
     * everywhere the moment this commits. What DOES need care is the same rule
     * Create enforces — an issue may not take an item below zero — which is why
     * assertWithinStock runs here too, told to discount this row's own current
     * quantity.
     */
    public function update(Request $request, StockIssue $stockIssue)
    {
        $this->authorizeStoreEdit('stock issue');

        $data = $request->validate([
            'issue_date' => ['required', 'date'],
            'qty' => ['required', 'numeric', 'min:0.0001'],
            // Existing masters only. The create form lets a name be typed and
            // creates it on the way through; a correction is not the place to
            // introduce a new Section or Approver, and Issue Setup is one click
            // away for that.
            'indent_section_id' => ['nullable', 'exists:indent_sections,id'],
            'indent_person_id' => ['nullable', 'exists:indent_persons,id'],
            'issue_approver_id' => ['nullable', 'exists:issue_approvers,id'],
            'item_category_id' => ['nullable', 'exists:item_categories,id'],
            'requisition_no' => ['nullable', 'string', 'max:100'],
            'requisition_type' => ['nullable', Rule::in(StockIssue::REQUISITION_TYPES)],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ], [], [
            'qty' => 'issued qty',
            'indent_section_id' => 'indent section',
            'indent_person_id' => 'indent person',
            'issue_approver_id' => 'approved by',
        ]);

        DB::transaction(function () use ($data, $stockIssue) {
            // Checked against the item this issue is already against, since the
            // item cannot change. Inside the transaction and before the write,
            // for the reason store() gives.
            $this->assertWithinStock(
                [['stock_item_id' => $stockIssue->stock_item_id, 'qty' => $data['qty']]],
                $stockIssue,
                fn () => 'qty'
            );

            // Every editable field named explicitly, so one left out of the form
            // is cleared rather than silently kept — a blanked Approved By has
            // to mean blank. validate() omits absent nullable keys entirely,
            // hence the ?? null on each.
            $sectionId = $data['indent_section_id'] ?? null;
            $personId = $data['indent_person_id'] ?? null;

            $stockIssue->update([
                'issue_date' => $data['issue_date'],
                'qty' => $data['qty'],
                'indent_section_id' => $sectionId,
                'indent_person_id' => $personId,
                'issue_approver_id' => $data['issue_approver_id'] ?? null,
                'item_category_id' => $data['item_category_id'] ?? null,
                'requisition_no' => $data['requisition_no'] ?? null,
                'requisition_type' => $data['requisition_type'] ?? null,
                'remarks' => $data['remarks'] ?? null,
                // The denormalised copies kept in step, exactly as store()
                // writes them, or the free-text columns would still name the
                // section this issue was moved off.
                'department' => $sectionId ? IndentSection::find($sectionId)?->name : null,
                'issued_to' => $personId ? IndentPerson::find($personId)?->name : null,
            ]);
        });

        // The same reordering advice a new issue raises: a correction upward can
        // take an item under its safety level just as an issue can.
        $warnings = $this->lowStockWarnings([(int) $stockIssue->stock_item_id]);

        if ($warnings) {
            return back()
                ->with('success', 'Issue updated.')
                ->with('warning', $warnings[0].' Please raise a purchase requisition.')
                ->with('issue_stock_warnings', $warnings);
        }

        return back()->with('success', 'Issue updated.');
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
