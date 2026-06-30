<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailTemplate extends Model
{
    protected $fillable = [
        'type',
        'name',
        'subject',
        'default_to',
        'default_cc',
        'body',
    ];

    /**
     * Fetch a template by its type, e.g. "pra".
     */
    public static function forType(string $type): ?self
    {
        return static::query()->where('type', $type)->first();
    }

    /**
     * Replace {{placeholder}} tokens in a string with the given values.
     *
     * @param array<string, string> $placeholders
     */
    public static function render(string $text, array $placeholders): string
    {
        foreach ($placeholders as $key => $value) {
            $text = str_replace('{{' . $key . '}}', (string) $value, $text);
        }

        return $text;
    }
}
