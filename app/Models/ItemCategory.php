<?php

namespace App\Models;

use App\Models\Concerns\IsIssueSetupMaster;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Issue Setup master — the consumable category shown on the issue form
 * (Needle, Chemical, Spare Parts, Electrical …).
 *
 * Separate from the free-text stock_items.category column, which the item
 * master and the Consumable Stock Report still use.
 */
class ItemCategory extends Model
{
    use HasFactory, SoftDeletes, IsIssueSetupMaster;

    public function issues()
    {
        return $this->hasMany(StockIssue::class, 'item_category_id');
    }
}
