<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A block of rows added to an existing workspace file by the import wizard. */
class ExcelFileImport extends Model
{
    protected $fillable = [
        'excel_file_id', 'user_id', 'source_name', 'row_count', 'skipped_duplicates',
        'first_row_number', 'last_row_number', 'undone_at', 'undone_by',
    ];

    protected $casts = ['undone_at' => 'datetime'];

    public function file()
    {
        return $this->belongsTo(ExcelFile::class, 'excel_file_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function rows()
    {
        return $this->hasMany(ExcelRow::class, 'excel_file_import_id');
    }
}
