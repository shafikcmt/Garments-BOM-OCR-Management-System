<?php

namespace App\Console\Commands;

use App\Models\BookingPo;
use App\Services\BookingPoSourceService;
use Illuminate\Console\Command;

class CheckMissingPiAlerts extends Command
{
    protected $signature = 'po:check-missing-pi-alerts {--days=3 : Number of days to wait after PO generation}';

    protected $description = 'Send red alert notifications when PI is not received within the configured PO generation waiting period.';

    public function handle(BookingPoSourceService $sourceService): int
    {
        $days = max(1, (int) $this->option('days'));
        $cutoff = now()->subDays($days);
        $checked = 0;
        $notifications = 0;

        BookingPo::query()
            ->with(['excelFile.uploader', 'excelRow.cells.header', 'generatedBy'])
            ->whereNotNull('generated_at')
            ->where('generated_at', '<=', $cutoff)
            ->where(function ($query) {
                $query->where('status', 'completed')
                    ->orWhereNotNull('completed_at');
            })
            ->orderBy('id')
            ->chunkById(100, function ($bookingPos) use ($sourceService, &$checked, &$notifications) {
                foreach ($bookingPos as $bookingPo) {
                    $checked++;
                    $notifications += $sourceService->notifyPiMissingForBookingPo($bookingPo);
                }
            });

        $this->info("Checked {$checked} generated PO(s). Created {$notifications} PI missing notification(s).");

        return self::SUCCESS;
    }
}
