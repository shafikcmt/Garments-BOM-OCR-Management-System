<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\ItemCategory;
use App\Models\StockItem;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * THE ITEM SKU (owner, 2026-09-29).
 *
 * FORMAT is an Admin setting built from tokens — {CAT:n} the category code,
 * {NAME:n} the item name, {BRAND:n} the brand, {SEQ:n} the running serial —
 * plus fixed text and separators. Default {CAT:2}-{NAME:3}-{SEQ:4}:
 *
 *   Ball Pen-Black (Stationery & Printing) → ST-BAL-0001
 *   Ball Pen-Red   (Stationery & Printing) → ST-BAL-0002
 *
 * Letters only, uppercase; spaces, symbols and digits are skipped; a short
 * value is padded with X ("X" → "XXX"; no brand → "XXX"). No category → GN.
 *
 * THE SERIAL runs per prefix — everything except {SEQ} ("ST-BAL-") — and is
 * taken under a row lock (stock_item_sku_sequences), so two items created at
 * once never get the same number; the unique index on stock_items.sku is the
 * backstop.
 *
 * AN SKU NEVER CHANGES once set: renaming the item, moving its category or
 * changing the format leaves it alone (a format change applies to new items
 * only). Only Admin corrects one, with a reason, logged
 * (stock_item_sku_changes) — correct().
 */
final class StockItemSku
{
    public const SETTING_PATTERN = 'stock_item_sku_pattern';

    public const DEFAULT_PATTERN = '{CAT:2}-{NAME:3}-{SEQ:4}';

    public const NO_CATEGORY = 'GN';

    public const PAD = 'X';

    public const MAX_LENGTH = 40;

    /** Stands in for {SEQ} inside a prefix; never allowed in fixed text. */
    private const SEQ_MARK = '#';

    private const TOKEN = '/\{(CAT|NAME|BRAND|SEQ):(\d{1,2})\}/';

    public static function pattern(): string
    {
        $pattern = trim((string) AppSetting::get(self::SETTING_PATTERN, ''));

        return $pattern !== '' && self::patternProblem($pattern) === null ? $pattern : self::DEFAULT_PATTERN;
    }

    /** Why a pattern cannot be used, in words for the settings screen; null when it can. */
    public static function patternProblem(string $pattern): ?string
    {
        $pattern = trim($pattern);

        if ($pattern === '') {
            return 'Enter a format.';
        }

        preg_match_all(self::TOKEN, $pattern, $tokens, PREG_SET_ORDER);
        $rest = preg_replace(self::TOKEN, '', $pattern);

        if (str_contains($rest, '{') || str_contains($rest, '}')) {
            return 'Use only {CAT:n}, {NAME:n}, {BRAND:n} and {SEQ:n} as tokens.';
        }

        if (! preg_match('/^[A-Za-z0-9 \-_\/.]*$/', $rest)) {
            return 'Fixed text may hold letters, digits, spaces and - _ / . only.';
        }

        $seq = array_filter($tokens, fn ($t) => $t[1] === 'SEQ');
        if (count($seq) !== 1) {
            return 'The format needs exactly one {SEQ:n}.';
        }

        foreach ($tokens as [, $name, $width]) {
            $width = (int) $width;
            [$min, $max] = $name === 'SEQ' ? [2, 8] : [1, 10];
            if ($width < $min || $width > $max) {
                return '{'.$name.'} takes a width from '.$min.' to '.$max.'.';
            }
        }

        if (strlen(self::render(self::prefix($pattern, 'XX', 'X', 'X'), 1, $pattern)) > self::MAX_LENGTH) {
            return 'The SKU would be longer than '.self::MAX_LENGTH.' characters.';
        }

        return null;
    }

    /** Letters only, uppercase, the first $width, padded with X. */
    public static function letters(?string $value, int $width): string
    {
        $letters = strtoupper((string) preg_replace('/[^A-Za-z]/', '', (string) $value));

        return str_pad(substr($letters, 0, $width), $width, self::PAD);
    }

    /** The category's code; its first two letters when it has none; GN with no category. */
    public static function categoryCode(?ItemCategory $category): string
    {
        if (! $category) {
            return self::NO_CATEGORY;
        }

        $code = strtoupper(trim((string) $category->code));

        return $code !== '' ? $code : self::letters($category->name, 2);
    }

    /** Every token but {SEQ} filled in, {SEQ} left as a mark: "ST-BAL-#". The serial's scope. */
    public static function prefix(string $pattern, string $categoryCode, ?string $name, ?string $brand): string
    {
        return preg_replace_callback(self::TOKEN, function ($m) use ($categoryCode, $name, $brand) {
            $width = (int) $m[2];

            return match ($m[1]) {
                'CAT' => self::letters($categoryCode, $width),
                'NAME' => self::letters($name, $width),
                'BRAND' => self::letters($brand, $width),
                'SEQ' => self::SEQ_MARK,
            };
        }, $pattern);
    }

    /** The prefix with its serial in place, zero-padded to the {SEQ} width. */
    public static function render(string $prefix, int $seq, string $pattern): string
    {
        preg_match('/\{SEQ:(\d{1,2})\}/', $pattern, $m);

        return str_replace(self::SEQ_MARK, str_pad((string) $seq, (int) ($m[1] ?? 4), '0', STR_PAD_LEFT), $prefix);
    }

    public static function prefixFor(StockItem $item, ?string $pattern = null): string
    {
        $category = $item->item_category_id ? ItemCategory::withTrashed()->find($item->item_category_id) : null;

        return self::prefix($pattern ?? self::pattern(), self::categoryCode($category), $item->name, $item->brand);
    }

    /**
     * Take the next SKU for an item. Runs in its own transaction (a savepoint
     * inside a caller's), so the sequence row stays locked until the number
     * is written.
     */
    public function next(StockItem $item): string
    {
        $pattern = self::pattern();
        $prefix = self::prefixFor($item, $pattern);

        return DB::transaction(function () use ($prefix, $pattern) {
            $row = DB::table('stock_item_sku_sequences')->where('prefix', $prefix)->lockForUpdate()->first();

            if (! $row) {
                try {
                    DB::table('stock_item_sku_sequences')->insert(['prefix' => $prefix, 'last_seq' => 0, 'created_at' => now(), 'updated_at' => now()]);
                } catch (\Illuminate\Database\UniqueConstraintViolationException) {
                    // Another request made it first; the lock below waits for it.
                }
                $row = DB::table('stock_item_sku_sequences')->where('prefix', $prefix)->lockForUpdate()->first();
            }

            $seq = (int) $row->last_seq;

            // Skip any number already taken (an Admin correction, an import).
            do {
                $seq++;
                $sku = self::render($prefix, $seq, $pattern);
            } while (StockItem::query()->where('sku', $sku)->exists());

            DB::table('stock_item_sku_sequences')->where('prefix', $prefix)->update(['last_seq' => $seq, 'updated_at' => now()]);

            return $sku;
        });
    }

    /**
     * Items without an SKU, in the order they are given one: category code,
     * then name, then brand, then id — so the preview and the real run agree
     * line for line.
     *
     * @return Collection<int, StockItem>
     */
    public function missing(): Collection
    {
        $items = StockItem::query()->whereNull('sku')->with(['itemCategory' => fn ($q) => $q->withTrashed()])->get();

        return $items->sortBy([
            fn ($a, $b) => strcmp(self::categoryCode($a->itemCategory), self::categoryCode($b->itemCategory)),
            fn ($a, $b) => strnatcasecmp((string) $a->name, (string) $b->name),
            fn ($a, $b) => strnatcasecmp((string) $a->brand, (string) $b->brand),
            fn ($a, $b) => $a->id <=> $b->id,
        ])->values();
    }

    /**
     * What the backfill WOULD give each item — nothing is written. Counters
     * start from the stored ones and skip SKUs already taken, exactly as
     * next() does.
     *
     * @return list<array{item: StockItem, category: string, code: string, sku: string}>
     */
    public function preview(): array
    {
        $pattern = self::pattern();
        $counters = DB::table('stock_item_sku_sequences')->pluck('last_seq', 'prefix')->map(fn ($v) => (int) $v)->all();
        $taken = StockItem::query()->whereNotNull('sku')->pluck('sku')->flip()->all();
        $rows = [];

        foreach ($this->missing() as $item) {
            $prefix = self::prefix($pattern, self::categoryCode($item->itemCategory), $item->name, $item->brand);
            $seq = $counters[$prefix] ?? 0;

            do {
                $seq++;
                $sku = self::render($prefix, $seq, $pattern);
            } while (isset($taken[$sku]));

            $counters[$prefix] = $seq;
            $taken[$sku] = true;

            $rows[] = [
                'item' => $item,
                'category' => $item->itemCategory?->name ?? '',
                'code' => self::categoryCode($item->itemCategory),
                'sku' => $sku,
            ];
        }

        return $rows;
    }

    /**
     * The dry-run preview as an Excel file — for the Store team to check
     * before anything is applied. Writes nothing to the database.
     *
     * @return array{path: string, count: int}
     */
    public function writePreview(?string $path = null): array
    {
        $rows = $this->preview();
        $path ??= storage_path('app/sku-preview/sku-preview-'.now()->format('Ymd-His').'.xlsx');

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }

        $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $book->getActiveSheet()->setTitle('SKU Preview');
        $headings = ['SL', 'Proposed SKU', 'Item Name', 'Brand', 'Uom', 'Category', 'Category Code', 'Item ID'];
        $sheet->fromArray([
            [config('stock.company_name').' — Item SKU preview (dry run, nothing applied)'],
            ['Format: '.self::pattern().' · generated '.now()->format('d M Y, h:i A').' · '.count($rows).' item(s) without an SKU'],
            [],
            $headings,
        ]);

        $r = 5;
        foreach ($rows as $i => $row) {
            $sheet->fromArray([
                $i + 1, $row['sku'], $row['item']->name, $row['item']->brand ?: '', $row['item']->uom ?: '',
                $row['category'] ?: '(no category)', $row['code'], $row['item']->id,
            ], null, 'A'.$r++);
        }

        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);
        $sheet->getStyle('A4:H4')->getFont()->setBold(true);
        $sheet->getStyle('A4:H4')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('E8EEF7');
        foreach (['A' => 6, 'B' => 18, 'C' => 44, 'D' => 18, 'E' => 8, 'F' => 26, 'G' => 13, 'H' => 9] as $col => $w) {
            $sheet->getColumnDimension($col)->setWidth($w);
        }
        $sheet->freezePane('A5');
        $sheet->setAutoFilter('A4:H'.max(4, $r - 1));

        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($path);

        return ['path' => $path, 'count' => count($rows)];
    }

    /** Give every item without an SKU its SKU, in preview order. All or nothing. */
    public function generateMissing(): int
    {
        return DB::transaction(function () {
            $count = 0;

            foreach ($this->missing() as $item) {
                $item->forceFill(['sku' => $this->next($item)])->saveQuietly();
                $count++;
            }

            return $count;
        });
    }

    /**
     * Admin corrects an SKU, with a reason — the only way one ever changes.
     * Logged with the old and new value.
     */
    public function correct(StockItem $item, string $sku, string $reason, User $user): void
    {
        $sku = strtoupper(trim($sku));

        if (! preg_match('/^[A-Z0-9][A-Z0-9 \-_\/.]{0,'.(self::MAX_LENGTH - 1).'}$/', $sku)) {
            throw ValidationException::withMessages(['sku' => 'Use letters, digits and - _ / . only, up to '.self::MAX_LENGTH.' characters.']);
        }

        if (StockItem::query()->where('sku', $sku)->whereKeyNot($item->getKey())->exists()) {
            throw ValidationException::withMessages(['sku' => 'SKU '.$sku.' is already used by another item.']);
        }

        if ($sku === $item->sku) {
            throw ValidationException::withMessages(['sku' => 'That is already this item\'s SKU.']);
        }

        DB::transaction(function () use ($item, $sku, $reason, $user) {
            DB::table('stock_item_sku_changes')->insert([
                'stock_item_id' => $item->id,
                'old_sku' => $item->sku,
                'new_sku' => $sku,
                'reason' => trim($reason),
                'user_id' => $user->id,
                'user_name' => $user->name,
                'created_at' => now(),
            ]);

            StockItem::$skuCorrection = true;
            try {
                $item->forceFill(['sku' => $sku])->save();
            } finally {
                StockItem::$skuCorrection = false;
            }
        });
    }
}
