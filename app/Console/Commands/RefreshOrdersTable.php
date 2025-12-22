<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

class RefreshOrdersTable extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'db:refresh-orders';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Refresh only the orders table';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Dropping orders table if exists...');
        Schema::dropIfExists('orders');

        $this->info('Re-running orders migration...');
        // Replace the filename with your orders migration file
        Artisan::call('migrate', [
            '--path' => 'database/migrations/2025_12_21_000000_create_orders_table.php'
        ]);

        $this->info('Orders table refreshed successfully!');
    }
}
