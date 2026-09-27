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

    /**
     * Whether a NEWLY CREATED PRA joins the 6-step departmental chain.
     *
     * READ AT EXACTLY ONE MOMENT: PaymentRequestController::store(), when the
     * PRA is created. Its answer is then stamped onto the row as flow_version
     * and never consulted again. Every downstream decision — who may act, which
     * queue lists it, which controller accepts it, who is notified, which
     * document it prints — reads the RECORD's own flow_version.
     *
     * That is what makes a runtime toggle safe here where it would not be for a
     * permission. Turning this off cannot reach a PRA that already exists: there
     * is no code path in which an in-flight PRA asks this question a second
     * time. The toggle chooses a default at birth; it does not govern access.
     */
    public const KEY_DEPARTMENTAL_FLOW = 'pra_departmental_flow_enabled';

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
     * The FOUR signature blocks of a departmental (flow 2) PRA, in print order,
     * each with the chain step that fills it and its default printed label.
     *
     *   prepared           the creator. No step — they authored it, and their
     *                      signature is snapshotted on the PRA itself.
     *   checked            step 2, Merchant Team.
     *   approved_merchant  step 3, Merchant Head.
     *   approved_finance   step 5, Accounts Head.
     *
     * STEP 6 (Commercial Head) HAS NO BLOCK, deliberately: completing a PRA is
     * recording a payment, not endorsing the document. It is printed instead as
     * a single "Paid / Completed by" line — see completionLineFor().
     *
     * SEPARATE FROM OFFICERS ABOVE, and that separation is the point. OFFICERS
     * holds the LEGACY titles; sharing them would mean an admin editing a
     * departmental label silently re-titles the printed document of all 19
     * flow-1 PRAs.
     *
     * The MAPPING is structural and not configurable — only the label text is.
     * Which step signs which line is what the document means; letting that be
     * edited from a settings screen would make two printouts of the same PRA
     * disagree about who approved it.
     *
     * @var array<string, array{label: string, sequence: int|null}>
     */
    public const FLOW2_BLOCKS = [
        'prepared' => ['label' => 'Prepared By', 'sequence' => null],
        'checked' => ['label' => 'Checked By', 'sequence' => 2],
        'approved_merchant' => ['label' => 'Approved By (Merchant)', 'sequence' => 3],
        'approved_finance' => ['label' => 'Approved By (Finance and Commercial)', 'sequence' => 5],
    ];

    /** The chain step whose actor is named on the completion line. */
    public const FLOW2_COMPLETION_SEQUENCE = 6;

    /**
     * The chain step that fills the printed "OCR Checked" box.
     *
     * Step 4 is the Accounts Team check. Nothing in the document previously tied
     * that box to any department — it was a blank for someone to tick by hand —
     * so this is a MEANING BEING ASSIGNED, not one being honoured. Recorded here
     * because the next person to read the form will reasonably assume the box
     * always meant Accounts, and it did not.
     */
    public const FLOW2_OCR_CHECK_SEQUENCE = 4;

    /**
     * Number of working days added to the apply/created date to reach the
     * Payment Require Date. Always at least 1.
     */
    /**
     * Whether new PRAs are created on the departmental chain.
     *
     * Unset falls back to PaymentRequestController::CREATE_ON_DEPARTMENTAL_FLOW,
     * so a database that has never seen this screen behaves exactly as it did
     * before the toggle existed — no migration, no backfill, no moment where the
     * answer is undefined.
     */
    public static function departmentalFlowEnabled(): bool
    {
        $stored = AppSetting::get(self::KEY_DEPARTMENTAL_FLOW);

        if ($stored === null || $stored === '') {
            return \App\Http\Controllers\SupplyChain\PaymentRequestController::CREATE_ON_DEPARTMENTAL_FLOW;
        }

        return (bool) ((int) $stored);
    }

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
    /**
     * Settings key for a departmental block's printed label.
     *
     * Its own prefix, never the officerKey() namespace, so the legacy titles and
     * these cannot collide or be edited by accident from the other screen.
     */
    public static function flow2LabelKey(string $slug): string
    {
        return "pra_flow2_label_{$slug}";
    }

    /**
     * The admin-configured label for a departmental block, or its default.
     *
     * A blank stored value falls back to the default rather than printing an
     * empty heading — a signature line with no title is worse than one an admin
     * meant to clear.
     */
    public static function flow2Label(string $slug): string
    {
        $default = self::FLOW2_BLOCKS[$slug]['label'] ?? '';
        $stored = trim((string) AppSetting::get(self::flow2LabelKey($slug), ''));

        return $stored !== '' ? $stored : $default;
    }

    /**
     * Every departmental label, keyed by slug — for the settings form.
     *
     * @return array<string, string>
     */
    public static function flow2Labels(): array
    {
        $labels = [];

        foreach (array_keys(self::FLOW2_BLOCKS) as $slug) {
            $labels[$slug] = self::flow2Label($slug);
        }

        return $labels;
    }

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
        // FIRST, BEFORE ANYTHING ELSE. A departmental PRA that nobody has acted
        // on yet has no approval rows, so it would otherwise fall into the
        // legacy static-officer branch below and print three fixed names that
        // have nothing to do with its chain.
        if ($pr->isDepartmentalFlow()) {
            return self::departmentalBlocks($pr, $embed);
        }

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
     * The four signature blocks of a departmental PRA.
     *
     * NOTHING IS FIXED HERE. No configured officer name, no configured officer
     * image — those belong to the legacy flow, where the same three people sign
     * every document. A departmental PRA is signed by whoever actually acted,
     * and until they act the block names who is expected and says plainly that
     * it is unsigned.
     *
     * Three states per block:
     *   signed     someone acted. Their name, THEIR OWN uploaded signature
     *              (snapshotted at the moment they signed, so a later change to
     *              their signature cannot rewrite a document already issued),
     *              and the date they acted.
     *   pending,   exactly one person can act at that step, so the document can
     *   named      say who it is waiting for. Marked "Pending approval" and given
     *              NO image — a name on a signature line that has not been signed
     *              must never read as an endorsement.
     *   pending,   several people could act (any one of a department's heads).
     *   unnamed    Guessing which would be a lie, so the block says only that it
     *              is pending.
     *
     * @return array<int, array<string, mixed>>
     */
    protected static function departmentalBlocks(\App\Models\PaymentRequest $pr, bool $embed): array
    {
        $decisions = app(\App\Services\PraWorkflow::class)
            ->currentCycleDecisions($pr)
            ->where('status', PraApproval::STATUS_APPROVED)
            ->keyBy('sequence');

        $blocks = [];

        foreach (self::FLOW2_BLOCKS as $slug => $definition) {
            $title = self::flow2Label($slug);

            // The creator's block. They authored the PRA rather than approving
            // it, so it is filled from the PRA itself, exactly as flow 1 does.
            if ($definition['sequence'] === null) {
                $blocks[] = self::dynamicBlock(
                    $slug, $title, $pr->createdBy, $pr->created_at, $pr->prepared_signature_path, $embed
                );

                continue;
            }

            $decision = $decisions->get($definition['sequence']);

            if ($decision) {
                $blocks[] = self::dynamicBlock(
                    $slug, $title, $decision->approver, $decision->acted_at, $decision->signature_path, $embed
                );

                continue;
            }

            // AN UNSAVED PRA (the create preview) NAMES NOBODY. Its items are
            // not written yet, so a buyer-scoped step would resolve against an
            // empty buyer list and could single out the wrong person. Pending,
            // unnamed, is the only honest answer before the PRA exists.
            $expected = $pr->exists ? self::expectedActor($pr, $definition['sequence']) : null;

            $blocks[] = self::pendingBlock($slug, $title, $expected);
        }

        return $blocks;
    }

    /**
     * The single person a step is waiting on, or null when more than one could
     * act (or nobody can).
     */
    protected static function expectedActor(\App\Models\PaymentRequest $pr, int $sequence): ?\App\Models\User
    {
        // Through the workflow, not the resolver, so a primary assignee is seen.
        // A named person is the one the document is waiting on; failing that,
        // the single eligible person if there is exactly one; otherwise nobody
        // can honestly be named. The block stays captioned "Pending approval"
        // in every case — a name here is never an endorsement.
        $actors = app(\App\Services\PraWorkflow::class)->stage($pr, $sequence);

        if ($actors->assignee) {
            return $actors->assignee;
        }

        return $actors->users->count() === 1 ? $actors->users->first() : null;
    }

    /**
     * A block awaiting its signature. Keeps the manual signing line, carries no
     * image, and is flagged so the templates can caption it "Pending approval".
     *
     * When one person is expected, their title goes with their name — the same
     * designation-or-department line a signed block prints — so the document
     * says "Merchandising Head" beneath the person it is waiting on, not a bare
     * name.
     *
     * @return array<string, mixed>
     */
    protected static function pendingBlock(string $officer, string $title, ?\App\Models\User $expected): array
    {
        return [
            'officer' => $officer,
            'title' => $title,
            'name' => $expected?->name ?? '',
            'designation' => $expected?->signatureDesignation() ?? '',
            'date' => '',
            'src' => null,
            'path' => null,
            'dynamic' => false,
            'pending' => true,
        ];
    }

    /**
     * Who completed (paid) a departmental PRA, for the single line printed under
     * the signature blocks. Null until step 6 is done, and null for flow 1.
     *
     * Deliberately NOT a signature block: block 4's label names Finance and
     * Commercial together, so a reader will ask where Commercial acted. One line
     * answers that without implying a signature nobody gave.
     *
     * @return array{name: string, date: string}|null
     */
    public static function completionLineFor(\App\Models\PaymentRequest $pr): ?array
    {
        return self::actorRecordAt($pr, self::FLOW2_COMPLETION_SEQUENCE);
    }

    /**
     * Who performed the Accounts Team check, and when, for the printed
     * "OCR Checked" box. Null until step 4 is done, and null for flow 1.
     *
     * NULL IS THE UNCHECKED BOX, not a "No". A departmental PRA that has not
     * reached step 4 prints exactly what a legacy one prints — two empty
     * checkboxes and two blank lines — because nobody has answered the question
     * yet. Ticking "No" would assert a failed check that never happened.
     *
     * @return array{name: string, date: string}|null
     */
    public static function ocrCheckFor(\App\Models\PaymentRequest $pr): ?array
    {
        return self::actorRecordAt($pr, self::FLOW2_OCR_CHECK_SEQUENCE);
    }

    /**
     * The name and date of whoever approved a given step of a departmental PRA.
     *
     * ONE LOOKUP, shared by the completion line, the OCR Checked box and
     * anything else that needs "who did step N" — the same current-cycle,
     * approved-only rule the signature blocks use. A second copy of this would
     * be a second chance to disagree about which cycle counts after a
     * resubmission.
     *
     * @return array{name: string, date: string}|null
     */
    protected static function actorRecordAt(\App\Models\PaymentRequest $pr, int $sequence): ?array
    {
        if (! $pr->isDepartmentalFlow()) {
            return null;
        }

        $decision = app(\App\Services\PraWorkflow::class)
            ->currentCycleDecisions($pr)
            ->firstWhere(fn (PraApproval $row) => $row->sequence === $sequence && $row->isApproved());

        if (! $decision) {
            return null;
        }

        return [
            'name' => optional($decision->approver)->name ?? '',
            'date' => $decision->acted_at
                ? \Illuminate\Support\Carbon::parse($decision->acted_at)->format('jS M-Y')
                : '',
        ];
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
            // The admin-set designation, falling back to the department (with
            // "Head" for a department admin) — see User::signatureDesignation().
            'designation' => $user ? $user->signatureDesignation() : '',
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
