<?php

namespace App\Support;

/**
 * Works out where a BOM row's style sits in the Style Library.
 *
 * One rule for the import preview and for the real sync (BomTableSync), so the
 * preview never promises something the import does not do.
 *
 * Mother source (set per buyer on the import profile; null = automatic):
 *   automatic  the sheet's Mother Style column, else the style code, else the style
 *   column     the Mother Style column only; a blank cell is not guessed
 *   code       the style code only: "720193(PETITE)" -> 720193, "608468 PETITE" -> 608468
 *   style      every style is its own mother, never a child
 *
 * With a Mother Style column the whole style code is the child
 * ("788457(PETITE)" under mother 813132), because its number differs from the mother's.
 */
class StyleHierarchy
{
    public const FROM_COLUMN = 'column';
    public const FROM_CODE = 'code';
    public const FROM_STYLE = 'style';

    public const RULES = [self::FROM_COLUMN, self::FROM_CODE, self::FROM_STYLE];

    /** Style cell values that mean "applies to every style" (poly bag, carton), not a real style. */
    private const COMMON = ['all', 'common', 'all styles', 'all style'];

    /**
     * A BOM line for every style of the order. It stays in the BOM but is not
     * a style, so it must never become a mother style in the Style Library.
     */
    public static function isCommon(string $styleCode): bool
    {
        return in_array(mb_strtolower(trim($styleCode)), self::COMMON, true);
    }

    /** @return list<string> */
    public static function fitWordList(?string $text): array
    {
        return collect(preg_split('/[\r\n,;]+/', (string) $text) ?: [])
            ->map(fn ($w) => trim(preg_replace('/\s+/', ' ', $w)))
            ->filter()
            ->unique()
            ->sortByDesc(fn ($w) => mb_strlen($w)) // "CURVY PETITE" before "PETITE"
            ->values()
            ->all();
    }

    /**
     * @param  ?string  $rule  one of RULES, or null for automatic
     * @param  ?string  $fitWords  the buyer's fit words, comma separated
     * @return array{mother: string, child: ?string, kind: ?string, source: string, missing: bool}
     *         missing = the rule asked for a Mother Style value and the cell was blank
     */
    public static function resolve(string $styleCode, ?string $motherCode = null, ?string $rule = null, ?string $fitWords = null): array
    {
        $code = trim($styleCode);
        $rule = in_array($rule, self::RULES, true) ? $rule : null;

        if ($rule === self::FROM_STYLE) {
            return self::place($code, null, null, self::FROM_STYLE);
        }

        // Excel often turns a typed number into "813132.0".
        $mother = preg_replace('/^(\d+)\.0+$/', '$1', trim((string) $motherCode));

        if ($rule !== self::FROM_CODE && $mother !== '') {
            $parsed = StyleCodeParser::parse($code);

            return self::place(
                $mother,
                $code === $mother ? null : $code,
                $code === $mother ? null : ($parsed['kind'] ?? 'linked'),
                self::FROM_COLUMN
            );
        }

        if ($rule === self::FROM_COLUMN) {
            // Asked for the column but the cell is empty: no guessing, flag it.
            return ['missing' => true] + self::place($code, null, null, self::FROM_STYLE);
        }

        return self::fromCode($code, $fitWords);
    }

    private static function fromCode(string $code, ?string $fitWords): array
    {
        $parsed = StyleCodeParser::parse($code);

        // "608468 PETITE": the fit follows the number without a bracket.
        if ($parsed['child'] === null) {
            foreach (self::fitWordList($fitWords) as $word) {
                if (preg_match('/^(.+?)\s+' . preg_quote($word, '/') . '$/iu', $code, $m) && trim($m[1]) !== '') {
                    return self::place(trim($m[1]), $word, 'variant', self::FROM_CODE);
                }
            }
        }

        return self::place($parsed['style_no'], $parsed['child'], $parsed['kind'], $parsed['child'] === null ? self::FROM_STYLE : self::FROM_CODE);
    }

    private static function place(string $mother, ?string $child, ?string $kind, string $source): array
    {
        return ['mother' => $mother, 'child' => $child, 'kind' => $kind, 'source' => $source, 'missing' => false];
    }
}
