<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Allocates the CS and Internal PO numbers for General Stock purchasing:
 *
 *   CS-26-27/0001           comparative statement
 *   PO-26-27/T-080         internal PO (T = unit code, config/purchasing.php)
 *
 * The sequence restarts every financial year. Same locking idea as
 * RvNumberGenerator: the period's counter row is locked for the length of the
 * caller's transaction, so two people saving at once queue instead of colliding.
 */
class GsDocumentNumberGenerator
{
    public const CS = 'cs';
    public const PO = 'po';

    /** MUST be called inside a transaction. */
    public function nextCs(?Carbon $on = null): string
    {
        return $this->format(self::CS, $on ?? Carbon::now(), $this->take(self::CS, $on ?? Carbon::now()));
    }

    /** MUST be called inside a transaction. */
    public function nextPo(?Carbon $on = null): string
    {
        return $this->format(self::PO, $on ?? Carbon::now(), $this->take(self::PO, $on ?? Carbon::now()));
    }

    public function previewCs(?Carbon $on = null): string
    {
        $on ??= Carbon::now();

        return $this->format(self::CS, $on, max($this->last(self::CS, $on) + 1, max(1, (int) config('purchasing.cs_first_no', 1))));
    }

    public function previewPo(?Carbon $on = null): string
    {
        $on ??= Carbon::now();

        return $this->format(self::PO, $on, max($this->last(self::PO, $on) + 1, max(1, (int) config('purchasing.po_first_no', 1))));
    }

    /** "26-27" for 1 Oct 2026 when the financial year starts in July. */
    public function financialYear(Carbon $on): string
    {
        $start = max(1, min(12, (int) config('purchasing.fy_start_month', 7)));
        $startYear = $on->month >= $start ? $on->year : $on->year - 1;

        return sprintf('%02d-%02d', $startYear % 100, ($startYear + 1) % 100);
    }

    private function key(string $type, Carbon $on): string
    {
        return $type.'|'.$this->financialYear($on);
    }

    private function last(string $type, Carbon $on): int
    {
        return (int) (DB::table('gs_purchase_sequences')->where('period_key', $this->key($type, $on))->value('last_no') ?? 0);
    }

    private function take(string $type, Carbon $on): int
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('GsDocumentNumberGenerator must run inside a transaction: the row lock that keeps numbers unique is released when it ends.');
        }

        $key = $this->key($type, $on);

        if (! DB::table('gs_purchase_sequences')->where('period_key', $key)->exists()) {
            try {
                DB::table('gs_purchase_sequences')->insert([
                    'period_key' => $key, 'last_no' => 0, 'created_at' => now(), 'updated_at' => now(),
                ]);
            } catch (\Illuminate\Database\QueryException $e) {
                // Someone else created it first, which is the outcome we wanted.
            }
        }

        $row = DB::table('gs_purchase_sequences')->where('period_key', $key)->lockForUpdate()->first();
        $first = max(1, (int) config($type === self::CS ? 'purchasing.cs_first_no' : 'purchasing.po_first_no', 1));
        $next = max((int) ($row->last_no ?? 0) + 1, $first);

        DB::table('gs_purchase_sequences')->where('period_key', $key)->update(['last_no' => $next, 'updated_at' => now()]);

        return $next;
    }

    private function format(string $type, Carbon $on, int $no): string
    {
        $fy = $this->financialYear($on);
        $seq = str_pad((string) $no, (int) config($type === self::CS ? 'purchasing.cs_seq_width' : 'purchasing.po_seq_width', $type === self::CS ? 4 : 3), '0', STR_PAD_LEFT);

        return $type === self::CS
            ? config('purchasing.cs_prefix', 'CS').'-'.$fy.'/'.$seq
            : config('purchasing.po_prefix', 'PO').'-'.$fy.'/'.config('purchasing.po_code', 'T').'-'.$seq;
    }
}
