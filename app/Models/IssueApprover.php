<?php

namespace App\Models;

use App\Models\Concerns\IsIssueSetupMaster;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Issue Setup master — the approving authority shown as "Approved By" on the
 * issue form and the consumption report.
 */
class IssueApprover extends Model
{
    use HasFactory, SoftDeletes, IsIssueSetupMaster;

    public function issues()
    {
        return $this->hasMany(StockIssue::class, 'issue_approver_id');
    }
}
