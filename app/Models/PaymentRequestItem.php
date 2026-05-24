<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentRequestItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'payment_request_id',
        'booking_po_id',
        'excel_file_id',
        'excel_row_id',
        'po_no',
        'pi_number',
        'pi_status',
        'pi_rate',
        'pi_amount',
        'payment_status',
        'payment_required_date',
        'supplier_name',
        'buyer_name',
        'season_name',
        'style_name',
        'material_description',
        'sap_code',
        'material_color',
        'qty',
        'delivery_term',
        'payment_term',
        'ship_mode',
        'forwarder',
        'committed_etd',
        'committed_eta',
        'remarks',
        'data',
    ];

    protected $casts = [
        'pi_rate' => 'decimal:4',
        'pi_amount' => 'decimal:4',
        'qty' => 'decimal:4',
        'payment_required_date' => 'date',
        'committed_etd' => 'date',
        'committed_eta' => 'date',
        'data' => 'array',
    ];

    public function paymentRequest()
    {
        return $this->belongsTo(PaymentRequest::class);
    }

    public function bookingPo()
    {
        return $this->belongsTo(BookingPo::class);
    }

    public function excelFile()
    {
        return $this->belongsTo(ExcelFile::class);
    }

    public function excelRow()
    {
        return $this->belongsTo(ExcelRow::class);
    }
}
