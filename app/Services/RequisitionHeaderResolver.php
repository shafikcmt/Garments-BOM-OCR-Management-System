<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\Department;
use App\Models\PurchaseRequisition;
use App\Models\User;

/**
 * The header block of a requisition document — Name of Unit, Section, Name
 * of User, Contact No. / Email, and the Store Personnel box.
 *
 * SNAPSHOT. The values are resolved and saved on the requisition at every
 * submit and resubmit (header_snapshot), and the documents print the saved
 * copy — so a later change to someone's phone, designation or job never
 * rewrites a document already sent. A Store correction does not touch it,
 * and nothing writes it once the sheet is approved: the snapshot of the
 * last submit is the one that was approved.
 *
 * OLD SHEETS (no snapshot) print exactly what they printed before: their
 * own saved columns. Only the Store Personnel box, which was never saved,
 * comes from current data.
 */
class RequisitionHeaderResolver
{
    public const SETTING_UNIT_NAME = 'requisition_unit_name';
    public const SETTING_STORE_PERSONNEL = 'requisition_store_personnel_user_id';
    public const SETTING_CONTACT_PERSON = 'requisition_contact_person_user_id';

    /** Statuses after which the snapshot never changes again. */
    private const FROZEN = [PurchaseRequisition::STATUS_APPROVED, PurchaseRequisition::STATUS_PURCHASE_ACTION_TAKEN];

    public static function unitName(): string
    {
        return trim((string) AppSetting::get(self::SETTING_UNIT_NAME, '')) ?: (string) config('stock.company_name');
    }

    /**
     * What a document prints. Each value is the raw value; the templates
     * apply the same empty-value fallbacks they always have.
     *
     * @return array{unit_name: ?string, section: ?string, user_name: ?string, contact: ?string, store_personnel: array<string, ?string>, snapshot: bool}
     */
    public function forDocument(PurchaseRequisition $requisition, ?string $departmentHeadName = null): array
    {
        $snapshot = $requisition->header_snapshot;

        if (is_array($snapshot) && $snapshot !== []) {
            return [
                'unit_name' => $snapshot['unit_name'] ?? null,
                'section' => $snapshot['section'] ?? null,
                'user_name' => $snapshot['user_name'] ?? null,
                'contact' => $snapshot['contact'] ?? null,
                'store_personnel' => ($snapshot['store_personnel'] ?? []) + self::blankPerson(),
                'snapshot' => true,
            ];
        }

        // Exactly the sources the documents read before snapshots existed.
        return [
            'unit_name' => $requisition->unit_name,
            'section' => $requisition->indentSection?->name,
            'user_name' => $requisition->indentPerson?->name ?: $departmentHeadName,
            'contact' => $requisition->contact,
            'store_personnel' => $this->storePersonnel(),
            'snapshot' => false,
        ];
    }

    /**
     * Resolve and save the snapshot. Called inside the (re)submit
     * transaction. Refuses on an approved sheet — the snapshot is frozen.
     */
    public function snapshot(PurchaseRequisition $requisition): void
    {
        if (in_array($requisition->status, self::FROZEN, true)) {
            return;
        }

        $requisition->forceFill([
            'header_snapshot' => $this->resolve($requisition),
            'header_snapshot_at' => now(),
        ])->saveQuietly();
    }

    /**
     * Today's values for this sheet.
     *
     * @return array<string, mixed>
     */
    public function resolve(PurchaseRequisition $requisition): array
    {
        // load(), not loadMissing(): an edit may just have changed the section.
        $requisition->load('indentSection', 'createdBy');
        $raiser = $requisition->createdBy;

        if ($requisition->isDepartmentLevel()) {
            $section = $requisition->indentSection?->name;
            $userName = $raiser?->name;
            $contact = self::contactOf($raiser);
        } else {
            $section = 'ALL';
            $userName = 'ALL';
            $person = self::userSetting(self::SETTING_CONTACT_PERSON) ?? $raiser;
            $contact = self::contactOf($person);
        }

        return [
            'v' => 1,
            'unit_name' => self::unitName(),
            'section' => $section,
            'user_name' => $userName,
            'contact' => $contact,
            'store_personnel' => $this->storePersonnel(),
        ];
    }

    /**
     * The Store Personnel box: the user chosen in settings, else the Store
     * department head. Empty fields stay null for the template's dash.
     *
     * @return array{name: ?string, designation: ?string, department: ?string, phone: ?string, email: ?string}
     */
    public function storePersonnel(): array
    {
        $user = self::userSetting(self::SETTING_STORE_PERSONNEL)
            ?? User::find(Department::where('key', 'store')->value('head_user_id'));

        if (! $user) {
            return self::blankPerson();
        }

        $departmentKey = $user->departmentKey();

        return [
            'name' => $user->name ?: null,
            'designation' => $user->designation ?: null,
            'department' => ($departmentKey ? Department::where('key', $departmentKey)->value('label') : null)
                ?: ($user->departmentLabel() ?: null),
            'phone' => $user->phone ?: null,
            'email' => $user->email ?: null,
        ];
    }

    /** "01711-000000 / name@…", either half alone, or null. */
    private static function contactOf(?User $user): ?string
    {
        if (! $user) {
            return null;
        }

        return implode(' / ', array_filter([trim((string) $user->phone), trim((string) $user->email)])) ?: null;
    }

    private static function userSetting(string $key): ?User
    {
        $id = (int) AppSetting::get($key, 0);

        return $id > 0 ? User::find($id) : null;
    }

    /** @return array{name: null, designation: null, department: null, phone: null, email: null} */
    private static function blankPerson(): array
    {
        return ['name' => null, 'designation' => null, 'department' => null, 'phone' => null, 'email' => null];
    }
}
