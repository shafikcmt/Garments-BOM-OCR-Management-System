<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Allocates the Requisition No: HAPL/PRODUCTION/2026/August/01.
 *
 * The format is the one the store already used on paper (reference workbook
 * "HAPL/Dept./ALL/2026/August/01"), so a number in the system reads the same as
 * a number in the old file and the two can be reconciled during changeover.
 *
 * The serial restarts per SECTION per MONTH, which is why the counter's period
 * key carries both. Same locking pattern as RvNumberGenerator: the period row
 * is locked for the length of the transaction, so simultaneous saves queue
 * instead of colliding.
 *
 * Unlike RV No, purchase_requisitions.requisition_no also carries a unique
 * index — one requisition is exactly one document, so the database can back the
 * allocator up. The field stays editable on the form for numbers copied from an
 * existing paper document; the unique index is what stops a duplicate.
 */
class PurchaseRequisitionNumberGenerator
{
    /** Digits in the serial part — 99 requisitions per section per month. */
    private const WIDTH = 2;

    /** Company prefix, matching the reference workbook. */
    private const PREFIX = 'HAPL';

    /** Used when no section is chosen, mirroring the workbook's "ALL" sheet. */
    private const NO_SECTION = 'ALL';

    /**
     * Take the next Requisition No for this section and month.
     *
     * MUST be called inside a transaction: the row lock that makes the number
     * unique is only held until that transaction ends.
     */
    public function next(?string $section, ?Carbon $on = null): string
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException(
                'PurchaseRequisitionNumberGenerator::next() must run inside a transaction — the row lock that keeps requisition numbers unique is released when the transaction ends.'
            );
        }

        $on = $on ?? Carbon::now();
        $period = $this->period($section, $on);

        // Create the counter if this is the section's first requisition of the
        // month. Done before the lock so there is always a row to lock; a race
        // here is caught by the unique index on `period` and resolved by the
        // re-read below.
        if (! DB::table('purchase_requisition_sequences')->where('period', $period)->exists()) {
            try {
                DB::table('purchase_requisition_sequences')->insert([
                    'period' => $period,
                    'last_no' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (\Illuminate\Database\QueryException $e) {
                // Someone else created it first. That is the outcome we wanted.
            }
        }

        $row = DB::table('purchase_requisition_sequences')->where('period', $period)->lockForUpdate()->first();

        $next = (int) ($row->last_no ?? 0);
        $number = null;

        // Skip any number already on a requisition. The field is editable, so a
        // document entered with a hand-typed number ("…/August/05") occupies a
        // serial the counter has not reached yet; without this the allocator
        // would eventually hand out that number again and the save would fail
        // on the unique index instead of simply taking the next free one.
        //
        // Bounded so a mistake can never spin forever — WIDTH digits is the
        // most serials this period can hold.
        $ceiling = (10 ** self::WIDTH) - 1;

        do {
            $next++;
            $candidate = $this->format($section, $on, $next);

            if (! DB::table('purchase_requisitions')->where('requisition_no', $candidate)->exists()) {
                $number = $candidate;
            }
        } while ($number === null && $next < $ceiling);

        if ($number === null) {
            throw new \RuntimeException(
                'No free requisition number left for '.$period.' — every serial up to '.$ceiling.' is already used.'
            );
        }

        DB::table('purchase_requisition_sequences')
            ->where('period', $period)
            ->update(['last_no' => $next, 'updated_at' => now()]);

        return $number;
    }

    /**
     * What the next number WOULD be, without taking it.
     *
     * For showing the operator a number before they save. Deliberately not a
     * reservation: two people previewing at once see the same value and the one
     * who saves second gets the next one, so the form labels it as a preview.
     */
    public function preview(?string $section, ?Carbon $on = null): string
    {
        $on = $on ?? Carbon::now();
        $next = (int) (DB::table('purchase_requisition_sequences')
            ->where('period', $this->period($section, $on))
            ->value('last_no') ?? 0);

        // Skips taken numbers exactly as next() does, so the operator is shown
        // the number they will actually get.
        $ceiling = (10 ** self::WIDTH) - 1;

        do {
            $next++;
            $candidate = $this->format($section, $on, $next);
        } while ($next < $ceiling
            && DB::table('purchase_requisitions')->where('requisition_no', $candidate)->exists());

        return $candidate;
    }

    /** "2026-08|PRODUCTION" — the counter key. */
    public function period(?string $section, Carbon $on): string
    {
        return $on->format('Y-m').'|'.$this->sectionKey($section);
    }

    private function format(?string $section, Carbon $on, int $no): string
    {
        return implode('/', [
            self::PREFIX,
            $this->sectionKey($section),
            $on->format('Y'),
            $on->format('F'),
            str_pad((string) $no, self::WIDTH, '0', STR_PAD_LEFT),
        ]);
    }

    /**
     * Section names are free text from the master list ("Cutting & Sewing"),
     * but they end up inside a slash-separated number, so they are upper-cased
     * and stripped of anything that would make the number ambiguous to read.
     */
    private function sectionKey(?string $section): string
    {
        $key = strtoupper(Str::slug((string) $section, ''));

        return $key !== '' ? $key : self::NO_SECTION;
    }
}
