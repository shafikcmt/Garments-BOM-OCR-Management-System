<?php

namespace App\Services;

use App\Models\AppNotification;
use App\Models\AppSetting;
use App\Models\BomApproval;
use App\Models\BomApprovalStep;
use App\Models\BomItem;
use App\Models\ExcelRow;
use App\Models\MotherStyle;
use App\Models\OrderLine;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Approval of a style's BOM before it is ordered against.
 *
 *   merchant submits -> level 1 (Merchandising Head) -> [level 2 (Management)] -> approved
 *
 * Level 2 is switched on by an admin. A reviewer can return the BOM with a
 * reason; the merchant fixes it and submits again.
 *
 * WHAT "THE BOM" IS. A fingerprint over the style's order lines and material
 * lines (colour, customer PO, order qty, material, SAP code, colour, unit, BOM
 * qty, consumption, wastage). The fingerprint taken at submission is what is
 * reviewed and approved. If the BOM differs afterwards, the style is shown as
 * "Changed after approval" and must be submitted again; nobody can approve a BOM
 * that changed since it was submitted.
 *
 * NOTHING IS BLOCKED BY DEFAULT. Only when an admin switches on "require
 * approval before PO" does Supply Chain's PO generation refuse an unapproved
 * style. Rows with no style link (older files) are never blocked.
 */
class BomApprovals
{
    public const KEY_MANAGEMENT_STAGE = 'bom_approval_management_stage';

    public const KEY_REQUIRED_FOR_PO = 'bom_approval_required_for_po';

    public static function managementStage(): bool
    {
        return (bool) (int) AppSetting::get(self::KEY_MANAGEMENT_STAGE, 0);
    }

    public static function requiredForPo(): bool
    {
        return (bool) (int) AppSetting::get(self::KEY_REQUIRED_FOR_PO, 0);
    }

    public function maxLevel(): int
    {
        return self::managementStage() ? 2 : 1;
    }

    // ------------------------------------------------------------ fingerprint

    public function fingerprint(MotherStyle $mother): string
    {
        return $this->fingerprintsFor(collect([$mother]))[$mother->id];
    }

    /**
     * @param  Collection<int, MotherStyle>  $mothers
     * @return array<int, string>  style id => fingerprint
     */
    public function fingerprintsFor(Collection $mothers): array
    {
        $lines = OrderLine::whereIn('mother_style_id', $mothers->pluck('id'))->with('bomItems')->get()->groupBy('mother_style_id');

        return $mothers->mapWithKeys(function (MotherStyle $mother) use ($lines) {
            $rows = $lines->get($mother->id, collect())->flatMap(function (OrderLine $line) {
                return $line->bomItems->map(fn (BomItem $item) => [
                    $line->line_key, $this->num($line->order_qty),
                    $item->material_description, $item->sap_code, $item->material_color, $item->uom,
                    $this->num($item->bom_qty), $this->num($item->initial_consumption),
                    $this->num($item->wastage_percent), $this->num($item->consumption_incl_yy),
                ]);
            })->sort(fn ($a, $b) => strcmp(json_encode($a), json_encode($b)))->values()->all();

            return [$mother->id => md5(json_encode($rows))];
        })->all();
    }

    // ----------------------------------------------------------------- state

    /** not_submitted | pending | returned | approved | changed */
    public function state(MotherStyle $mother, ?BomApproval $approval = null, ?string $fingerprint = null): string
    {
        $approval ??= $mother->bomApproval;

        return match ($approval?->status) {
            null => 'not_submitted',
            BomApproval::PENDING => 'pending',
            BomApproval::RETURNED => 'returned',
            BomApproval::APPROVED => ($fingerprint ?? $this->fingerprint($mother)) === $approval->fingerprint ? 'approved' : 'changed',
            default => 'not_submitted',
        };
    }

    // ------------------------------------------------------------- who decides

    /**
     * @return Collection<int, User>
     */
    public function approversFor(BomApproval $approval): Collection
    {
        $mother = $approval->motherStyle;
        $role = $approval->level >= 2 ? 'management' : 'merchant';
        $roles = \Spatie\Permission\Models\Role::whereIn('name', [$role, 'admin'])->pluck('name')->all();

        if ($roles === []) {
            return collect();
        }

        return User::role($roles)->where('status', 1)->get()
            ->filter(function (User $user) use ($role, $mother) {
                if ($user->hasRole('admin')) {
                    return true;
                }

                // Merchandising level: department admins only. Management level: any Management user.
                return ($role === 'management' || $user->is_department_admin) && $user->hasBuyerAccess((int) $mother->buyer_id);
            })->values();
    }

    /**
     * May this person approve or return it now?
     *
     * Not the person who submitted it — unless nobody else could, so a one-person
     * team is not locked out.
     */
    public function canDecide(User $user, BomApproval $approval): bool
    {
        if ($approval->status !== BomApproval::PENDING) {
            return false;
        }

        $approvers = $this->approversFor($approval);

        if (! $approvers->contains('id', $user->id)) {
            return false;
        }

        return $user->id !== $approval->submitted_by || $approvers->where('id', '!=', $approval->submitted_by)->isEmpty();
    }

    /**
     * @return Collection<int, BomApproval>
     */
    public function waitingFor(User $user): Collection
    {
        return BomApproval::where('status', BomApproval::PENDING)->with(['motherStyle.buyer', 'motherStyle.season', 'submitter:id,name'])
            ->orderBy('submitted_at')->get()
            ->filter(fn (BomApproval $a) => $a->motherStyle && $this->canDecide($user, $a))->values();
    }

    // ----------------------------------------------------------------- actions

    /**
     * @throws \DomainException
     */
    public function submit(MotherStyle $mother, User $by): BomApproval
    {
        if (! BomItem::whereHas('orderLine', fn ($q) => $q->where('mother_style_id', $mother->id))->exists()) {
            throw new \DomainException('This style has no BOM yet. Import the buyer\'s Excel first.');
        }

        $current = $mother->bomApproval;
        $state = $this->state($mother, $current);

        if ($state === 'pending') {
            throw new \DomainException('This BOM is already waiting for approval.');
        }

        if ($state === 'approved') {
            throw new \DomainException('This BOM is already approved and has not changed.');
        }

        $approval = DB::transaction(function () use ($mother, $by, $current) {
            $approval = $current ?? new BomApproval(['mother_style_id' => $mother->id]);

            $approval->fill([
                'status' => BomApproval::PENDING,
                'level' => 1,
                'cycle' => $current ? $current->cycle + 1 : 1,
                'fingerprint' => $this->fingerprint($mother),
                'submitted_by' => $by->id,
                'submitted_at' => now(),
                'approved_at' => null,
                'changed_notified_at' => null,
            ])->save();

            $this->log($approval, 'submitted', $by, null);

            return $approval;
        });

        $this->notifyMany($this->approversFor($approval->load('motherStyle'))->reject(fn ($u) => $u->id === $by->id),
            $by, $mother, 'bom_approval_request', 'BOM waiting for approval',
            $by->name . ' submitted the BOM of style ' . $mother->style_no . ' for your approval.');

        return $approval;
    }

    /**
     * @throws \DomainException
     */
    public function approve(BomApproval $approval, User $by, ?string $comment): BomApproval
    {
        $this->assertCanDecide($by, $approval);
        $mother = $approval->motherStyle;

        if ($this->fingerprint($mother) !== $approval->fingerprint) {
            throw new \DomainException('The BOM changed after it was submitted. Return it so the merchant can submit it again.');
        }

        DB::transaction(function () use ($approval, $by, $comment) {
            $this->log($approval, 'approved', $by, $comment);

            if ($approval->level < $this->maxLevel()) {
                $approval->update(['level' => $approval->level + 1]);
            } else {
                $approval->update(['status' => BomApproval::APPROVED, 'approved_at' => now()]);
            }
        });

        $approval->refresh()->load('motherStyle');

        if ($approval->status === BomApproval::PENDING) {
            $this->notifyMany($this->approversFor($approval)->reject(fn ($u) => $u->id === $by->id), $by, $mother,
                'bom_approval_request', 'BOM waiting for approval',
                'The BOM of style ' . $mother->style_no . ' passed ' . BomApproval::LEVEL_LABELS[$approval->level - 1] . ' and is waiting for you.');
        } else {
            $this->notifyApproved($approval, $by);
        }

        return $approval;
    }

    /**
     * @throws \DomainException
     */
    public function return(BomApproval $approval, User $by, string $comment): BomApproval
    {
        $this->assertCanDecide($by, $approval);

        DB::transaction(function () use ($approval, $by, $comment) {
            $this->log($approval, 'returned', $by, $comment);
            $approval->update(['status' => BomApproval::RETURNED]);
        });

        $mother = $approval->motherStyle;

        if ($approval->submitter && $approval->submitter->id !== $by->id) {
            $this->notifyMany(collect([$approval->submitter]), $by, $mother, 'bom_returned', 'BOM returned',
                $by->name . ' returned the BOM of style ' . $mother->style_no . ': ' . $comment);
        }

        return $approval->refresh();
    }

    /**
     * After workspace rows changed: tell people when an APPROVED BOM no longer
     * matches what was approved. Once per change, until it is submitted again.
     *
     * @param  list<int>  $rowIds
     */
    public function afterRowsChanged(array $rowIds): void
    {
        $motherIds = OrderLine::whereIn('id', BomItem::whereIn('excel_row_id', $rowIds)->pluck('order_line_id'))
            ->pluck('mother_style_id')->filter()->unique();

        BomApproval::whereIn('mother_style_id', $motherIds)->where('status', BomApproval::APPROVED)
            ->whereNull('changed_notified_at')->with('motherStyle')->get()
            ->each(function (BomApproval $approval) {
                $mother = $approval->motherStyle;

                if (! $mother || $this->state($mother, $approval) !== 'changed') {
                    return;
                }

                $approval->update(['changed_notified_at' => now()]);

                $people = $this->approversFor((clone $approval)->setAttribute('level', 1))->push($approval->submitter)->filter()->unique('id');
                $this->notifyMany($people, null, $mother, 'bom_changed', 'BOM changed after approval',
                    'The BOM of style ' . $mother->style_no . ' changed after it was approved. It must be submitted for approval again.');
            });
    }

    // -------------------------------------------------------------- PO gate

    /**
     * Why PO generation must stop for this worksheet row, or null to let it go.
     */
    public function blockReason(ExcelRow $row): ?string
    {
        if (! self::requiredForPo()) {
            return null;
        }

        $line = OrderLine::whereHas('bomItems', fn ($q) => $q->where('excel_row_id', $row->id))->first();

        // No style link (an older file): nothing to approve, so nothing to block.
        if (! $line || ! $line->mother_style_id) {
            return null;
        }

        $mother = MotherStyle::find($line->mother_style_id);

        return $this->state($mother) === 'approved'
            ? null
            : 'The BOM of style ' . $mother->style_no . ' is not approved. Ask Merchandising to submit it for approval before ordering.';
    }

    // ----------------------------------------------------------------- private

    private function assertCanDecide(User $by, BomApproval $approval): void
    {
        if (! $this->canDecide($by, $approval)) {
            throw new \DomainException('You cannot decide on this BOM.');
        }
    }

    private function log(BomApproval $approval, string $action, User $by, ?string $comment): void
    {
        BomApprovalStep::create([
            'bom_approval_id' => $approval->id, 'cycle' => $approval->cycle, 'level' => $approval->level,
            'action' => $action, 'user_id' => $by->id, 'comment' => $comment, 'created_at' => now(),
        ]);
    }

    private function notifyApproved(BomApproval $approval, User $by): void
    {
        $mother = $approval->motherStyle;
        $roles = \Spatie\Permission\Models\Role::where('name', 'supply_chain')->pluck('name')->all();
        $supply = $roles === [] ? collect() : User::role($roles)->where('status', 1)->get()
            ->filter(fn (User $u) => $u->hasBuyerAccess((int) $mother->buyer_id));

        $people = $supply->push($approval->submitter)->filter()->reject(fn ($u) => $u->id === $by->id)->unique('id');

        $this->notifyMany($people, $by, $mother, 'bom_approved', 'BOM approved',
            'The BOM of style ' . $mother->style_no . ' is approved and ready to order.');
    }

    /**
     * @param  Collection<int, User>  $people
     */
    private function notifyMany(Collection $people, ?User $actor, MotherStyle $mother, string $type, string $title, string $message): void
    {
        foreach ($people as $person) {
            AppNotification::create([
                'user_id' => $person->id, 'actor_id' => $actor?->id, 'type' => $type,
                'title' => $title, 'message' => $message, 'url' => route('merchant.status.style', $mother),
            ]);
        }
    }

    private function num($value): string
    {
        return $value === null ? '' : rtrim(rtrim(number_format((float) $value, 6, '.', ''), '0'), '.');
    }
}
