<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MotherStyle extends Model
{
    protected $fillable = [
        'buyer_id', 'buyer_season_id', 'style_no', 'product_group',
        'description', 'status', 'created_by',
    ];

    public function buyer()
    {
        return $this->belongsTo(Buyer::class);
    }

    public function season()
    {
        return $this->belongsTo(BuyerSeason::class, 'buyer_season_id');
    }

    public function bomApproval()
    {
        return $this->hasOne(BomApproval::class);
    }

    public function orderLines()
    {
        return $this->hasMany(OrderLine::class);
    }

    public function children()
    {
        return $this->hasMany(ChildStyle::class)->orderBy('style_no');
    }
}
