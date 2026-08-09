<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Concerns\AuthorizesStoreCorrections;
use App\Http\Controllers\Controller;
use App\Models\GeneralStockSupplier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * General Stock (module A) — "Master Setup": one screen for every master list
 * behind the purchase, issue and item forms.
 *
 * This replaces the two separate Purchase Setup and Issue Setup index pages.
 * It composes only — every Add, Edit, Delete, Import and Bulk-delete form on
 * the screen still posts to PurchaseSetupController or IssueSetupController
 * exactly as it did before the merge, so validation, permissions, the
 * soft-delete rules and the in-use guard are untouched and defined in one
 * place each.
 *
 * The two old index routes redirect here with the matching ?tab=, so bookmarks
 * and the links on the Purchases and Issues screens keep working.
 */
class StockSetupController extends Controller
{
    use AuthorizesStoreCorrections;

    /** Suppliers first, then the four issue masters in IssueSetupController order. */
    public const SUPPLIERS = 'suppliers';

    public function index(Request $request)
    {
        ['edit' => $canEdit, 'delete' => $canDelete] = $this->storeCorrectionAbilities();

        $tabs = $this->tabs();

        // An unknown ?tab= falls back to the first rather than 404ing — a stale
        // bookmark should still land somewhere useful.
        $active = $request->query('tab');
        if (! is_string($active) || ! isset($tabs[$active])) {
            $active = self::SUPPLIERS;
        }

        return view('store.stock.setup', compact('tabs', 'active', 'canEdit', 'canDelete'));
    }

    /**
     * Per-tab metadata. The four issue masters are read straight from
     * IssueSetupController::TYPES so the two screens can never disagree about
     * what exists or what it is called.
     *
     * @return array<string, array<string, mixed>>
     */
    private function tabs(): array
    {
        $tabs = [
            self::SUPPLIERS => [
                'key' => self::SUPPLIERS,
                'label' => 'Suppliers',
                'singular' => 'Supplier',
                'icon' => 'bi-shop',
                'placeholder' => 'e.g. Organ Needle Co.',
                'note' => 'Suppliers fill the Supplier Name field on Record Purchase.',
                // What "Used On" counts, and the word shown beside the number.
                'used_word' => 'purchase',
                'used_count' => 'purchases_count',
                // Suppliers has no Remarks column, and its delete never refuses.
                'has_remarks' => false,
                'blocks_delete_when_used' => false,
                'import_help' => 'Upload many suppliers at once. Start from the sample template so the columns line up.',
                'rows' => GeneralStockSupplier::withCount('purchases')->orderBy('name')
                    ->get(['id', 'name', 'is_active', 'created_at']),
            ],
        ];

        foreach (IssueSetupController::TYPES as $type => $meta) {
            /** @var class-string<Model> $model */
            $model = $meta['model'];

            $tabs[$type] = [
                'key' => $type,
                'label' => $meta['label'],
                'singular' => $meta['label'],
                'icon' => $meta['icon'],
                'placeholder' => $meta['placeholder'],
                'note' => $this->noteFor($type, $meta['label']),
                'used_word' => 'issue',
                'used_count' => 'issues_count',
                'has_remarks' => true,
                'blocks_delete_when_used' => true,
                'import_help' => 'CSV or Excel, names in the first column. A header row is skipped automatically, and names already in the list are left alone.',
                // withCount tells the screen whether a name is already in use,
                // which decides remove vs. deactivate.
                'rows' => $model::withCount('issues')->orderBy('name')->get(),
            ];
        }

        return $tabs;
    }

    /** One plain sentence saying what each list actually drives. */
    private function noteFor(string $type, string $label): string
    {
        return match ($type) {
            // Category is the one master two screens read, so it says so.
            'categories' => 'Categories fill the Category field on Record Issue and on the Item Master.',
            default => $label.' entries fill the '.$label.' field on Record Issue.',
        };
    }
}
