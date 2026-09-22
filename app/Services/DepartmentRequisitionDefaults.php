<?php

namespace App\Services;

use App\Models\IndentSection;
use App\Models\PurchaseRequisition;
use App\Models\RequisitionDepartmentHead;
use App\Models\User;

/**
 * What a DEPARTMENT-level requisition form already knows before anyone
 * types: whose section it is, who heads the department, and therefore what
 * the Section, Requested By, Contact and User Dept. fields must say.
 *
 * Resolved once per request and handed to the form, the number preview and
 * the save, so the screen and the server agree on every locked value — the
 * server never trusts the posted copy of a field this fixes.
 *
 * For a FINAL-level form (Store / Admin / Management) every method here
 * answers "nothing fixed", and the form behaves exactly as it always has.
 *
 * Built from the same two mappings the workflow already uses — the user's
 * requisition section (Admin > Users) and RequisitionDepartmentHead — so
 * there is no second idea of who a department head is.
 */
final class DepartmentRequisitionDefaults
{
    private function __construct(
        public readonly string $level,
        public readonly ?string $department,
        public readonly ?IndentSection $section,
        public readonly ?User $head,
    ) {
    }

    /**
     * For the signed-in user raising a NEW requisition, or — when editing —
     * for the requisition as it was raised. An existing sheet keeps its own
     * level and department; the section and head are read fresh, so a
     * section set on the user after the draft was saved is picked up.
     */
    public static function for(?User $user, ?PurchaseRequisition $existing = null): self
    {
        $level = $existing?->level ?: PurchaseRequisition::levelFor($user);

        if ($level !== PurchaseRequisition::LEVEL_DEPARTMENT) {
            return new self($level, null, null, null);
        }

        $department = $existing?->department_role ?: RequisitionDepartmentHead::departmentOf($user);

        // The requester's section, not the editor's: a Store correction of
        // a Cutting sheet must not re-file it under the Store user's section.
        $owner = $existing?->createdBy ?: $user;
        $section = $owner?->requisitionSection ?: $existing?->indentSection;

        // Requested By is the head the sheet goes to: the section head when
        // the section has one, else the department head — the first step of
        // the same chain that routes the sheet, so form, queue and signature
        // box all name the same person. When the chain has no step (the
        // department head's own sheet, or no heads at all) the department
        // head is still the one the form names, as it always was.
        $head = RequisitionReviewChain::stepsFor($owner, $section, $department)[0]['head']
            ?? RequisitionDepartmentHead::headOf($department);

        return new self($level, $department, $section, $head);
    }

    public function isDepartment(): bool
    {
        return $this->level === PurchaseRequisition::LEVEL_DEPARTMENT;
    }

    /** Whether the Section field is fixed on this form. */
    public function fixesSection(): bool
    {
        return $this->isDepartment() && $this->section !== null;
    }

    /** Whether Requested By and Contact are fixed to a head. */
    public function fixesHead(): bool
    {
        return $this->isDepartment() && $this->head !== null;
    }

    /** "Cutting section head" / "Production department head" — who Requested By is. */
    public function headTitle(): string
    {
        if ($this->head && $this->section && (int) $this->section->head_user_id === $this->head->id) {
            return $this->section->name.' section head';
        }

        return RequisitionDepartmentHead::departmentLabel($this->department).' department head';
    }

    /**
     * The section name the requisition number is built from. The user's
     * section when set; failing that the department's label, so a
     * Merchandising user with no section still gets HAPL/MERCHANDISING/…
     * rather than the Store's ALL. Null for a final-level form, whose number
     * follows whatever section the Store picks.
     */
    public function numberSection(): ?string
    {
        if (! $this->isDepartment()) {
            return null;
        }

        return $this->section?->name
            ?: ($this->department ? RequisitionDepartmentHead::departmentLabel($this->department) : null);
    }

    /** The value locked into every line's User Dept. on a department form. */
    public function userDept(): ?string
    {
        return $this->numberSection();
    }

    /** The head's contact — users carry an email and nothing else. */
    public function contact(): ?string
    {
        return $this->head?->email;
    }
}
