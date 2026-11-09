<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One step in a sample's life: a status change and/or a buyer comment. */
class SampleLog extends Model
{
    public $timestamps = false;

    protected $fillable = ['sample_id', 'status', 'note', 'event_date', 'user_id', 'created_at'];

    protected $casts = ['event_date' => 'date', 'created_at' => 'datetime'];

    public function sample()
    {
        return $this->belongsTo(Sample::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
