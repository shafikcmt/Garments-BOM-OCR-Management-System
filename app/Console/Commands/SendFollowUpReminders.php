<?php

namespace App\Console\Commands;

use App\Services\FollowUps;
use Illuminate\Console\Command;

/**
 * Daily reminder: everyone with a follow-up due today or overdue gets one
 * notification (and an e-mail when Admin has switched that on) saying how many;
 * tasks overdue for the escalation days go to the supervisor once. Runs once a day from the scheduler.
 */
class SendFollowUpReminders extends Command
{
    protected $signature = 'followups:remind';

    protected $description = 'Notify people whose follow-up tasks are due today or overdue, and escalate long-overdue ones to the supervisor';

    public function handle(FollowUps $followUps): int
    {
        $this->info($followUps->sendDailyReminders() . ' person(s) reminded.');
        $this->info($followUps->escalateOverdue() . ' task(s) escalated.');

        return self::SUCCESS;
    }
}
