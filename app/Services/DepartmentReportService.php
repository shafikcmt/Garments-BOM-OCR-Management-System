<?php

namespace App\Services;

use App\Models\ExcelCell;
use App\Models\ExcelHeader;
use App\Models\ExcelRow;
use App\Models\User;
use App\Support\Concerns\ScopesToAssignedBuyers;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Role;

/**
 * One department's line-level report over the BOM grid. Read-only.
 *
 * Merchandising and Supply Chain were built as two classes with the same shape;
 * this is that shape, extracted once. A department's report is now the handful
 * of facts that actually differ between them — which role owns the columns,
 * which columns ride along as context, what can be filtered, which column the
 * date range reads, and what the PDF prints — declared by the six hooks at the
 * bottom of this file. Everything else is here and is identical for all of them.
 *
 * WHY AN ABSTRACT CLASS RATHER THAN A TRAIT. What varies between departments is
 * data, and abstract methods state that contract in a way a trait cannot: a
 * subclass that forgets one does not compile. It also gives the departments a
 * shared TYPE, so a controller, an export or a test can be written against
 * DepartmentReportService rather than against five unrelated classes — which is
 * what makes Commercial, Accounts and Production additions rather than copies.
 *
 * TWO BOUNDARIES, both enforced here so a subclass cannot forget either:
 *
 *   columns()          the department boundary. A report shows the columns its
 *                      role owns, plus any it names as context, and nothing
 *                      else. Every query below is scoped to what it returns.
 *   applyBuyerScope()  the buyer boundary. Applied inside rowQuery(), the ONE
 *                      path the screen, the PDF and the Excel sheet all reach
 *                      data through, so a scope can never reach one and miss
 *                      another.
 *
 * Writes nothing. No column, relation or header row is changed.
 */
abstract class DepartmentReportService
{
    use ScopesToAssignedBuyers;

    /** @var Collection<int, ExcelHeader>|null */
    private ?Collection $columns = null;

    /** The viewer the report is scoped to. See viewer() for the default. */
    private ?User $viewer = null;

    private bool $viewerSet = false;

    /*
    |--------------------------------------------------------------------------
    | Viewer
    |--------------------------------------------------------------------------
    */

    /**
     * Scope the report to a viewer.
     *
     * The controller calls this once; the tests call it to act as somebody
     * without a session. Everything else — rowQuery() and the filter dropdowns
     * alike — reads viewer() below, so the scope cannot be applied to one and
     * forgotten on the other.
     */
    public function forUser(?User $user): static
    {
        $this->viewer = $user;
        $this->viewerSet = true;

        return $this;
    }

    /**
     * Who the report is being built for.
     *
     * Falls back to the authenticated user when forUser() was never called.
     * The fallback is deliberately the SCOPED answer rather than null: a caller
     * that forgets to pass the viewer gets the session's own restrictions, not
     * an unrestricted report. Fail closed, not open.
     */
    protected function viewer(): ?User
    {
        if (! $this->viewerSet) {
            $this->viewer = auth()->user();
            $this->viewerSet = true;
        }

        return $this->viewer;
    }

    /*
    |--------------------------------------------------------------------------
    | Columns — the department boundary
    |--------------------------------------------------------------------------
    */

    /**
     * Every column this report may show: the context columns first, then the
     * department's own in workspace order.
     *
     * THE boundary. Nothing else decides which columns exist. A department that
     * names no context columns — Merchandising, which owns its own buyer and
     * style — gets its own columns alone, because contextKeys() is empty and
     * the first half contributes nothing.
     *
     * @return Collection<int, ExcelHeader>
     */
    public function columns(): Collection
    {
        if ($this->columns !== null) {
            return $this->columns;
        }

        $roleId = Role::where('name', $this->ownerRole())->value('id');

        $own = $roleId
            ? ExcelHeader::query()
                ->where('owner_role_id', $roleId)
                ->where('is_active', true)
                ->orderBy('position')
                ->get()
            : collect();

        $contextKeys = $this->contextKeys();

        if ($contextKeys === []) {
            return $this->columns = $own->values();
        }

        $byKey = ExcelHeader::query()
            ->whereIn('header_key', $contextKeys)
            ->where('is_active', true)
            ->get()
            ->keyBy('header_key');

        $context = collect($contextKeys)
            ->map(fn (string $key) => $byKey->get($key))
            ->filter();

        return $this->columns = $context->concat($own)->values();
    }

    /**
     * The PDF's subset, in pdfKeys() order rather than workspace order.
     *
     * A key absent from excel_headers is skipped, so a department's PDF list
     * can never break its report.
     *
     * @return Collection<int, ExcelHeader>
     */
    public function pdfColumns(): Collection
    {
        $byKey = $this->columns()->keyBy('header_key');

        return collect($this->pdfKeys())
            ->map(fn (string $key) => $byKey->get($key))
            ->filter()
            ->values();
    }

    /*
    |--------------------------------------------------------------------------
    | Reading the report
    |--------------------------------------------------------------------------
    */

    /**
     * One page of report lines, for the on-screen preview.
     *
     * @param  array<string, string|null>  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->baseQuery($filters)
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (ExcelRow $row) => $this->present($row));
    }

    /**
     * Every matching line, for the PDF and Excel exports.
     *
     * @param  array<string, string|null>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function all(array $filters): Collection
    {
        return $this->baseQuery($filters)->get()->map(fn (ExcelRow $row) => $this->present($row));
    }

    /**
     * How many lines the filters match, without loading any of them.
     *
     * @param  array<string, string|null>  $filters
     */
    public function count(array $filters): int
    {
        return $this->rowQuery($filters)->count();
    }

    /*
    |--------------------------------------------------------------------------
    | Filter dropdowns
    |--------------------------------------------------------------------------
    */

    /**
     * Distinct values for each dropdown, read from the rows the viewer may see.
     *
     * @return array<string, Collection<int, string>>
     */
    public function filterOptions(): array
    {
        return collect($this->exactFilters())
            ->map(fn (string $headerKey) => $this->distinctValues($headerKey))
            ->all();
    }

    /**
     * Screen label for a filter, taken from the header itself so a renamed
     * column renames its own filter.
     */
    public function filterLabel(string $filterKey): string
    {
        $headerKey = $this->exactFilters()[$filterKey] ?? null;

        if (! $headerKey) {
            return \Illuminate\Support\Str::headline($filterKey);
        }

        // A filter can sit on a column another department owns (e.g. Garments
        // PO), which is not in columns(); read its name from the header list
        // rather than showing the raw key ("garments_po") on screen.
        return (string) ($this->columns()->firstWhere('header_key', $headerKey)?->header_name
            ?? ExcelHeader::where('header_key', $headerKey)->value('header_name')
            ?? \Illuminate\Support\Str::headline($headerKey));
    }

    /** Label of the column the date range filters on. */
    public function dateLabel(): string
    {
        return (string) ($this->columns()->firstWhere('header_key', $this->dateKey())?->header_name
            ?? $this->fallbackDateLabel());
    }

    /**
     * @return Collection<int, string>
     */
    protected function distinctValues(string $headerKey): Collection
    {
        $headerId = $this->headerId($headerKey);

        if ($headerId === null) {
            return collect();
        }

        $query = ExcelCell::query()
            ->where('header_id', $headerId)
            ->whereNotNull('value')
            ->where('value', '<>', '');

        // The dropdowns are built from the rows the viewer may SEE, so a scoped
        // user is never offered another buyer's name — or their style, season,
        // colour or PO. Deriving them from the scoped row set rather than
        // filtering each list by hand is what keeps the lists and the report
        // itself on one rule.
        //
        // Only the buyer scope is applied, not the screen's own filters: the
        // dropdowns must keep offering every value the user could switch to.
        $this->restrictToVisibleRows($query);

        return $query->distinct()
            ->pluck('value')
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->unique()
            ->sort(SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }

    /**
     * Narrow a cell query to rows the viewer may see. Skipped entirely for an
     * unconfined viewer, so the common case runs the same single query it
     * always has, with no subquery attached.
     */
    protected function restrictToVisibleRows($query): void
    {
        if (! $this->viewer()?->isBuyerConfined()) {
            return;
        }

        $rows = ExcelRow::query()->select('id');
        $this->applyBuyerScope($rows);

        $query->whereIn('row_id', $rows);
    }

    /*
    |--------------------------------------------------------------------------
    | Queries
    |--------------------------------------------------------------------------
    */

    /**
     * The filtered rows with only this report's cells eager-loaded.
     *
     * @param  array<string, string|null>  $filters
     */
    protected function baseQuery(array $filters)
    {
        $columnIds = $this->columnIds();

        return $this->rowQuery($filters)->with([
            'excelFile:id,original_file_name',
            // The scope that makes this one department's report. Loading cells
            // unrestricted would pull every column in the system into memory
            // and leave the boundary to the view, which is the wrong place.
            'cells' => fn ($query) => $query->whereIn('header_id', $columnIds),
        ]);
    }

    /**
     * THE ONE QUERY PATH. index, pdf and excel all reach data through this, so
     * a filter or a scope added here cannot reach one and miss another.
     *
     * @param  array<string, string|null>  $filters
     */
    protected function rowQuery(array $filters)
    {
        $query = ExcelRow::query();

        foreach ($this->exactFilters() as $filterKey => $headerKey) {
            $value = $filters[$filterKey] ?? null;
            $headerId = $this->headerId($headerKey);

            if (! $value || $headerId === null) {
                continue;
            }

            $query->whereHas('cells', fn ($cell) => $cell
                ->where('header_id', $headerId)
                ->where('value', $value));
        }

        $this->applySearch($query, $filters);
        $this->applyDateRange($query, $filters);
        $this->applyBuyerScope($query);

        // Stable order: file first, then the row's own line number, so the
        // report reads in the same sequence as the workspace grid.
        return $query->orderBy('excel_file_id')->orderBy('row_number');
    }

    /**
     * Buyer scoping — the read-time half of the rule the scoped departments
     * already enforce when editing.
     *
     * THE RULE ITSELF NOW LIVES IN ScopesToAssignedBuyers, unchanged, because
     * PO Generate, PRA, the Supply Chain dashboard and its email history apply
     * the same one, and a scope restated five times is four chances to miss a
     * department. What stays here is the single fact that differs: the viewer a
     * report is scoped to is the one set on it, not always the logged-in user,
     * because Admin and Management drive this class for somebody else.
     *
     * Read the trait for why an untagged file stays visible, why an empty buyer
     * set narrows rather than widens, and why hasBuyerAccess() is not used to
     * build the SQL.
     */
    protected function buyerScopeViewer(): ?User
    {
        return $this->viewer();
    }

    /**
     * @param  array<string, string|null>  $filters
     */
    protected function applySearch($query, array $filters): void
    {
        $search = trim((string) ($filters['material'] ?? ''));

        if ($search === '') {
            return;
        }

        // Searched keys are looked up directly rather than through headerId(),
        // because a department may search a column it does not display — a SAP
        // code is a thing people type from memory whether or not the report
        // prints it.
        $headerIds = collect($this->searchKeys())
            ->map(fn (string $key) => ExcelHeader::where('header_key', $key)->value('id'))
            ->filter()
            ->values()
            ->all();

        if ($headerIds === []) {
            return;
        }

        // whereLike compiles to ILIKE on PostgreSQL and LIKE on MySQL. A plain
        // LIKE here would match on local MySQL and quietly find nothing on live.
        $query->whereHas('cells', fn ($cell) => $cell
            ->whereIn('header_id', $headerIds)
            ->whereLike('value', "%{$search}%"));
    }

    /**
     * Date range against the department's own date column.
     *
     * Cells hold dates as ISO 'YYYY-MM-DD' text, which compares correctly as a
     * STRING on both MySQL and PostgreSQL — ISO dates sort lexicographically in
     * date order. That is deliberate: casting a longText column to a date is
     * where the two engines disagree (PostgreSQL refuses the empty string a
     * blank cell holds), and a plain string comparison needs no cast at all.
     *
     * @param  array<string, string|null>  $filters
     */
    protected function applyDateRange($query, array $filters): void
    {
        $from = $filters['date_from'] ?? null;
        $to = $filters['date_to'] ?? null;
        $headerId = $this->headerId($this->dateKey());

        if ($headerId === null || (! $from && ! $to)) {
            return;
        }

        $query->whereHas('cells', function ($cell) use ($headerId, $from, $to) {
            // A blank cell is excluded rather than treated as an early date —
            // "" sorts below every real value and would otherwise pass a
            // from-date filter.
            $cell->where('header_id', $headerId)->where('value', '<>', '');

            if ($from) {
                $cell->where('value', '>=', $from);
            }

            if ($to) {
                $cell->where('value', '<=', $to);
            }
        });
    }

    /**
     * Flatten one row into header_key => value, with every column present so
     * the table has the same shape on every line.
     *
     * @return array<string, mixed>
     */
    protected function present(ExcelRow $row): array
    {
        $byHeader = $row->cells->keyBy('header_id');

        $values = $this->columns()
            ->mapWithKeys(fn (ExcelHeader $header) => [
                $header->header_key => trim((string) ($byHeader->get($header->id)?->value ?? '')),
            ])
            ->all();

        return [
            'row_number' => $row->row_number,
            'file' => $row->excelFile?->original_file_name ?? '',
            'values' => $values,
        ];
    }

    /** @return list<int> */
    protected function columnIds(): array
    {
        return $this->columns()->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    protected function headerId(string $headerKey): ?int
    {
        $id = $this->columns()->firstWhere('header_key', $headerKey)?->id;

        return $id === null ? null : (int) $id;
    }

    /*
    |--------------------------------------------------------------------------
    | What a department declares
    |--------------------------------------------------------------------------
    |
    | Everything above is the same for every department. These are the facts
    | that are not, and a subclass that omits a required one does not compile.
    */

    /** The role whose headers this report is built from. */
    abstract protected function ownerRole(): string;

    /**
     * Screen filter key => the header it matches on, exactly.
     *
     * Only columns the report DISPLAYS belong here — a filter on an invisible
     * column is a result the user cannot explain, and on a column owned by
     * another department it is that department's data by a slower route.
     *
     * @return array<string, string>
     */
    abstract protected function exactFilters(): array;

    /**
     * Columns the free-text search runs across together.
     *
     * @return list<string>
     */
    abstract protected function searchKeys(): array;

    /** The column the date range applies to. */
    abstract protected function dateKey(): string;

    /**
     * The PDF's summary columns, in print order.
     *
     * A department's full column count does not fit an A4 landscape page at a
     * readable size, so the PDF prints a subset while the screen and Excel
     * carry everything.
     *
     * @return list<string>
     */
    abstract protected function pdfKeys(): array;

    /**
     * Columns belonging to ANOTHER department that ride along as read-only
     * context, in display order.
     *
     * Empty for a department whose own columns already identify a line, which
     * is why this is a default rather than an abstract. A department overrides
     * it only when its own columns cannot say which order a row belongs to —
     * see SupplyChainReportService, which owns no buyer or style column.
     *
     * @return list<string>
     */
    public function contextKeys(): array
    {
        return [];
    }

    /** Used for the date filter's label when the column is missing entirely. */
    protected function fallbackDateLabel(): string
    {
        return 'Date';
    }
}
