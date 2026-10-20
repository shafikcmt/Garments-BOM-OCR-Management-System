<?php

namespace App\Support;

/**
 * Reads the style codes merchandisers type into lot file names and BOM sheets.
 *
 * Real examples: "882635", "720193(PETITE)", "789055(CURVY)", "748268 (866317 )".
 * The part before the brackets is the MOTHER style number; the bracket becomes
 * a CHILD — a fit variant when it is text (PETITE), a linked style number when
 * it is digits (866317).
 */
class StyleCodeParser
{
    /**
     * @return array{style_no: string, child: ?string, kind: ?string}
     */
    public static function parse(string $code): array
    {
        $code = trim($code);

        if (preg_match('/^(.*?)\s*\(([^)]*)\)\s*$/u', $code, $m)) {
            $child = trim($m[2]);

            return [
                'style_no' => trim($m[1]),
                'child' => $child === '' ? null : $child,
                'kind' => $child === '' ? null : (preg_match('/^[0-9A-Za-z\-]*\d[0-9A-Za-z\-]*$/', $child) ? 'linked' : 'variant'),
            ];
        }

        return ['style_no' => $code, 'child' => null, 'kind' => null];
    }

    /**
     * Split a pasted list on commas, semicolons, "&" and new lines — never
     * inside brackets — and parse each entry. Blank entries are dropped.
     *
     * @return list<array{style_no: string, child: ?string, kind: ?string}>
     */
    public static function parseList(string $text): array
    {
        $parts = preg_split('/[,;&\n\r]+(?![^(]*\))/u', $text) ?: [];

        return collect($parts)
            ->map(fn ($part) => self::parse($part))
            ->filter(fn ($row) => $row['style_no'] !== '')
            ->values()
            ->all();
    }
}
