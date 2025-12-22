<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Order extends Model {
    use HasFactory;

    protected $fillable = [
        'order_number','buyer_name','season_name','style_name',
        'quantity','contract_number','shipment_date','status',
        'created_by','approved_by'
    ];

    protected $casts = [
    'shipment_date' => 'date',
    ];

    public function creator() { return $this->belongsTo(User::class,'created_by'); }
    public function approver() { return $this->belongsTo(User::class,'approved_by'); }
    public function values() { return $this->hasMany(OrderValue::class); }
}