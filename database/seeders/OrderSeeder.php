<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\Carbon;

class OrderSeeder extends Seeder
{
    public function run()
    {
        $statuses = ['draft', 'pending', 'approved', 'rejected'];
        $buyers = ['John Doe', 'Alice Smith', 'Bob Johnson', 'Charlie Brown', 'David Lee'];
        $seasons = ['Spring 2026', 'Summer 2026', 'Autumn 2025', 'Winter 2025'];
        $styles = ['Jacket', 'T-Shirt', 'Coat', 'Shirt', 'Pants'];
        
        for ($i = 1; $i <= 20; $i++) {
            $shipmentDate = Carbon::now()->addDays(rand(1, 90));
            
            Order::create([
                'buyer_name'       => $buyers[array_rand($buyers)],
                'season_name'      => $seasons[array_rand($seasons)],
                'order_number'     => 'ORD' . rand(10000, 99999),
                'style_name'       => $styles[array_rand($styles)],
                'quantity'         => rand(50, 500),
                'shipment_date'    => $shipmentDate->format('Y-m-d'),
                'contract_number'  => 'CN' . rand(10000, 99999),
                'status'           => $statuses[array_rand($statuses)],
                'created_by'       => rand(1, 5),
                'approved_by'      => rand(1, 5),
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);
        }
    }
}
