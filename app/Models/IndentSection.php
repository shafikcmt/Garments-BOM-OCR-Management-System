<?php

namespace App\Models;

use App\Models\Concerns\IsIssueSetupMaster;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Issue Setup master — the production section a General Stock issue is
 * indented for (Line-04, Cutting, Finishing, QA …).
 *
 * Since 2026_09_23_000002 a section may also sit under a department and
 * have a head: that is what routes a department user's requisition to the
 * right person. A section with no department is a plain issue-register
 * section, exactly as every row was before.
 *
 * Not related to config('stock.indent_sections'), which the separate
 * Buyer/Style Bulk Issue screen still uses.
 */
class IndentSection extends Model
{
    use HasFactory, SoftDeletes, IsIssueSetupMaster;

    // IsIssueSetupMaster merges name / is_active / remarks / created_by into
    // these at boot. (An initialize*() hook only runs for a TRAIT of that
    // name, so the columns are declared here, not merged from the class.)
    protected $fillable = ['department_key', 'head_user_id', 'sort_order'];

    protected $casts = ['sort_order' => 'integer'];

    public function issues()
    {
        return $this->hasMany(StockIssue::class);
    }

    public function department()
    {
        return $this->belongsTo(Department::class, 'department_key', 'key');
    }

    public function head()
    {
        return $this->belongsTo(User::class, 'head_user_id');
    }

    /** Sections filed under a department, in the order the admin set. */
    public function scopeUnderDepartment(Builder $query, string $key): Builder
    {
        return $query->where('department_key', $key)->orderBy('sort_order')->orderBy('name');
    }
}
