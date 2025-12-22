<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderSection extends Model {
    protected $fillable = ['name','role_id','description','sort_order','is_active'];
    public function role() { return $this->belongsTo(Role::class,'role_id'); }
    public function fields() { return $this->hasMany(OrderField::class,'section_id'); }
}
