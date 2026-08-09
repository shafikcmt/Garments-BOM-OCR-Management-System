<?php

namespace App\Models;

use App\Models\Concerns\IsIssueSetupMaster;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Issue Setup master — the person who raised the requisition.
 */
class IndentPerson extends Model
{
    use HasFactory, SoftDeletes, IsIssueSetupMaster;

    /** Laravel would guess "indent_people"; the table is indent_persons. */
    protected $table = 'indent_persons';

    public function issues()
    {
        return $this->hasMany(StockIssue::class);
    }
}
