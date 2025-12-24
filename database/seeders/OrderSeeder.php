<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Support\Str;

class OrderSeeder extends Seeder
{
    public function run()
    {
        $orderStatuses = ['CONFIRMED', 'PROCESSING', 'COMPLETED'];
        $shipmentStatuses = ['SHIPPED', 'PENDING'];
        $fabricStatuses = ['FABRIC BOOKED', 'NOT BOOKED'];

        $buyers = ['HB-OW', 'H&M', 'ZARA', 'NEXT', 'C&A'];
        $divisions = ['OW HMR', 'HB-OW', 'KIDS', 'MEN'];
        $seasons = ['W25FA', 'S26SP', 'A25AU'];
        $orderCategories = ['BULK', 'SAMPLE'];
        $productTypes = ['Non Down Jacket', 'Down Jacket', 'T-Shirt', 'Coat'];
        $washTypes = ['NON WASH', 'ENZYME', 'STONE'];
        $destinations = ['USA', 'CAN', 'UK', 'GER'];

        for ($i = 1; $i <= 20; $i++) {

            $orderQty  = rand(1000, 5000);
            $sewingQty = rand(800, $orderQty);
            $balance   = $orderQty - $sewingQty;

            $fob = rand(20, 60);
            $salesValue = $orderQty * $fob;

            $pcd = Carbon::now()->addDays(rand(10, 30));
            $xFty = (clone $pcd)->addDays(45);
            $xCountry = (clone $xFty)->addDays(3);

            Order::create([
                // BASIC
                'buyer_name'         => $buyers[array_rand($buyers)],
                'division'           => $divisions[array_rand($divisions)],
                'season_name'        => $seasons[array_rand($seasons)],
                'order_status'       => 'CONFIRMED',
                'order_category'     => $orderCategories[array_rand($orderCategories)],
                'product_type'       => $productTypes[array_rand($productTypes)],

                // STYLE & PO
                'style_name'         => 'BERO' . rand(1000, 9999),
                'po_number'          => 'PO' . now()->timestamp . $i,
                'description'        => 'MEN JACKET',
                'wash_type'          => $washTypes[array_rand($washTypes)],

                // QTY
                'order_qty'          => $orderQty,
                'sewing_qty'         => $sewingQty,
                'balance_to_sewing'  => $balance,

                // PRODUCTION
                'smv'                => rand(100, 200) + rand(0,99)/100,
                'total_minutes'      => ($orderQty * rand(80, 150)),

                // COMMERCIAL
                'fob'                => $fob,
                'sales_value'        => $salesValue,
                'gm'                 => rand(10, 20),
                'destination'        => $destinations[array_rand($destinations)],

                // DATES
                'pcd'                => $pcd,
                'x_fty'              => $xFty,
                'x_country'          => $xCountry,
                'original_x_fty'     => $xFty,
                'original_x_country' => $xCountry,

                // STATUS
                'shipment_status'       => $shipmentStatuses[array_rand($shipmentStatuses)],
                'fabric_booking_status' => $fabricStatuses[array_rand($fabricStatuses)],

                // REMARKS
                'remarks'            => null,

                // SYSTEM
                'status'             => $orderStatuses[array_rand($orderStatuses)],
                'created_by'         => rand(1, 3),
                'approved_by'        => rand(1, 3),
                'created_at'         => now(),
                'updated_at'         => now(),
            ]);
        }
    }
}
