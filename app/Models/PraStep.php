<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * One step of the departmental PRA chain, defined by Admin on
 * Settings > PRA Approval Steps. See the migration for the rules.
 */
class PraStep extends Model
{
    public const STAGES = ['check' => 'Check', 'approve' => 'Approve', 'complete' => 'Payment / completion'];

    public const KINDS = ['team' => 'Team', 'head' => 'Head'];

    protected $fillable = [
        'sequence', 'position', 'label', 'department', 'actor_kind', 'stage', 'buyer_scoped',
        'signature_label', 'prints_ocr_check', 'prints_completion', 'is_active',
    ];

    protected $casts = [
        'sequence' => 'integer',
        'position' => 'integer',
        'buyer_scoped' => 'boolean',
        'prints_ocr_check' => 'boolean',
        'prints_completion' => 'boolean',
        'is_active' => 'boolean',
    ];

    public static function isAvailable(): bool
    {
        return Schema::hasTable('pra_steps');
    }
}
