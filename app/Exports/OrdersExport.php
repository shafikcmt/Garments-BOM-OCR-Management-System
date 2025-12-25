<?php
namespace App\Exports;

use App\Models\Order;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class OrdersExport implements FromCollection, WithHeadings
{
    protected $demo;
    protected $filters;

    public function __construct($demo=false, $filters=[])
    {
        $this->demo = $demo;
        $this->filters = $filters;
    }

    public function collection()
    {
        if($this->demo){
            return collect([
                ['buyer_name'=>'Demo Buyer','division'=>'Demo','season_name'=>'2025','order_status'=>'CONFIRMED','order_category'=>'BULK','product_type'=>'Shirt','style_name'=>'S123','po_number'=>'PO001','order_qty'=>100,'sewing_qty'=>50]
            ]);
        }

        $query = Order::query();

        if($this->filters['buyer_name']??false) $query->where('buyer_name','like',"%{$this->filters['buyer_name']}%");
        if($this->filters['season_name']??false) $query->where('season_name','like',"%{$this->filters['season_name']}%");
        if($this->filters['order_status']??false) $query->where('order_status',$this->filters['order_status']);

        return $query->get(['buyer_name','division','season_name','order_status','order_category','product_type','style_name','po_number','order_qty','sewing_qty']);
    }

    public function headings(): array
    {
        return ['Buyer Name','Division','Season','Order Status','Order Category','Product Type','Style Name','PO Number','Order Qty','Sewing Qty'];
    }
}
