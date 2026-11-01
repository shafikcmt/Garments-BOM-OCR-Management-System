<?php

use App\Models\ItemCategory;
use App\Models\PurchaseRequisition;
use App\Models\PurchaseRequisitionItem;
use App\Models\StockItem;
use App\Models\User;
use Spatie\Permission\Models\Role;

/** Category Summary: totals by category, shares, downloads, and who may open it. */
function csSetup(): User
{
    $admin = User::factory()->create();
    $admin->assignRole(Role::findOrCreate('admin', 'web'));

    $make = function (string $category, float $qty, float $rate, string $no) use ($admin) {
        $cat = ItemCategory::firstOrCreate(['name' => $category]);
        $item = StockItem::create(['name' => $category.' item', 'uom' => 'Piece', 'category' => $category, 'is_active' => true, 'item_category_id' => $cat->id]);
        $sheet = PurchaseRequisition::create([
            'requisition_no' => $no, 'requisition_date' => '2026-10-10', 'mode' => PurchaseRequisition::MODE_MULTI,
            'level' => PurchaseRequisition::LEVEL_FINAL, 'status' => PurchaseRequisition::STATUS_APPROVED,
            'submitted_at' => now(), 'created_by' => $admin->id,
        ]);
        PurchaseRequisitionItem::create([
            'purchase_requisition_id' => $sheet->id, 'stock_item_id' => $item->id, 'item_category_id' => $cat->id, 'sort_order' => 1,
            'qty_requested' => $qty, 'stock_in_hand_snapshot' => 0, 'to_be_procured_qty' => $qty, 'rate_appx' => $rate, 'amount' => $qty * $rate,
        ]);
    };
    $make('Chemical', 10, 30, 'CS-1');   // 300
    $make('Stationery', 10, 10, 'CS-2'); // 100

    return $admin;
}

it('shows each category with its amount and share of spend', function () {
    $admin = csSetup();

    $this->actingAs($admin)->get(route('store.stock.requisitions.category-summary', ['month' => '2026-10']))
        ->assertOk()->assertSee('Chemical')->assertSee('Stationery')
        ->assertSee('400.00')->assertSee('75.0%')->assertSee('25.0%')
        ->assertSee('Download PDF')->assertSee('Download Excel');
});

it('downloads the category summary as PDF and Excel', function () {
    $admin = csSetup();

    $this->actingAs($admin)->get(route('store.stock.requisitions.category-summary.pdf', ['month' => '2026-10']))->assertOk();
    $this->actingAs($admin)->get(route('store.stock.requisitions.category-summary.excel', ['month' => '2026-10']))->assertOk();
});

it('refuses a user outside the Store and management', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('store.stock.requisitions.category-summary'))->assertForbidden();
});
