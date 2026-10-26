<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BuyerImportProfile extends Model
{
    protected $fillable = ['buyer_id', 'name', 'sheet_name', 'header_row', 'created_by'];

    public function buyer()
    {
        return $this->belongsTo(Buyer::class);
    }

    public function columns()
    {
        return $this->hasMany(BuyerImportProfileColumn::class, 'profile_id');
    }
}
