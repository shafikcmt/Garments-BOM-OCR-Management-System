<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderField extends Model
{
    use HasFactory;

    protected $fillable = [
        'section_id',
        'field_label',
        'field_key',
        'field_type',
        'options',
        'is_required',
        'formula',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'options' => 'array', // JSON to array
        'is_required' => 'boolean',
        'is_active' => 'boolean',
    ];

    /**
     * Section this field belongs to
     */
    public function section()
    {
        return $this->belongsTo(OrderSection::class, 'section_id');
    }

    /**
     * Values entered for this field
     */
    public function values()
    {
        return $this->hasMany(OrderValue::class, 'field_id');
    }
}
