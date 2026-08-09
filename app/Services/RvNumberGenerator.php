<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Allocates the RV No for a goods-receiving event: AUG26-00001.
 *
 * Three-letter month, two-digit year, five-digit sequence that restarts each
 * month — the period is in the key, so a sequence that carried across months
 * would only make the prefix decorative.
 *
 * The number cannot be guaranteed by a unique index on stock_purchases.rv_no,
 * because one receiving writes several rows that deliberately share its RV No.
 * Uniqueness comes from here instead: the period's counter row is locked for
 * the duration of the transaction, so two people saving at the same moment
 * queue rather than collide.
 *
 * Legacy rows carry hand-typed numbers such as "1245". Those are bare digits
 * and this format always begins with three letters, so old and new can never
 * collide and no historical row needed rewriting.
 */
class RvNumberGenerator
{
    /** Digits in the sequence part — 99,999 receivings in one month. */
    private const WIDTH = 5;

    /**
     * Take the next RV No for the month the goods were received in.
     *
     * MUST be called inside a transaction: the lock it takes is only held until
     * that transaction ends, and it is the lock that makes the number unique.
     */
    public function next(?Carbon $on = null): string
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException(
                'RvNumberGenerator::next() must run inside a transaction — the row lock that keeps RV numbers unique is released when the transaction ends.'
            );
        }

        $period = $this->period($on ?? Carbon::now());

        // Create the period's counter if this is its first receiving. Done
        // before the lock so the row always exists to be locked; a race here is
        // caught by the unique index on `period` and resolved by re-reading.
        if (! DB::table('stock_rv_sequences')->where('period', $period)->exists()) {
            try {
                DB::table('stock_rv_sequences')->insert([
                    'period' => $period,
                    'last_no' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (\Illuminate\Database\QueryException $e) {
                // Someone else created it first. That is the outcome we wanted.
            }
        }

        $row = DB::table('stock_rv_sequences')->where('period', $period)->lockForUpdate()->first();

        $next = (int) ($row->last_no ?? 0) + 1;

        DB::table('stock_rv_sequences')
            ->where('period', $period)
            ->update(['last_no' => $next, 'updated_at' => now()]);

        return $this->format($period, $next);
    }

    /**
     * What the next number WOULD be, without taking it.
     *
     * For showing the operator the RV No before they save. Deliberately not a
     * reservation — two people previewing at once see the same value, and the
     * one who saves second gets the next one. The form therefore presents it as
     * a preview, never as a committed number.
     */
    public function preview(?Carbon $on = null): string
    {
        $period = $this->period($on ?? Carbon::now());
        $last = (int) (DB::table('stock_rv_sequences')->where('period', $period)->value('last_no') ?? 0);

        return $this->format($period, $last + 1);
    }

    /** "AUG26" for August 2026. */
    public function period(Carbon $on): string
    {
        return strtoupper($on->format('M')).$on->format('y');
    }

    private function format(string $period, int $no): string
    {
        return $period.'-'.str_pad((string) $no, self::WIDTH, '0', STR_PAD_LEFT);
    }
}
