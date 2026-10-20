<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BuyerSeason extends Model
{
    protected $fillable = ['buyer_id', 'code', 'name', 'status'];

    public function buyer()
    {
        return $this->belongsTo(Buyer::class);
    }

    public function motherStyles()
    {
        return $this->hasMany(MotherStyle::class)->orderBy('style_no');
    }
}
