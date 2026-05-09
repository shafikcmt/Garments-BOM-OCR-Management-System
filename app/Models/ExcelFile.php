<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExcelFile extends Model
{
    use HasFactory;

    protected $fillable = [
        'file_name',
        'original_file_name',
        'file_path',
        'uploaded_by',
        'upload_batch_no',
        'total_rows',
        'status',
        'remarks',
        'submitted_at',
        'completed_at',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function rows()
    {
        return $this->hasMany(ExcelRow::class);
    }

    public function logs()
    {
        return $this->hasMany(ActivityLog::class);
    }
}