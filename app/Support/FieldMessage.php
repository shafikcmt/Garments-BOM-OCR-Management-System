<?php

namespace App\Support;

/**
 * The short tag shown UNDER a field on the entry forms (Record Issue, Record
 * Receiving) — "Required", "More than 0", "0 or more", "Same remark already
 * used". The full sentence stays in the red summary at the top of the page,
 * so a field never carries three wrapped lines (owner, 2026-09-29).
 *
 * Read from the server's own message, so a rule keeps its one wording in the
 * controller and this only decides how briefly to say it under the field.
 */
final class FieldMessage
{
    public static function short(?string $message): ?string
    {
        if ($message === null || trim($message) === '') {
            return null;
        }

        $m = mb_strtolower($message);

        return match (true) {
            str_contains($m, 'same remark') => 'Same remark already used',
            str_contains($m, 'in stock') => 'Not enough stock',
            str_contains($m, 'cannot be earlier') => 'Before challan date',
            // "at least 0.0001" is the qty rule; "at least 0" the price rule.
            str_contains($m, 'at least 0.0001'), str_contains($m, 'more than 0') => 'More than 0',
            str_contains($m, 'at least 0'), str_contains($m, '0 or more') => '0 or more',
            str_contains($m, 'must be a number'), str_contains($m, 'must be a valid date') => 'Invalid',
            str_contains($m, 'required'), str_starts_with($m, 'enter '), str_starts_with($m, 'select ') => 'Required',
            str_contains($m, 'invalid'), str_contains($m, 'is not'), str_contains($m, 'must be') => 'Invalid',
            default => 'Check this field',
        };
    }
}
