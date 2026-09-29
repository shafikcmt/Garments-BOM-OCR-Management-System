<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * The Month filter's list on the store history screens (Issues, Receiving):
 * "All months" in the view, then the last 24 months newest first, plus the
 * selected one if it is older. An empty <input type="month"> reads
 * "--------- ----", which is why these screens use a list.
 */
final class MonthOptions
{
    /** @return array<string, string> "2026-09" => "September 2026" */
    public static function recent(?string $selected = null, int $months = 24): array
    {
        $options = [];
        $month = now()->startOfMonth();

        for ($i = 0; $i < $months; $i++) {
            $options[$month->format('Y-m')] = $month->format('F Y');
            $month = $month->copy()->subMonth();
        }

        if ($selected && ! isset($options[$selected])) {
            $options[$selected] = Carbon::createFromFormat('!Y-m', $selected)->format('F Y');
        }

        return $options;
    }
}
