<?php

namespace App\Support;

/**
 * Works out where a BOM row's style sits in the Style Library.
 *
 * One rule for the import preview and for the real sync (BomTableSync), so the
 * preview never promises something the import does not do. The mother comes
 * from, in this order:
 *   1. the sheet's own Mother Style column (the whole style code is then the child)
 *   2. the style code before its bracket: "720193(PETITE)" -> 720193
 *   3. the style itself: "882635" is its own mother, with no child
 */
class StyleHierarchy
{
    public const FROM_COLUMN = 'column';
    public const FROM_CODE = 'code';
    public const FROM_STYLE = 'style';

    /**
     * @return array{mother: string, child: ?string, kind: ?string, source: string}
     */
    public static function resolve(string $styleCode, ?string $motherCode = null): array
    {
        $parsed = StyleCodeParser::parse($styleCode);

        // Excel often turns a typed number into "813132.0".
        $mother = preg_replace('/^(\d+)\.0+$/', '$1', trim((string) $motherCode));

        if ($mother !== '') {
            $code = trim($styleCode);

            return [
                'mother' => $mother,
                'child' => $code === $mother ? null : $code,
                'kind' => $code === $mother ? null : ($parsed['kind'] ?? 'linked'),
                'source' => self::FROM_COLUMN,
            ];
        }

        return [
            'mother' => $parsed['style_no'],
            'child' => $parsed['child'],
            'kind' => $parsed['kind'],
            'source' => $parsed['child'] === null ? self::FROM_STYLE : self::FROM_CODE,
        ];
    }
}
