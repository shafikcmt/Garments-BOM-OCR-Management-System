<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Pieces of one size for one colour / customer PO of a style. */
class OrderSizeQuantity extends Model
{
    protected $fillable = ['mother_style_id', 'gmts_color', 'customer_po_no', 'size', 'qty', 'updated_by'];

    protected $casts = ['qty' => 'decimal:2'];

    public function motherStyle()
    {
        return $this->belongsTo(MotherStyle::class);
    }
}
