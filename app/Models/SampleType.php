<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A kind of sample (Proto, Fit, PP ...), kept on the Samples screen. */
class SampleType extends Model
{
    protected $fillable = ['name', 'position', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('position')->orderBy('name');
    }
}
