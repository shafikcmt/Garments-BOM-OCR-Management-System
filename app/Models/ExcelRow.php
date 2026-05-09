<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExcelRow extends Model
{
    use HasFactory;

    protected $fillable = [
        'excel_file_id',
        'row_number',
    ];

    public function excelFile()
    {
        return $this->belongsTo(ExcelFile::class);
    }

    public function cells()
    {
        return $this->hasMany(ExcelCell::class, 'row_id');
    }

    public function logs()
    {
        return $this->hasMany(ActivityLog::class, 'row_id');
    }

    public function bookingPo()
    {
        return $this->hasOne(BookingPo::class, 'excel_row_id');
    }
}
