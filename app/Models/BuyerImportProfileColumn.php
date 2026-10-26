<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BuyerImportProfileColumn extends Model
{
    protected $fillable = ['profile_id', 'source_header', 'source_key', 'excel_header_id'];

    public function profile()
    {
        return $this->belongsTo(BuyerImportProfile::class, 'profile_id');
    }
}
