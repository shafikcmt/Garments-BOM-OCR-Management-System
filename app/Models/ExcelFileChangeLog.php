<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExcelFileChangeLog extends Model
{
    protected $fillable = [
        'excel_file_id',
        'excel_row_id',
        'excel_header_id',
        'row_number',
        'header_name',
        'old_value',
        'new_value',
        'changed_by',
        'batch_id',
    ];
}