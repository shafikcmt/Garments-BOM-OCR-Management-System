<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BookingPo extends Model
{
    use HasFactory;

    protected $fillable = [
        'excel_file_id',
        'excel_row_id',
        'po_no',
        'buyer_code',
        'season_code',
        'buyer_name',
        'season_name',
        'ihod',
        'vendor_name',
        'style_name',
        'item_name',
        'qty',
        'uom',
        'item_type',
        'description',
        'color',
        'size_width',
        'supplier_article',
        'consumption',
        'remarks',
        'booking_data',
        'status',
        'generated_by',
        'generated_at',
        'completed_by',
        'completed_at',
    ];

    protected $casts = [
        'booking_data' => 'array',
        'generated_at' => 'datetime',
        'completed_at' => 'datetime',
    ];


    public function getRevisionNoAttribute($value): int
    {
        if ($value !== null) {
            return max(0, (int) $value);
        }

        $data = $this->booking_data ?: [];

        return max(0, (int) ($data['revision_no'] ?? 0));
    }

    public function getNeedsRegenerateAttribute($value): bool
    {
        if ($value !== null) {
            return (bool) $value;
        }

        $data = $this->booking_data ?: [];

        return (bool) ($data['needs_regenerate'] ?? false);
    }

    public function excelFile()
    {
        return $this->belongsTo(ExcelFile::class);
    }

    public function excelRow()
    {
        return $this->belongsTo(ExcelRow::class);
    }

    public function generatedBy()
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function completedBy()
    {
        return $this->belongsTo(User::class, 'completed_by');
    }
}
