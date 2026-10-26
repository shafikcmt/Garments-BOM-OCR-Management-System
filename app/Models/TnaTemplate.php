<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A Time and Action plan: tasks due relative to an order date (Admin > TnA Templates). */
class TnaTemplate extends Model
{
    protected $fillable = ['name', 'buyer_id', 'is_active', 'auto_apply', 'created_by'];

    protected $casts = ['is_active' => 'boolean', 'auto_apply' => 'boolean'];

    public function buyer()
    {
        return $this->belongsTo(Buyer::class);
    }

    public function tasks()
    {
        return $this->hasMany(TnaTemplateTask::class)->orderBy('position')->orderBy('id');
    }

    /** Templates that may be used for a buyer: its own and the all-buyer ones. */
    public function scopeForBuyer($query, ?int $buyerId)
    {
        return $query->where('is_active', true)->where(fn ($q) => $q->whereNull('buyer_id')->when($buyerId, fn ($q) => $q->orWhere('buyer_id', $buyerId)));
    }
}
