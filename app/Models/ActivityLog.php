<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ActivityLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'excel_file_id',
        'row_id',
        'header_id',
        'old_value',
        'new_value',
        'action',
        'user_id',
    ];

    public function excelFile()
    {
        return $this->belongsTo(ExcelFile::class);
    }

    public function row()
    {
        return $this->belongsTo(ExcelRow::class, 'row_id');
    }

    public function header()
    {
        return $this->belongsTo(ExcelHeader::class, 'header_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}