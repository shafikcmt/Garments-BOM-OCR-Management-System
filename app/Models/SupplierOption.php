<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One entry of an Admin-managed vendor pick list (Item Type, Incoterm, Ship Mode). */
class SupplierOption extends Model
{
    public const TYPES = [
        'item_type' => 'Item Types',
        'incoterm' => 'Incoterms',
        'ship_mode' => 'Ship Modes',
        'payment_term' => 'Payment Terms',
    ];

    protected $fillable = ['type', 'name', 'sort_order'];

    /**
     * Names to offer in a dropdown, in Admin's order. Values a record already holds
     * but that were later deleted from the list are appended, so editing a vendor for
     * another reason never blanks what is printed on its PO/PI.
     *
     * @param  iterable<string>  $keep
     * @return list<string>
     */
    public static function names(string $type, iterable $keep = []): array
    {
        $names = static::where('type', $type)->orderBy('sort_order')->orderBy('name')->pluck('name')->all();

        foreach ($keep as $value) {
            $value = trim((string) $value);
            if ($value !== '' && ! in_array($value, $names, true)) {
                $names[] = $value;
            }
        }

        return $names;
    }

    /** Split a stored multi-value ("Fabric, Trim") into its parts. @return list<string> */
    public static function split(?string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $value)), fn ($v) => $v !== ''));
    }
}
