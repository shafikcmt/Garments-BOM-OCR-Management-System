<?php

namespace App\Console\Commands;

use App\Services\TnaPlans;
use Illuminate\Console\Command;

/**
 * Daily: applies every active "auto apply" TnA template to the styles of its
 * buyer(s) that have orders. Tasks already made are never repeated.
 */
class ApplyTnaTemplates extends Command
{
    protected $signature = 'tna:apply';

    protected $description = 'Create TnA tasks from auto-apply templates for styles that have orders';

    public function handle(TnaPlans $plans): int
    {
        $this->info($plans->autoApply() . ' TnA task(s) created.');

        return self::SUCCESS;
    }
}
