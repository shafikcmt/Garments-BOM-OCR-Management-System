<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\ExcelHeader;
use Spatie\Permission\Models\Role;

class ExcelHeaderSeeder extends Seeder
{
    public function run(): void
    {
        $roles = Role::whereIn('name', [
            'merchant',
            'supply_chain',
            'commercial',
            'account',
            'store',
        ])->get()->keyBy('name');

        $headers = [
            ['header_name' => 'Buyer Name', 'header_key' => 'buyer_name', 'role' => 'merchant', 'position' => 1, 'field_type' => 'text'],
            ['header_name' => 'Season Name', 'header_key' => 'season_name', 'role' => 'merchant', 'position' => 2, 'field_type' => 'text'],
            ['header_name' => 'Style Name', 'header_key' => 'style_name', 'role' => 'merchant', 'position' => 3, 'field_type' => 'text'],
            ['header_name' => 'GMTS Color Name', 'header_key' => 'gmts_color_name', 'role' => 'merchant', 'position' => 4, 'field_type' => 'text'],
            ['header_name' => 'Contract Number', 'header_key' => 'contract_number', 'role' => 'merchant', 'position' => 5, 'field_type' => 'text'],

            ['header_name' => 'Material Description', 'header_key' => 'material_description', 'role' => 'supply_chain', 'position' => 6, 'field_type' => 'text'],
            ['header_name' => 'SAP Code', 'header_key' => 'sap_code', 'role' => 'supply_chain', 'position' => 7, 'field_type' => 'text'],
            ['header_name' => 'Vendor Name', 'header_key' => 'vendor_name', 'role' => 'supply_chain', 'position' => 8, 'field_type' => 'text'],
            ['header_name' => 'Material Order Status', 'header_key' => 'material_order_status', 'role' => 'supply_chain', 'position' => 9, 'field_type' => 'text'],

            ['header_name' => 'Delivery Term', 'header_key' => 'delivery_term', 'role' => 'commercial', 'position' => 10, 'field_type' => 'text'],
            ['header_name' => 'Payment Term', 'header_key' => 'payment_term', 'role' => 'commercial', 'position' => 11, 'field_type' => 'text'],
            ['header_name' => 'Payment Status', 'header_key' => 'payment_status', 'role' => 'commercial', 'position' => 12, 'field_type' => 'text'],

            ['header_name' => 'Ship Mode', 'header_key' => 'ship_mode', 'role' => 'account', 'position' => 13, 'field_type' => 'text'],
            ['header_name' => 'BL / AWB No', 'header_key' => 'bl_awb_no', 'role' => 'account', 'position' => 14, 'field_type' => 'text'],
            ['header_name' => 'Committed ETA', 'header_key' => 'committed_eta', 'role' => 'account', 'position' => 15, 'field_type' => 'date'],

            ['header_name' => 'Invoiced Qty', 'header_key' => 'invoiced_qty', 'role' => 'store', 'position' => 16, 'field_type' => 'number'],
            ['header_name' => 'Receipt Qty', 'header_key' => 'receipt_qty', 'role' => 'store', 'position' => 17, 'field_type' => 'number'],
            ['header_name' => 'Issued Qty', 'header_key' => 'issued_qty', 'role' => 'store', 'position' => 18, 'field_type' => 'number'],
        ];

        foreach ($headers as $header) {
            ExcelHeader::updateOrCreate(
                ['header_key' => $header['header_key']],
                [
                    'header_name' => $header['header_name'],
                    'owner_role_id' => $roles[$header['role']]->id ?? null,
                    'position' => $header['position'],
                    'field_type' => $header['field_type'],
                    'is_required' => false,
                    'is_active' => true,
                    'can_view_all' => true,
                    'can_edit_owner_only' => true,
                    'merchant_can_upload' => $header['merchant_can_upload'] ?? false,
                ]
            );
        }
    }
}