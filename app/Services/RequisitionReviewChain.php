<?php

namespace App\Services;

use App\Models\Department;
use App\Models\IndentSection;
use App\Models\PurchaseRequisition;
use App\Models\User;
use App\Support\DepartmentRoles;

/**
 * Who checks a department requisition before the Store, and in what order.
 *
 * The chain for a sheet is, top to bottom:
 *
 *   SECTION head      if the requester's section has one, and it is not
 *                     the requester themself
 *   DEPARTMENT head   if the requester's department has one, and it is
 *                     not the requester themself
 *   Store             always
 *
 * ONE CHECK, BY THE NEAREST HEAD. The first step that applies is the only
 * one the sheet stops at; after that head approves it goes to the Store.
 * A section head's own sheet therefore skips the section step and stops
 * at the department head; a department head's own sheet skips both and
 * goes straight to the Store; a department with nothing configured
 * behaves exactly as before. Which head is "the same person" is decided
 * by comparing users, never by naming anyone, so it stays right when
 * heads change.
 *
 * A sheet sent back returns to the SAME level on resubmit — unless that
 * step no longer applies (the head was removed, or is now the requester),
 * in which case the next step that does apply takes it, or the Store.
 *
 * The whole chain is still computed (not just the first step) so that
 * resubmit can find "the next step after the one that rejected", and so
 * a two-check mode can be switched on later without changing the data.
 *
 * Admin and Management stand in for any head; that is decided where the
 * decision is authorized (PurchaseRequisition::isReviewerFor), not here.
 */
class RequisitionReviewChain
{
    public const LEVEL_SECTION = 'section';
    public const LEVEL_DEPARTMENT = 'department';

    /** @return array<string, string> level => the title printed and spoken */
    public static function levelLabels(): array
    {
        return [
            self::LEVEL_SECTION => 'Section Head',
            self::LEVEL_DEPARTMENT => 'Department Head',
        ];
    }

    public static function levelLabel(?string $level): string
    {
        return self::levelLabels()[$level] ?? 'Head';
    }

    /**
     * The steps that apply for this requester, in order.
     *
     * @return list<array{level: string, head: User}>
     */
    public static function stepsFor(?User $requester, ?IndentSection $section, ?string $departmentKey): array
    {
        if (! $requester) {
            return [];
        }

        $steps = [];

        $sectionHead = $section?->head;
        if ($sectionHead && $sectionHead->id !== $requester->id) {
            $steps[] = ['level' => self::LEVEL_SECTION, 'head' => $sectionHead];
        }

        $departmentHead = $departmentKey
            ? Department::query()->where('key', $departmentKey)->where('is_active', true)->first()?->head
            : null;
        if ($departmentHead && $departmentHead->id !== $requester->id) {
            $steps[] = ['level' => self::LEVEL_DEPARTMENT, 'head' => $departmentHead];
        }

        return $steps;
    }

    /**
     * The steps for an existing sheet: its own section (fixed at save) and
     * department, for the person who raised it.
     *
     * @return list<array{level: string, head: User}>
     */
    public static function stepsForRequisition(PurchaseRequisition $requisition): array
    {
        if (! $requisition->isDepartmentLevel()) {
            return [];
        }

        $requester = $requisition->createdBy;
        $section = $requisition->indentSection ?: $requester?->requisitionSection;

        return self::stepsFor($requester, $section, $requisition->department_role);
    }

    /**
     * The department key a user raises requisitions for: the department
     * of the first of their roles that belongs to one. Null for a role
     * mapped nowhere (requisition_raiser) — such a sheet has no head step.
     */
    public static function departmentOf(?User $user): ?string
    {
        if (! $user) {
            return null;
        }

        foreach ($user->getRoleNames() as $role) {
            $key = DepartmentRoles::departmentOf($role);

            if ($key !== null) {
                return $key;
            }
        }

        return null;
    }

    /**
     * What a NEW submission is set to: the first applicable step, or
     * nothing — which is "Sent to Store".
     *
     * @return array{department_review_status: ?string, review_level: ?string}
     */
    public static function onSubmit(?User $requester, ?IndentSection $section, ?string $departmentKey): array
    {
        $first = self::stepsFor($requester, $section, $departmentKey)[0] ?? null;

        return [
            'department_review_status' => $first ? PurchaseRequisition::DEPT_REVIEW_PENDING : null,
            'review_level' => $first['level'] ?? null,
        ];
    }

    /**
     * What a RESUBMISSION is set to: the level that sent it back, if that
     * step still applies; else the next one that does; else the Store.
     *
     * @return array{department_review_status: ?string, review_level: ?string}
     */
    public static function onResubmit(PurchaseRequisition $requisition): array
    {
        $steps = self::stepsForRequisition($requisition);
        $rejectedAt = $requisition->review_level;

        $order = array_keys(self::levelLabels());
        $rejectedRank = $rejectedAt === null ? -1 : (int) array_search($rejectedAt, $order, true);

        // The rejecting level itself, or failing that the first step that
        // comes after it in chain order. With no rejecting level (a draft
        // being submitted through update()) that is simply the first step.
        $target = collect($steps)->first(fn ($s) => $s['level'] === $rejectedAt)
            ?? collect($steps)->first(fn ($s) => array_search($s['level'], $order, true) > $rejectedRank);

        return [
            'department_review_status' => $target ? PurchaseRequisition::DEPT_REVIEW_PENDING : null,
            'review_level' => $target['level'] ?? null,
        ];
    }
}
