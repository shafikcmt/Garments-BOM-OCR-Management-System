<?php

namespace App\Http\Controllers\Store;

use App\Exports\StockReferenceTemplateExport;
use App\Http\Controllers\Concerns\AuthorizesStoreCorrections;
use App\Http\Controllers\Controller;
use App\Imports\StockReferenceImport;
use App\Models\StockItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Backfilling the Stock Report's Unit Price, Last Addition and Last
 * Consumption columns for items carried over from the old Excel sheets.
 *
 * TWO STEPS, and the split is the safety. preview() reads the file and reports
 * what WOULD change; apply() writes only a plan a person has already looked at.
 * A single-step import that silently touched 1,241 items would give nobody the
 * chance to notice a column out of step before it landed.
 *
 * WHAT IT WRITES: three columns on stock_items, and only where the report has
 * nothing to show today — the rule lives in StockReferenceImport, which decides
 * each field independently. stock_purchases and stock_issues are read to
 * establish what already exists and are NEVER written; no code path here can
 * create a movement.
 *
 * Guarded on the correction right rather than a create right, the same as the
 * Unit Price import it sits beside: this CHANGES existing items and creates
 * none.
 */
class StockReferenceImportController extends Controller
{
    use AuthorizesStoreCorrections;

    /** Section-scoped correction permission, matching the report's own screen. */
    protected string $storeSection = 'store.stock_report';

    /** How long a previewed plan stays applicable. */
    private const PLAN_TTL_MINUTES = 60;

    /** Skipped rows kept for display, so a 1,241-row file cannot fill the page. */
    private const SKIPPED_ROW_LIMIT = 50;

    /** The blank five-column workbook. */
    public function template()
    {
        $this->authorizeStoreEdit('stock reference data');

        return Excel::download(new StockReferenceTemplateExport, 'Stock-Reference-Upload-Template.xlsx');
    }

    /**
     * Step one: read the file, work out what would change, show it.
     *
     * WRITES NOTHING. The plan is held in the cache under a one-time token
     * rather than in the session, because a file naming every item produces a
     * plan far larger than a session cookie should carry. The token is bound to
     * the user who made it.
     */
    public function preview(Request $request)
    {
        $this->authorizeStoreEdit('stock reference data');

        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:8192'],
        ], [], ['file' => 'upload file']);

        try {
            $sheets = Excel::toArray(new StockReferenceImport, $request->file('file'));
        } catch (\Throwable $e) {
            return back()->with('warning', 'That file could not be read. Please upload a CSV or Excel file based on the sample template.');
        }

        $plan = StockReferenceImport::plan($sheets[0] ?? []);

        // All-or-nothing on errors, like the item and price imports beside it.
        // An unmatched name is usually a column out of step or the wrong brand,
        // and applying the half of the file that happened to match would leave
        // the user unable to tell what had landed.
        if ($plan['errors']) {
            return back()
                ->with('warning', 'Nothing was imported. Please correct '.count($plan['errors']).' row(s) and upload the file again.')
                ->with('import_errors', $plan['errors'])
                ->with('import_skipped', array_slice($plan['skipped'], 0, self::SKIPPED_ROW_LIMIT));
        }

        if ($plan['updates'] === []) {
            return back()->with('warning', $plan['skipped']
                ? 'Nothing needs importing from that file — every value in it is already on the report.'
                : 'No values were found in that file. Please use the sample template.')
                ->with('import_skipped', array_slice($plan['skipped'], 0, self::SKIPPED_ROW_LIMIT));
        }

        $token = (string) Str::uuid();

        Cache::put($this->planKey($token), [
            'user_id' => $request->user()->id,
            'updates' => $plan['updates'],
            'counts' => $plan['counts'],
            'matched' => $plan['matched'],
            'skipped' => $plan['skipped'],
            'file_name' => $request->file('file')->getClientOriginalName(),
        ], now()->addMinutes(self::PLAN_TTL_MINUTES));

        return view('store.stock.import-preview', [
            'token' => $token,
            'counts' => $plan['counts'],
            'matched' => $plan['matched'],
            'itemCount' => count($plan['updates']),
            'skipped' => array_slice($plan['skipped'], 0, self::SKIPPED_ROW_LIMIT),
            'skippedTotal' => count($plan['skipped']),
            'fileName' => $request->file('file')->getClientOriginalName(),
            'expiryMinutes' => self::PLAN_TTL_MINUTES,
        ]);
    }

    /**
     * Step two: apply a plan that was previewed.
     *
     * The plan is re-read from the cache rather than from the posted form, so
     * what lands is what was shown — a tampered form field cannot widen it.
     *
     * Each field is written on its own, because the plan already decided them
     * independently: an item in the plan for Unit Price alone has only that one
     * column in its update, so its dates cannot be touched here.
     */
    public function apply(Request $request)
    {
        $this->authorizeStoreEdit('stock reference data');

        $request->validate(['token' => ['required', 'string', 'max:64']]);

        $key = $this->planKey($request->string('token')->toString());
        $plan = Cache::get($key);

        // Expired, already used, or made by somebody else. Nothing is applied
        // from a plan whose counts the user in front of us never saw.
        if (! is_array($plan) || ($plan['user_id'] ?? null) !== $request->user()->id) {
            return redirect()->route('store.stock.ledger')
                ->with('warning', 'That import preview has expired. Please upload the file again.');
        }

        // Taken before writing, so a double-submitted form cannot apply twice.
        Cache::forget($key);

        DB::transaction(function () use ($plan) {
            foreach ($plan['updates'] as $update) {
                $columns = [];

                foreach ($update['values'] as $field => $value) {
                    $columns[StockReferenceImport::FIELD_COLUMNS[$field]] = $value;
                }

                // Only the columns this item's plan named, written with the
                // query builder rather than a model save so nothing else on the
                // row can be carried along by accident.
                StockItem::whereKey($update['id'])->update($columns);
            }
        });

        $parts = [];

        foreach (StockReferenceImport::FIELDS as $field) {
            if ($plan['counts'][$field] > 0) {
                $parts[] = $plan['counts'][$field].' '.StockReferenceImport::FIELD_LABELS[$field];
            }
        }

        return redirect()->route('store.stock.ledger')
            ->with('success', 'Import applied — '.implode(', ', $parts).'.')
            ->with('import_skipped', array_slice($plan['skipped'], 0, self::SKIPPED_ROW_LIMIT));
    }

    private function planKey(string $token): string
    {
        return 'stock-reference-import:'.$token;
    }
}
