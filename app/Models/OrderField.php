<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderField extends Model {
    protected $fillable = ['section_id','field_label','field_key','field_type','options','is_required','sort_order','is_active'];
    protected $casts = ['options'=>'array','is_required'=>'boolean','is_active'=>'boolean'];
    public function section() { return $this->belongsTo(OrderSection::class,'section_id'); }
    public function values() { return $this->hasMany(OrderValue::class,'field_id'); }
}