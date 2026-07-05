<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A user in the PRA approver pool. Membership here is what grants a user the
 * ability to receive and act on PRA approval requests.
 */
class PraApprover extends Model
{
    protected $fillable = [
        'user_id',
        'is_active',
        'added_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function addedBy()
    {
        return $this->belongsTo(User::class, 'added_by');
    }
}
