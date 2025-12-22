<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderValue extends Model {
    protected $fillable = ['order_id','field_id','value','role_id','user_id','is_locked'];
    public function order() { return $this->belongsTo(Order::class); }
    public function field() { return $this->belongsTo(OrderField::class,'field_id'); }
    public function user() { return $this->belongsTo(User::class); }
    public function role() { return $this->belongsTo(Role::class); }
}