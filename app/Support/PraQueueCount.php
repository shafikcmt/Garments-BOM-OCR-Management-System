<?php

namespace App\Support;

use App\Models\PaymentRequest;
use App\Models\User;
use App\Services\PraStageResolver;
use Illuminate\Support\Facades\Cache;

/**
 * How many departmental PRAs are waiting on THIS user right now: the number on
 * the sidebar's "PRA Approvals" badge.
 *
 * It asks the same question as the queue itself (PraStageResolver::mayAct for
 * the PRA's current step), so the badge and the list always agree. That costs a
 * few queries per PRA, and the sidebar draws on every page, so the answer is
 * cached for a short time per user. Never throws: a badge must not break a page.
 */
final class PraQueueCount
{
    private const TTL_SECONDS = 30;

    public static function forUser(?User $user): int
    {
        if (! $user) {
            return 0;
        }

        try {
            return (int) Cache::remember(self::key($user->id), self::TTL_SECONDS, function () use ($user) {
                $resolver = app(PraStageResolver::class);

                return PaymentRequest::query()
                    ->where('flow_version', PaymentRequest::FLOW_DEPARTMENTAL)
                    ->whereNotNull('current_sequence')
                    ->with('items.excelFile')
                    ->get()
                    ->filter(fn (PaymentRequest $pra) => $resolver->mayAct($pra, (int) $pra->current_sequence, $user))
                    ->count();
            });
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /** Drop a user's cached count, e.g. right after they act on a PRA. */
    public static function forget(?int $userId): void
    {
        if ($userId) {
            Cache::forget(self::key($userId));
        }
    }

    private static function key(int $userId): string
    {
        return 'pra.queue.count.' . $userId;
    }
}
