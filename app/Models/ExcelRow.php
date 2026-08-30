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
        'duplicated_from_row_id',
        'duplicated_at',
    ];

    protected $casts = [
        'duplicated_at' => 'datetime',
    ];

    /**
     * Whether the "Duplicated" badge should show for this row.
     *
     * Reads duplicated_at, NOT duplicated_from_row_id: dismissing the badge
     * clears the timestamp and deliberately keeps the lineage, so a dismissed
     * duplicate is still traceable but no longer flagged.
     */
    public function isFlaggedDuplicate(): bool
    {
        return $this->duplicated_at !== null;
    }

    /** The row this one was copied from, if it still exists. */
    public function duplicatedFrom()
    {
        return $this->belongsTo(self::class, 'duplicated_from_row_id');
    }

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
