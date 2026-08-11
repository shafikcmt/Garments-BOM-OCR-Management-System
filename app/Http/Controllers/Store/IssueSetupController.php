<?php

namespace App\Http\Controllers\Store;

use App\Exports\MasterTemplateExport;
use App\Http\Controllers\Concerns\AuthorizesStoreCorrections;
use App\Http\Controllers\Controller;
use App\Imports\MasterListImport;
use App\Models\IndentPerson;
use App\Models\IndentSection;
use App\Models\IssueApprover;
use App\Models\ItemCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;

/**
 * General Stock (module A) — "Issue Setup": the Indent Section, Indent Person
 * and Approved By masters that the issue form's three searchable dropdowns are
 * built from.
 *
 * The three are identical in shape, so they share one controller keyed by a
 * whitelisted {type} segment rather than being copied into three controllers.
 *
 * Access follows the rest of the store module: adding a name is routine store
 * work (same as adding a stock item), while editing or removing an existing one
 * is an Admin / Management correction right.
 */
class IssueSetupController extends Controller
{
    use AuthorizesStoreCorrections;

    /** Section this controller belongs to, for the section-scoped correction
     *  permissions. The flat store.edit / store.delete still apply too. */
    protected string $storeSection = 'store.setup';

    /**
     * Whitelist of editable masters. The key is the URL segment; nothing
     * outside this map can ever be reached.
     *
     * Public so the merged Master Setup screen builds its tabs from this same
     * map rather than keeping a second copy that could drift out of step.
     *
     * @var array<string, array{model: class-string<Model>, label: string, singular: string, icon: string, placeholder: string}>
     */
    public const TYPES = [
        'sections' => [
            'model' => IndentSection::class,
            'label' => 'Indent Section',
            'singular' => 'indent section',
            'icon' => 'bi-diagram-3',
            'placeholder' => 'e.g. Line-04',
        ],
        'persons' => [
            'model' => IndentPerson::class,
            'label' => 'Indent Person',
            'singular' => 'indent person',
            'icon' => 'bi-person-badge',
            'placeholder' => 'e.g. Sumaya',
        ],
        'approvers' => [
            'model' => IssueApprover::class,
            'label' => 'Approved By',
            'singular' => 'approver',
            'icon' => 'bi-patch-check',
            'placeholder' => 'e.g. Liton',
        ],
        'categories' => [
            'model' => ItemCategory::class,
            'label' => 'Category',
            'singular' => 'category',
            'icon' => 'bi-tags',
            'placeholder' => 'e.g. Needle',
        ],
    ];

    public function index()
    {
        ['edit' => $canEdit, 'delete' => $canDelete] = $this->storeCorrectionAbilities();

        $groups = collect(self::TYPES)->map(function (array $meta, string $type) {
            /** @var class-string<Model> $model */
            $model = $meta['model'];

            return $meta + [
                'type' => $type,
                // withCount tells the screen whether a name is already in use,
                // which decides remove vs. deactivate.
                'rows' => $model::withCount('issues')->orderBy('name')->get(),
            ];
        });

        return view('store.stock.issue-setup', compact('groups', 'canEdit', 'canDelete'));
    }

    public function store(Request $request, string $type)
    {
        $meta = $this->meta($type);

        $data = $this->validated($request, $type);
        $data['created_by'] = auth()->id();

        $meta['model']::create($data);

        return back()->with('success', $meta['label'].' "'.$data['name'].'" added.');
    }

    /**
     * Bulk add from a CSV / Excel file — the names in the first column.
     *
     * Adding is routine store work, so this needs no extra permission beyond
     * reaching the screen, exactly like adding one name at a time. It only ever
     * inserts: an existing name is reported as skipped, never overwritten, so
     * re-uploading a corrected file cannot damage the list.
     */
    public function bulk(Request $request, string $type)
    {
        $meta = $this->meta($type);

        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:2048'],
        ], [], ['file' => 'upload file']);

        try {
            $sheets = Excel::toArray(new MasterListImport, $request->file('file'));
        } catch (\Throwable $e) {
            return back()->with('warning', 'That file could not be read. Please upload a CSV or Excel file with the names in the first column.');
        }

        ['names' => $names, 'ignored' => $ignored] = MasterListImport::parse($sheets[0] ?? []);

        if (empty($names)) {
            return back()
                ->with('warning', 'No names were found in the first column of that file.')
                ->with('import_ignored', $ignored);
        }

        $model = $meta['model'];

        // One query for the whole existing list, compared case-insensitively,
        // so a 500-row upload does not become 500 lookups.
        $existing = $model::pluck('name')->map(fn ($name) => mb_strtolower($name))->flip();

        $added = 0;
        foreach ($names as $name) {
            if ($existing->has(mb_strtolower($name))) {
                continue;
            }

            $model::create(['name' => $name, 'is_active' => true, 'created_by' => auth()->id()]);
            $existing[mb_strtolower($name)] = true;
            $added++;
        }

        $skipped = count($names) - $added;

        return back()
            ->with('success', trim(
                $meta['label'].': '.$added.' name(s) added.'
                .($skipped > 0 ? ' '.$skipped.' already existed and were skipped.' : '')
                .($ignored ? ' '.count($ignored).' heading/note row(s) were ignored.' : '')
            ))
            ->with('import_ignored', $ignored);
    }

    /**
     * Blank upload template — one "Name" column, which is all MasterListImport
     * reads. Suppliers has had one of these all along; these four now match.
     */
    public function template(string $type)
    {
        $meta = $this->meta($type);

        return Excel::download(
            new MasterTemplateExport($meta['label'], trim(str_replace('e.g.', '', $meta['placeholder']))),
            Str::slug($meta['label']).'-template.xlsx'
        );
    }

    /**
     * Rename a master entry.
     *
     * Every issue that points at it follows automatically — the issue stores
     * the id, not a copy of the name — so a correction here fixes history too.
     * The one exception is the free-text `department` / `issued_to` snapshot on
     * old issue rows, which is left as it was recorded; the admin is told how
     * many records are affected so the change is not made blind.
     */
    public function update(Request $request, string $type, int $id)
    {
        $meta = $this->meta($type);
        $this->authorizeStoreEdit($meta['singular']);

        $record = $meta['model']::findOrFail($id);
        $before = $record->name;

        $record->update($this->validated($request, $type, $id));

        $used = $record->issues()->count();

        return back()->with('success', $meta['label'].' updated'
            .($before !== $record->name ? ': "'.$before.'" is now "'.$record->name.'".' : '.')
            .($used > 0 ? ' '.$used.' issue record(s) now show the new name.' : ''));
    }

    public function destroy(string $type, int $id)
    {
        $meta = $this->meta($type);
        $this->authorizeStoreDelete($meta['singular']);

        $record = $meta['model']::findOrFail($id);
        $used = $record->issues()->count();

        // An entry already written onto issue records is not removable: the
        // report and the issue history read through this row, and taking it out
        // of the list is not what the admin means when a real record depends on
        // it. Deactivating hides it from the dropdown and keeps history intact.
        if ($used > 0) {
            return back()->with('warning', 'Cannot remove "'.$record->name.'": it is used on '
                .$used.' issue record(s). Edit it and untick Active to hide it from the dropdown instead.');
        }

        // Soft delete keeps the name resolvable on anything that referenced it.
        $record->delete();

        return back()->with('success', $meta['label'].' "'.$record->name.'" removed.');
    }

    /**
     * Remove several entries in one go.
     *
     * These lists arrive by bulk upload and routinely land with dozens of typo
     * duplicates, so clearing them one row at a time is not realistic. Entries
     * that are in use are refused individually and reported by name — a batch
     * is never silently reduced to "some of them worked".
     */
    public function bulkDestroy(Request $request, string $type)
    {
        $meta = $this->meta($type);
        $this->authorizeStoreDelete($meta['singular']);

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ], [
            'ids.required' => 'Select at least one entry to delete.',
        ]);

        $records = $meta['model']::whereIn('id', $data['ids'])->withCount('issues')->get();

        if ($records->isEmpty()) {
            return back()->with('warning', 'Nothing was deleted — those entries no longer exist.');
        }

        [$blocked, $removable] = $records->partition(fn ($row) => $row->issues_count > 0);

        if ($removable->isNotEmpty()) {
            $meta['model']::whereIn('id', $removable->pluck('id'))->delete();
        }

        if ($blocked->isEmpty()) {
            return back()->with('success', $removable->count().' '.$meta['label'].' entr'
                .($removable->count() === 1 ? 'y' : 'ies').' removed.');
        }

        return back()
            ->with($removable->isEmpty() ? 'warning' : 'success',
                $removable->count().' removed. '.$blocked->count().' kept because they are in use.')
            ->with('bulk_blocked', $blocked
                ->map(fn ($row) => '"'.$row->name.'" is used on '.$row->issues_count.' issue record(s).')
                ->values()->all());
    }

    /**
     * @return array{model: class-string<Model>, label: string, singular: string, icon: string}
     */
    private function meta(string $type): array
    {
        abort_unless(isset(self::TYPES[$type]), 404);

        return self::TYPES[$type];
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, string $type, ?int $ignoreId = null): array
    {
        $model = self::TYPES[$type]['model'];
        $table = (new $model)->getTable();

        return $request->validate([
            'name' => [
                'required', 'string', 'max:150',
                // Unique among live rows only — a soft-deleted name must not
                // block re-adding it, which is why this is not a DB index.
                Rule::unique($table, 'name')->whereNull('deleted_at')->ignore($ignoreId),
            ],
            'is_active' => ['nullable', 'boolean'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ], [
            'name.unique' => 'This name already exists in the list.',
        ]);
    }
}
