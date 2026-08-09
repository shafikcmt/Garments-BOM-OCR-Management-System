<?php

namespace App\Models;

use App\Models\Concerns\IsIssueSetupMaster;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Issue Setup master — the production section a General Stock issue is
 * indented for (Line-04, Cutting, Finishing, QA …).
 *
 * Not related to config('stock.indent_sections'), which the separate
 * Buyer/Style Bulk Issue screen still uses.
 */
class IndentSection extends Model
{
    use HasFactory, SoftDeletes, IsIssueSetupMaster;

    public function issues()
    {
        return $this->hasMany(StockIssue::class);
    }
}
