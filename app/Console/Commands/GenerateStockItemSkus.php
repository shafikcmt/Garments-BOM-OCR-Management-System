<?php

namespace App\Console\Commands;

use App\Services\StockItemSku;
use Illuminate\Console\Command;

/**
 * Give every General Stock item without an SKU its SKU.
 *
 *   php artisan stock:generate-skus --dry-run   writes an Excel preview,
 *                                               changes nothing
 *   php artisan stock:generate-skus             applies (asks first)
 *
 * Dry-run first, always: the Store team checks the preview before the real
 * run. The real run gives the SKUs in exactly the preview's order (category
 * code, name, brand, id), all or nothing. An item that already has an SKU is
 * never touched.
 */
class GenerateStockItemSkus extends Command
{
    protected $signature = 'stock:generate-skus
        {--dry-run : Write the Excel preview only; change nothing}
        {--force : Apply without asking (for a deploy script)}';

    protected $description = 'Give every General Stock item without an SKU its SKU (dry-run first).';

    public function handle(StockItemSku $sku): int
    {
        $this->line('Format: '.StockItemSku::pattern());

        if ($this->option('dry-run')) {
            $preview = $sku->writePreview();
            $this->info($preview['count'].' item(s) would get an SKU. Nothing was changed.');
            $this->line('Preview: '.$preview['path']);

            return self::SUCCESS;
        }

        $missing = $sku->missing()->count();

        if ($missing === 0) {
            $this->info('Every item already has an SKU.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm($missing.' item(s) will get an SKU. SKUs never change afterwards. Apply now?')) {
            $this->warn('Nothing was changed.');

            return self::SUCCESS;
        }

        $this->info($sku->generateMissing().' item(s) now have an SKU.');

        return self::SUCCESS;
    }
}
