<?php

namespace App\Support;

use App\Models\AppSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Central access point for admin-configurable PI / PRA (Payment Request Approval)
 * settings. All values are stored in the app_settings key/value table.
 *
 * Covers:
 *  - The number of Bangladesh working days used for the Payment Require Date.
 *  - Digital signature configuration for the Prepared By / Checked By /
 *    Approved By footer officers.
 */
class PaymentRequestSettings
{
    public const KEY_WORKING_DAYS = 'pra_working_days';

    public const DEFAULT_WORKING_DAYS = 7;

    /**
     * Officer slugs and their footer titles, in display order.
     */
    public const OFFICERS = [
        'prepared' => 'Prepared By',
        'checked' => 'Checked By',
        'approved' => 'Approved By',
    ];

    /**
     * Number of working days added to the apply/created date to reach the
     * Payment Require Date. Always at least 1.
     */
    public static function workingDays(): int
    {
        return max(1, (int) AppSetting::get(self::KEY_WORKING_DAYS, self::DEFAULT_WORKING_DAYS));
    }

    /**
     * Add a number of Bangladesh working days to a date, skipping Friday and
     * Saturday (the official weekly off-days). The result always lands on a
     * working day (Sunday–Thursday).
     */
    public static function addWorkingDays(Carbon $start, int $days): Carbon
    {
        $date = $start->copy();
        $added = 0;

        while ($added < $days) {
            $date->addDay();

            if (! in_array($date->dayOfWeek, [Carbon::FRIDAY, Carbon::SATURDAY], true)) {
                $added++;
            }
        }

        return $date;
    }

    /**
     * Default Payment Require Date for a fresh PRA, based on the configured
     * working-days count counted from today (or the supplied start date).
     */
    public static function defaultPaymentRequiredDate(?Carbon $from = null): Carbon
    {
        return self::addWorkingDays($from ? $from->copy() : Carbon::now(), self::workingDays());
    }

    /**
     * Raw stored value for an officer signature attribute.
     */
    protected static function officerKey(string $officer, string $attribute): string
    {
        return "pra_sign_{$officer}_{$attribute}";
    }

    public static function officerName(string $officer): string
    {
        return (string) AppSetting::get(self::officerKey($officer, 'name'), '');
    }

    public static function officerDesignation(string $officer): string
    {
        return (string) AppSetting::get(self::officerKey($officer, 'designation'), '');
    }

    public static function officerImagePath(string $officer): ?string
    {
        $path = (string) AppSetting::get(self::officerKey($officer, 'image'), '');

        return $path !== '' ? $path : null;
    }

    public static function officerEnabled(string $officer): bool
    {
        return (bool) ((int) AppSetting::get(self::officerKey($officer, 'enabled'), 0));
    }

    /**
     * Signature blocks for the PRA footer, in display order.
     *
     * When $embed is true the image is returned as a base64 data URI suitable
     * for DomPDF; otherwise a public storage URL is returned for web preview.
     * The "src" key is null when the officer's signature should not be shown
     * (toggle off or no image), preserving the blank manual-signing line.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function signatureBlocks(bool $embed = false): array
    {
        $blocks = [];

        foreach (self::OFFICERS as $officer => $title) {
            $path = self::officerImagePath($officer);
            $enabled = self::officerEnabled($officer);
            $show = $enabled && $path && Storage::disk('public')->exists($path);

            $blocks[] = [
                'officer' => $officer,
                'title' => $title,
                'name' => self::officerName($officer),
                'designation' => self::officerDesignation($officer),
                'enabled' => $enabled,
                'has_image' => (bool) $path,
                'src' => $show ? self::imageSource($path, $embed) : null,
            ];
        }

        return $blocks;
    }

    /**
     * Resolve a stored signature image into a renderable source.
     */
    protected static function imageSource(string $path, bool $embed): string
    {
        if (! $embed) {
            return Storage::disk('public')->url($path);
        }

        $contents = Storage::disk('public')->get($path);
        $mime = Storage::disk('public')->mimeType($path) ?: 'image/png';

        return 'data:' . $mime . ';base64,' . base64_encode($contents);
    }
}
