<?php

namespace App\Imports;

use App\Models\Order;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;
use Maatwebsite\Excel\Concerns\SkipsOnFailure;
use Maatwebsite\Excel\Concerns\SkipsFailures;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

class OrdersImport implements ToModel, WithHeadingRow, WithValidation, SkipsOnFailure, SkipsEmptyRows
{
    use SkipsFailures;

    protected $userId;

    public function __construct($userId)
    {
        $this->userId = $userId;
    }

    private function parseDate($value)
    {
        if (!$value) return null;

        if (is_numeric($value)) {
            return date('Y-m-d', ExcelDate::excelToTimestamp($value));
        }

        $d = \DateTime::createFromFormat('d-m-Y', $value);
        return $d ? $d->format('Y-m-d') : null;
    }

    private function addDays($date, $days)
    {
        if (!$date) return null;
        $d = new \DateTime($date);
        $d->modify("$days day");
        return $d->format('Y-m-d');
    }

    public function model(array $row)
    {
        $orderQty = $row['order_qty'] ?? 0;
        $sewingQty = $row['sewing_qty'] ?? 0;
        $smv = $row['smv'] ?? 0;
        $fob = $row['fob'] ?? 0;

        $balanceToSewing = $row['balance_to_sewing'] ?? ($orderQty - $sewingQty);
        $totalMinutes = $row['total_minutes'] ?? ($orderQty * $smv);
        $salesValue = $row['sales_value'] ?? ($orderQty * $fob);

        $xFty = $this->parseDate($row['x_fty'] ?? null);
        $originalXFty = $this->parseDate($row['original_x_fty'] ?? null);

        $pcd = $row['pcd'] ?? $this->addDays($xFty, -45); 
        $xCountry = $row['x_country'] ?? $this->addDays($xFty, 2);
        $originalXCountry = $row['original_x_country'] ?? $this->addDays($originalXFty, 2);

        return new Order([
            'buyer_name'  => $row['buyer_name'],
            'division'    => $row['division'],
            'season_name' => $row['season_name'],
            'order_status'=> $row['order_status'] ?? 'pending',
            'order_category' => $row['order_category'] ?? null,
            'product_type'=> $row['product_type'] ?? null,
            'style_name'  => $row['style_name'] ?? null,
            'po_number'   => $row['po_number'] ?? null,
            'description' => $row['description'] ?? null,
            'wash_type'   => $row['wash_type'] ?? null,
            'order_qty'   => $orderQty,
            'sewing_qty'  => $sewingQty,
            'balance_to_sewing' => $balanceToSewing,
            'smv'         => $smv,
            'total_minutes' => $totalMinutes,
            'fob'         => $fob,
            'sales_value' => $salesValue,
            'gm'          => $row['gm'] ?? 0,
            'destination' => $row['destination'] ?? null,
            'pcd' => $pcd,
            'x_fty' => $xFty,
            'x_country' => $xCountry,
            'original_x_fty' => $originalXFty,
            'original_x_country' => $originalXCountry,
            'shipment_status' => $row['shipment_status'] ?? null,
            'fabric_booking_status' => $row['fabric_booking_status'] ?? null,
            'remarks' => $row['remarks'] ?? null,
            'status' => $row['status'] ?? 'active',
            'created_by' => $this->userId,
        ]);
    }

    public function rules(): array
    {
        return [
            'buyer_name' => 'required|string',
            'division' => 'required|string',
            'season_name' => 'required|string',
            'order_qty' => 'required|numeric|min:1',
            'x_fty' => 'nullable',
        ];
    }

    public function customValidationMessages()
    {
        return [
            'buyer_name.required' => 'Buyer name is required',
            'division.required' => 'Division is required',
            'season_name.required' => 'Season name is required',
            'order_qty.required' => 'Order quantity is required',
            'order_qty.numeric' => 'Order quantity must be a number',
            'x_fty.date_format' => 'X FTY must be in dd-mm-yyyy format',
        ];
    }
}
