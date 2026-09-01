<?php

namespace App\Support;

use App\Models\StockItem;

/**
 * Which item master row an imported line means.
 *
 * WHY THIS EXISTS. ReceivingImport and IssueImport both resolved an item with
 * `StockItem::get()->keyBy(name)`, and `keyBy` keeps only the LAST row on a
 * duplicate key. Duplicate names are deliberate — there is no unique index on
 * `stock_items.name`, and ItemMasterImport dedups on name AND brand precisely so
 * "B/S Needle DPX17-09" can be stocked as both Generic and Organ. So one of
 * every duplicate pair was unreachable by import, and every line naming it
 * posted its quantity to whichever row survived: wrong item, no error, and the
 * figure goes on to feed the Stock Report and the re-order levels.
 *
 * The importer that CREATES items already knew the rule; the two that CONSUME
 * them did not. This is that rule, in one place, asked by all three.
 *
 * THE LADDER, in this order. Each rung exists for a reason and dropping one
 * either re-opens the bug or breaks a file that imports today:
 *
 *   1. exact (name, brand)   the answer whenever the file names a brand that
 *                            the master also holds. This is the fix.
 *   2. name resolves alone   no exact match, but only ONE item bears the name,
 *                            so there is nothing to be ambiguous about. Keeps
 *                            every legacy sheet importing: those files write
 *                            brand text freely ("Organ Needle Co." against a
 *                            master holding "Organ"), and refusing them because
 *                            the brand is worded differently would reject
 *                            deliveries that are not in doubt. The importers'
 *                            existing brand-mismatch NOTE still fires here.
 *   3. several candidates    REFUSED, naming the brands to choose from. The old
 *                            code silently picked one; a rejected delivery is
 *                            fixable in the file, a quantity on the wrong item
 *                            is not noticed for months.
 *   4. nothing at all        not in the master — the importers' existing error.
 *
 * BLANK BRAND IS A VALUE, NEVER A WILDCARD, on either side. A blank-brand line
 * matches a blank-brand item and nothing else. Made permissive it would match
 * every brand of that name at once, which is rung 3 — that is, the original bug
 * wearing a different hat. It is also the rule ItemMasterImport::identity()
 * already states, and the two must agree or an item this system created becomes
 * an item it cannot find.
 */
final class StockItemLookup
{
    /** @var array<string, list<StockItem>> */
    private array $byIdentity = [];

    /** @var array<string, list<StockItem>> */
    private array $byName = [];

    /**
     * Lists, not single rows, on BOTH indexes. Indexing by identity alone would
     * repeat the original mistake one level down: three items called "probe"
     * with no brand share one identity, and keeping the last of them would pick
     * silently again. A bucket holding more than one is rung 3, not a winner.
     *
     * @param  iterable<StockItem>  $items
     */
    private function __construct(iterable $items)
    {
        foreach ($items as $item) {
            $this->byIdentity[self::identity($item->name, $item->brand)][] = $item;
            $this->byName[self::normalize($item->name)][] = $item;
        }
    }

    /**
     * The whole item master, one query.
     *
     * Selects the columns the importers cross-check against (uom, category,
     * brand) as well as the id, so resolving an item does not then cost a
     * second query per row to read them.
     */
    public static function fromMaster(): self
    {
        return new self(StockItem::get(['id', 'name', 'uom', 'category', 'brand']));
    }

    /**
     * For tests and callers that already hold the items.
     *
     * @param  iterable<StockItem>  $items
     */
    public static function for(iterable $items): self
    {
        return new self($items);
    }

    /**
     * Resolve one imported line.
     *
     * `ambiguous` is deliberately separate from a null item: "we cannot tell
     * which of these you mean" and "we have never heard of this" are different
     * problems and the person fixing the file needs different instructions.
     *
     * @return array{item: StockItem|null, ambiguous: bool, brands: list<string>}
     */
    public function resolve(?string $name, ?string $brand): array
    {
        $exact = $this->byIdentity[self::identity($name, $brand)] ?? [];

        if ($exact !== []) {
            return count($exact) === 1 ? self::matched($exact[0]) : self::ambiguous($exact);
        }

        $sameName = $this->byName[self::normalize($name)] ?? [];

        if ($sameName !== []) {
            return count($sameName) === 1 ? self::matched($sameName[0]) : self::ambiguous($sameName);
        }

        return ['item' => null, 'ambiguous' => false, 'brands' => []];
    }

    /**
     * What to tell the user about an ambiguous line, without the "Row N: "
     * prefix each importer adds itself.
     *
     * Two different sentences, because two different things went wrong and only
     * one of them is fixable in the spreadsheet:
     *
     *   several brands   the file can say which. Name them, so the user is
     *                    choosing from the master rather than guessing at it.
     *   one brand, many  the master itself holds duplicates — nothing typed in
     *                    the file can separate them, so it says so and points
     *                    at Items instead of at the sheet.
     *
     * @param  array{item: StockItem|null, ambiguous: bool, brands: list<string>}  $match
     */
    public static function ambiguityMessage(string $itemName, array $match): string
    {
        if (count($match['brands']) > 1) {
            return '"'.$itemName.'" is held under more than one brand ('
                .implode(', ', $match['brands'])
                .'). Put the exact brand in the Brand/Specification column.';
        }

        return '"'.$itemName.'" matches more than one item in the item master. '
            .'Remove the duplicate under Items first.';
    }

    /**
     * What makes one stock item the same as another: its name and its brand,
     * compared without regard to case or spacing.
     *
     * THE single definition, shared with ItemMasterImport so the importer that
     * creates an item and the importers that find it cannot drift apart. That
     * drift is the whole bug this class exists for.
     */
    public static function identity(?string $name, ?string $brand): string
    {
        return self::normalize($name).'|'.self::normalize($brand);
    }

    /**
     * Lower case, trimmed, internal whitespace runs collapsed.
     *
     * "Circuit  Breaker" and "Circuit Breaker" are one part — a double space is
     * a typing slip, not a different item, and ItemMasterImport has always
     * refused to create the second as a separate row on that basis.
     */
    private static function normalize(?string $value): string
    {
        return preg_replace('/\s+/', ' ', mb_strtolower(trim((string) $value)));
    }

    /**
     * @return array{item: StockItem, ambiguous: false, brands: list<string>}
     */
    private static function matched(StockItem $item): array
    {
        return ['item' => $item, 'ambiguous' => false, 'brands' => []];
    }

    /**
     * The distinct brands on offer, in master order, blanks labelled so the
     * message reads as a choice rather than as a gap.
     *
     * @param  list<StockItem>  $candidates
     * @return array{item: null, ambiguous: true, brands: list<string>}
     */
    private static function ambiguous(array $candidates): array
    {
        $brands = [];

        foreach ($candidates as $candidate) {
            $label = trim((string) $candidate->brand);
            $label = $label === '' ? 'no brand' : $label;

            if (! in_array($label, $brands, true)) {
                $brands[] = $label;
            }
        }

        return ['item' => null, 'ambiguous' => true, 'brands' => $brands];
    }
}
