<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A size run a buyer uses (XS-XXL, 0-16 ...), entered on screen. */
class SizeSet extends Model
{
    protected $fillable = ['buyer_id', 'name', 'sizes', 'created_by'];

    protected $casts = ['sizes' => 'array'];

    public function buyer()
    {
        return $this->belongsTo(Buyer::class);
    }

    /** "XS, S, M", one per line, or "XS S M" (no commas) into a clean ordered list. */
    public static function parseSizes(string $text): array
    {
        $parts = preg_split('/[,\n\r;]+/', $text) ?: [];
        if (count($parts) === 1) {
            $parts = preg_split('/\s+/', trim($text)) ?: [];
        }

        return collect($parts)
            ->map(fn ($s) => mb_strtoupper(trim($s)))
            ->filter(fn ($s) => $s !== '')
            ->unique()
            ->values()
            ->all();
    }
}
