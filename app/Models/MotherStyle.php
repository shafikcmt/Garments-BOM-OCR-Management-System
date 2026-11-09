<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MotherStyle extends Model
{
    protected $fillable = [
        'buyer_id', 'buyer_season_id', 'style_no', 'product_group',
        'description', 'status', 'created_by', 'size_set_id',
    ];

    public function buyer()
    {
        return $this->belongsTo(Buyer::class);
    }

    public function season()
    {
        return $this->belongsTo(BuyerSeason::class, 'buyer_season_id');
    }

    public function costings()
    {
        return $this->hasMany(Costing::class);
    }

    public function bomApproval()
    {
        return $this->hasOne(BomApproval::class);
    }

    public function buyerOrders()
    {
        return $this->hasMany(BuyerOrder::class);
    }

    public function documents()
    {
        return $this->hasMany(StyleDocument::class)->latest('id');
    }

    public function samples()
    {
        return $this->hasMany(Sample::class);
    }

    public function sizeSet()
    {
        return $this->belongsTo(SizeSet::class);
    }

    public function sizeQuantities()
    {
        return $this->hasMany(OrderSizeQuantity::class);
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
