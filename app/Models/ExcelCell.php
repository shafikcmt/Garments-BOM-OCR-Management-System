<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExcelCell extends Model
{
    use HasFactory;

    protected $fillable = [
        'row_id',
        'header_id',
        'value',
        'updated_by',
    ];

    public function row()
    {
        return $this->belongsTo(ExcelRow::class, 'row_id');
    }

    public function header()
    {
        return $this->belongsTo(ExcelHeader::class, 'header_id');
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}