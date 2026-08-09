<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * Free-text entry for the four Issue Setup dropdowns (Indent Section, Indent
 * Person, Approved By, Category).
 *
 * Store staff work fast and a name they need is often not in the list yet.
 * Rather than sending them to another screen mid-entry, the dropdowns let them
 * type a new value: the browser posts it as "new:<name>" and this resolves it
 * to a master row, creating one if needed, so it is a normal suggestion from
 * then on.
 *
 * An existing selection posts a plain numeric id and is used as-is.
 */
trait ResolvesIssueSetupMasters
{
    /** Marker the front end puts in front of a value the user typed himself. */
    public const NEW_VALUE_PREFIX = 'new:';

    /**
     * Turn one posted dropdown value into a master row id.
     *
     * @param  class-string<Model>  $model
     * @return int|null  null when the field was left blank
     */
    protected function resolveMasterValue(string $model, mixed $value): ?int
    {
        $value = is_string($value) ? trim($value) : $value;

        if ($value === null || $value === '') {
            return null;
        }

        // An existing option: the id is posted directly.
        if (! str_starts_with((string) $value, self::NEW_VALUE_PREFIX)) {
            return is_numeric($value) ? (int) $value : null;
        }

        $name = trim(substr((string) $value, strlen(self::NEW_VALUE_PREFIX)));

        if ($name === '') {
            return null;
        }

        // Match case-insensitively so "cutting" does not become a second
        // "Cutting" — the whole point of the masters is one spelling each.
        $existing = $model::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();

        if ($existing) {
            // Typing the name of something previously deactivated brings it
            // back, which is what the user plainly meant by choosing it.
            if (! $existing->is_active) {
                $existing->update(['is_active' => true]);
            }

            return $existing->id;
        }

        return $model::create([
            'name' => mb_substr($name, 0, 150),
            'is_active' => true,
            'created_by' => auth()->id(),
        ])->id;
    }
}
