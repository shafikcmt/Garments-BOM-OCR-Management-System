<?php

namespace App\Console\Commands;

use App\Services\FollowUps;
use Illuminate\Console\Command;

/**
 * Daily reminder: everyone with a follow-up due today or overdue gets one
 * notification saying how many. Runs once a day from the scheduler.
 */
class SendFollowUpReminders extends Command
{
    protected $signature = 'followups:remind';

    protected $description = 'Notify people whose follow-up tasks are due today or overdue';

    public function handle(FollowUps $followUps): int
    {
        $this->info($followUps->sendDailyReminders() . ' person(s) reminded.');

        return self::SUCCESS;
    }
}
