<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChildStyle extends Model
{
    public const KINDS = [
        'variant' => 'Fit variant',
        'linked' => 'Linked style no.',
        'other' => 'Other',
    ];

    protected $fillable = ['mother_style_id', 'style_no', 'kind', 'description'];

    public function mother()
    {
        return $this->belongsTo(MotherStyle::class, 'mother_style_id');
    }
}
