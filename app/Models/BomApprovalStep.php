<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BomApprovalStep extends Model
{
    public $timestamps = false;

    protected $fillable = ['bom_approval_id', 'cycle', 'level', 'action', 'user_id', 'comment', 'created_at'];

    protected $casts = ['created_at' => 'datetime'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
