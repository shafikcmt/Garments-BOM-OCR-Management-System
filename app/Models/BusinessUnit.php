<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A company / business unit a vendor can be applicable to. */
class BusinessUnit extends Model
{
    protected $fillable = ['code', 'name', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];
}
