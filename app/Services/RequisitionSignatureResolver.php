<?php

namespace App\Services;

use App\Models\PurchaseRequisition;
use App\Models\RequisitionDepartmentHead;
use App\Models\RequisitionSignatureBlock;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Turns the configured signature blocks into what a requisition document
 * prints: for each box, its label, the name under the line, the designation,
 * and the date the system knows it was signed — or nothing, when it is
 * signed on paper.
 *
 * The ONE place the PDF, the Excel sheet, the screen and the monthly report
 * read signatures from, so the four can never disagree.
 *
 * Each resolved box is a plain array:
 *
 *   label      the caption over the box                  "Checked by"
 *   name       who signs, or '' when nobody is named     "Rahim Uddin"
 *   title      the designation under the name            "Store Head"
 *   signed_at  Carbon|null — set only when a system action stamped it
 *   stamped    whether this box is filled by a system action at all
 */
class RequisitionSignatureResolver
{
    /**
     * The boxes for one requisition, in print order.
     *
     * @return array<int, array{label: string, name: string, title: string, signed_at: ?Carbon, stamped: bool}>
     */
    public function forRequisition(PurchaseRequisition $requisition): array
    {
        $level = $requisition->level ?: PurchaseRequisition::LEVEL_FINAL;

        return $this->blocks($level)
            ->map(fn (RequisitionSignatureBlock $block) => $this->resolve($block, $requisition))
            ->all();
    }

    /**
     * The boxes for a document that is not one requisition — the monthly
     * report — where there is nobody's action to read a date from, only the
     * configured names.
     *
     * @return array<int, array{label: string, name: string, title: string, signed_at: ?Carbon, stamped: bool}>
     */
    public function forLevel(string $level): array
    {
        return $this->blocks($level)
            ->map(fn (RequisitionSignatureBlock $block) => $this->resolve($block, null))
            ->all();
    }

    /** @return \Illuminate\Support\Collection<int, RequisitionSignatureBlock> */
    private function blocks(string $level)
    {
        return RequisitionSignatureBlock::query()->forLevel($level)->with('user')->get();
    }

    /** @return array{label: string, name: string, title: string, signed_at: ?Carbon, stamped: bool} */
    private function resolve(RequisitionSignatureBlock $block, ?PurchaseRequisition $requisition): array
    {
        // Who actually signed, if the system recorded it. Wins over the
        // configured person: the sheet must name whoever really acted.
        [$signer, $signedAt] = $requisition
            ? $this->stamp($block->stamp_source, $requisition)
            : [null, null];

        $configured = $this->configuredSigner($block, $requisition);
        $person = $signer ?? $configured;

        return [
            'label' => $block->label,
            'name' => $person?->name ?? '',
            'title' => $this->title($block, $person, $requisition),
            'signed_at' => $signedAt,
            'stamped' => $block->stamp_source !== null && $block->stamp_source !== '',
        ];
    }

    /**
     * The person the configuration points at, before anyone has acted.
     * A role names no single person, so it resolves to nobody — the role's
     * label is printed as the designation instead.
     */
    private function configuredSigner(RequisitionSignatureBlock $block, ?PurchaseRequisition $requisition): ?User
    {
        return match ($block->assignee_type) {
            RequisitionSignatureBlock::ASSIGNEE_USER => $block->user,
            RequisitionSignatureBlock::ASSIGNEE_REQUESTER => $requisition?->createdBy,
            RequisitionSignatureBlock::ASSIGNEE_DEPARTMENT_HEAD => $this->headFor($requisition),
            default => null,
        };
    }

    /**
     * The head a "Requester's Department Head" box names before anyone has
     * signed: the first head in the sheet's chain — its section head when
     * the section has one, else its department head — so the box shows the
     * person the sheet is actually going to. With no sheet (the settings
     * preview) there is nobody to name.
     */
    private function headFor(?PurchaseRequisition $requisition): ?User
    {
        if (! $requisition) {
            return null;
        }

        $first = RequisitionReviewChain::stepsForRequisition($requisition)[0] ?? null;

        return $first['head'] ?? RequisitionDepartmentHead::headOf($requisition->department_role);
    }

    /**
     * The designation line. The admin's title wins when set; otherwise the
     * person's own designation, then the role's label, so the box is never
     * blank under the line.
     */
    private function title(RequisitionSignatureBlock $block, ?User $person, ?PurchaseRequisition $requisition): string
    {
        if (trim((string) $block->title) !== '') {
            return trim((string) $block->title);
        }

        // `designation` is an optional column on users; reading a missing
        // attribute yields null, so this is safe on a database without it.
        $designation = trim((string) ($person?->designation ?? ''));
        if ($designation !== '') {
            return $designation;
        }

        return match ($block->assignee_type) {
            RequisitionSignatureBlock::ASSIGNEE_ROLE => self::roleLabel($block->role_name),
            // "Cutting Section Head" while a section holds the sheet, else
            // "Production Head" — the level the sheet is at, or would go to.
            RequisitionSignatureBlock::ASSIGNEE_DEPARTMENT_HEAD => $this->headTitle($requisition),
            RequisitionSignatureBlock::ASSIGNEE_REQUESTER => RequisitionDepartmentHead::departmentLabel($requisition?->department_role),
            default => '',
        };
    }

    private function headTitle(?PurchaseRequisition $requisition): string
    {
        if (! $requisition) {
            return 'Department Head';
        }

        $level = $requisition->review_level
            ?: (RequisitionReviewChain::stepsForRequisition($requisition)[0]['level'] ?? null);

        if ($level === RequisitionReviewChain::LEVEL_SECTION && $requisition->indentSection) {
            return $requisition->indentSection->name.' Section Head';
        }

        return RequisitionDepartmentHead::departmentLabel($requisition->department_role).' Head';
    }

    /**
     * The signer and date a system action stamped for this source.
     *
     * "Department Head — Approve" is the ONE head check the chain makes,
     * whichever level made it, so it reads the section head's stamp on a
     * sheet checked at section level.
     *
     * @return array{0: ?User, 1: ?Carbon}
     */
    private function stamp(?string $source, PurchaseRequisition $requisition): array
    {
        return match ($source) {
            RequisitionSignatureBlock::STAMP_PREPARED => [$requisition->createdBy, $requisition->submitted_at],
            RequisitionSignatureBlock::STAMP_DEPARTMENT_HEAD => $requisition->department_review_status === PurchaseRequisition::DEPT_REVIEW_APPROVED
                ? [$requisition->headCheckedBy(), $requisition->headCheckedAt()]
                : [null, null],
            RequisitionSignatureBlock::STAMP_STORE_HEAD => [$requisition->storeAckBy, $requisition->store_ack_at],
            RequisitionSignatureBlock::STAMP_APPROVED => [$requisition->approvedBy, $requisition->approved_at],
            default => [null, null],
        };
    }

    /** @return array<string, string> role => label, for the settings screen and the printed designation */
    public static function roleLabels(): array
    {
        return RequisitionDepartmentHead::departmentOptions() + [
            'management' => 'Management',
            'admin' => 'Admin',
        ];
    }

    public static function roleLabel(?string $role): string
    {
        return self::roleLabels()[$role] ?? ucwords(str_replace('_', ' ', (string) $role));
    }
}
