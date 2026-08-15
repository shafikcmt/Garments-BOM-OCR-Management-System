<?php

namespace Database\Seeders;

use App\Models\Buyer;
use Illuminate\Database\Seeder;

/**
 * The fixed buyer list the factory works with. Matched on buyer_name so the
 * seeder can be re-run safely without creating duplicates or overwriting
 * contact details an admin has since filled in from the Buyers screen.
 */
class BuyerSeeder extends Seeder
{
    public function run(): void
    {
        $buyers = [
            'Hugo Boss',
            'MACYS',
            'HUMANA',
            'H&M',
            'TARGET',
            'C&A',
            'GAP',
            'ZARA',
            'NEXT',
        ];

        foreach ($buyers as $buyerName) {
            Buyer::firstOrCreate(
                ['buyer_name' => $buyerName],
                ['is_active' => true]
            );
        }
    }
}
