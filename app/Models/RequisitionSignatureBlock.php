<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One signature box in the footer of a Purchase Requisition document.
 *
 * Configured under Admin > Settings > Requisition Signatures; rendered by
 * RequisitionSignatureResolver, which is the only thing that turns a row here
 * into a name, a designation and a date on the sheet. See the migration for
 * what each column means.
 */
class RequisitionSignatureBlock extends Model
{
    public const LEVEL_DEPARTMENT = 'department';
    public const LEVEL_FINAL = 'final';
    /** An individual (unmerged) requisition: its own approval boxes. */
    public const LEVEL_INDIVIDUAL = 'individual';

    public const ASSIGNEE_REQUESTER = 'requester';
    public const ASSIGNEE_DEPARTMENT_HEAD = 'department_head';
    public const ASSIGNEE_ROLE = 'role';
    public const ASSIGNEE_USER = 'user';
    /** The Store department head, as set on Admin > Departments. */
    public const ASSIGNEE_STORE_HEAD = 'store_head';
    /** Any Store user — the Store team receiving a department's sheet. */
    public const ASSIGNEE_STORE_TEAM = 'store_team';

    public const STAMP_PREPARED = 'prepared';
    public const STAMP_DEPARTMENT_HEAD = 'department_head';
    public const STAMP_STORE_HEAD = 'store_head';
    public const STAMP_APPROVED = 'approved';

    protected $fillable = [
        'level',
        'sequence',
        'label',
        'title',
        'assignee_type',
        'role_name',
        'user_id',
        'stamp_source',
    ];

    protected $casts = [
        'sequence' => 'integer',
    ];

    /** @return array<string, string> level => label */
    public static function levelLabels(): array
    {
        return [
            self::LEVEL_DEPARTMENT => 'Department Requisition',
            self::LEVEL_FINAL => 'Final Purchase Requisition',
            self::LEVEL_INDIVIDUAL => 'Individual Requisition',
        ];
    }

    /** @return array<string, string> assignee_type => label */
    public static function assigneeTypeLabels(): array
    {
        return [
            self::ASSIGNEE_REQUESTER => 'Person who raised it',
            self::ASSIGNEE_DEPARTMENT_HEAD => "Requester's Department Head",
            self::ASSIGNEE_ROLE => 'Anyone with a role',
            self::ASSIGNEE_USER => 'A specific user',
            self::ASSIGNEE_STORE_HEAD => 'Store Department Head',
            self::ASSIGNEE_STORE_TEAM => 'Any Store user (Store team)',
        ];
    }

    /** @return array<string, string> stamp_source => label; '' = an approval that changes no status */
    public static function stampSourceLabels(): array
    {
        return [
            '' => 'Approval only — no status change',
            self::STAMP_PREPARED => 'Prepared — when submitted',
            self::STAMP_DEPARTMENT_HEAD => 'Department Head — Approve',
            self::STAMP_STORE_HEAD => 'Store Head — Received',
            self::STAMP_APPROVED => 'Final — Approve',
        ];
    }

    public function scopeForLevel(Builder $query, string $level): Builder
    {
        return $query->where('level', $level)->orderBy('sequence')->orderBy('id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
