<?php

namespace App\Support;

use App\Models\AppSetting;
use App\Models\PraApproval;
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
     * Signature blocks for a specific PRA, populated with the personal signature
     * of whoever performed each step (Prepared / Checked / Approved) in the
     * digital flow. Falls back to the static admin-configured signature exactly
     * as before whenever a PRA has no approval flow, or per-box when a step has
     * no assigned actor.
     *
     * Block shape:
     *   title, name, designation, date, src (nullable), dynamic (bool)
     * The view uses `dynamic` to decide between: image, typed name+date, or a
     * blank manual-signing line.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function signatureBlocksFor(\App\Models\PaymentRequest $pr, bool $embed = false): array
    {
        // No approval flow at all -> preserve the exact legacy static behaviour.
        if ($pr->approvals->isEmpty()) {
            return array_map(
                fn (array $block) => self::staticBlock($block['officer'], $block['title'], $embed),
                self::officerList()
            );
        }

        $check = $pr->currentCheckApproval();
        $approveRows = $pr->currentApproveApprovals();
        $progress = $pr->approvalProgress();

        // Prepared By -> creator, always.
        $prepared = self::dynamicBlock(
            'prepared', self::OFFICERS['prepared'],
            $pr->createdBy, $pr->created_at, $pr->prepared_signature_path, $embed
        );

        // Checked By -> checker when the check step is complete; blank line while
        // a checker is assigned but has not checked; static fallback otherwise.
        if ($check) {
            $checked = $check->isApproved()
                ? self::dynamicBlock('checked', self::OFFICERS['checked'], $check->approver, $check->acted_at, $check->signature_path, $embed)
                : self::blankBlock('checked', self::OFFICERS['checked']);
        } else {
            $checked = self::staticBlock('checked', self::OFFICERS['checked'], $embed);
        }

        // Approved By -> last approver once fully approved; blank while pending;
        // static fallback when no approver was ever assigned.
        if ($approveRows->isNotEmpty()) {
            if (($progress['state'] ?? null) === \App\Models\PaymentRequest::STATUS_APPROVED) {
                $last = $approveRows->where('status', PraApproval::STATUS_APPROVED)
                    ->sortByDesc(fn (PraApproval $a) => optional($a->acted_at)->getTimestamp())
                    ->first();
                $approved = self::dynamicBlock('approved', self::OFFICERS['approved'], $last?->approver, $last?->acted_at, $last?->signature_path, $embed);
            } else {
                $approved = self::blankBlock('approved', self::OFFICERS['approved']);
            }
        } else {
            $approved = self::staticBlock('approved', self::OFFICERS['approved'], $embed);
        }

        return [$prepared, $checked, $approved];
    }

    /**
     * @return array<int, array{officer:string, title:string}>
     */
    protected static function officerList(): array
    {
        $list = [];
        foreach (self::OFFICERS as $officer => $title) {
            $list[] = ['officer' => $officer, 'title' => $title];
        }

        return $list;
    }

    /**
     * A block populated from the acting user's personal signature/details.
     */
    protected static function dynamicBlock(string $officer, string $title, ?\App\Models\User $user, $date, ?string $signaturePath, bool $embed): array
    {
        $path = $signaturePath ?: ($user?->signature_path);
        $show = $path && Storage::disk('public')->exists($path);

        return [
            'officer' => $officer,
            'title' => $title,
            'name' => $user?->name ?? '',
            'designation' => $user ? $user->departmentLabel() : '',
            'date' => $date ? \Illuminate\Support\Carbon::parse($date)->format('jS M-Y') : '',
            'src' => $show ? self::imageSource($path, $embed) : null,
            'path' => $show ? $path : null,
            'dynamic' => true,
        ];
    }

    /**
     * A blank block (assigned but not yet signed) — keeps the manual signing line.
     */
    protected static function blankBlock(string $officer, string $title): array
    {
        return [
            'officer' => $officer,
            'title' => $title,
            'name' => '',
            'designation' => '',
            'date' => '',
            'src' => null,
            'path' => null,
            'dynamic' => false,
        ];
    }

    /**
     * A block populated from the static admin-configured officer signature.
     */
    protected static function staticBlock(string $officer, string $title, bool $embed): array
    {
        $path = self::officerImagePath($officer);
        $enabled = self::officerEnabled($officer);
        $show = $enabled && $path && Storage::disk('public')->exists($path);

        return [
            'officer' => $officer,
            'title' => $title,
            'name' => self::officerName($officer),
            'designation' => self::officerDesignation($officer),
            'date' => '',
            'src' => $show ? self::imageSource($path, $embed) : null,
            'path' => $show ? $path : null,
            'dynamic' => false,
        ];
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
