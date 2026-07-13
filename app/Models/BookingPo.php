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

    /**
     * Denormalized identity payload copied onto Store stock event rows
     * (material_receivings / bulk_issues / movements / requisitions). Read-only
     * helper — does not affect booking generation.
     *
     * @return array<string, mixed>
     */
    public function toStockPayload(): array
    {
        return [
            'excel_file_id' => $this->excel_file_id,
            'excel_row_id' => $this->excel_row_id,
            'booking_po_id' => $this->id,
            'po_no' => $this->po_no,
            'buyer_name' => $this->buyer_name,
            'season_name' => $this->season_name,
            'style_name' => $this->style_name,
            'material_description' => $this->item_name ?: $this->description,
            // SAP Code is not a booking_pos column — it lives in the BOM row cell
            // (Merchant-owned header). Resolve it via the shared source service so
            // every stock event row (receiving/issue/movement/requisition) carries
            // it instead of always ending up null. Falls back to supplier_article.
            'sap_code' => app(\App\Services\BookingPoSourceService::class)
                ->sourceValueForBookingPo($this, 'sap_code'),
            'material_color' => $this->color,
            'size' => $this->size_width,
            'uom' => $this->uom,
        ];
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
