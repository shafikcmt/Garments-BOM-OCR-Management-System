<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A column title from a buyer's file that the system has no header for. */
class ImportUnmappedColumn extends Model
{
    public const OPEN = 'open';

    public const MAPPED = 'mapped';

    public const IGNORED = 'ignored';

    protected $fillable = [
        'buyer_id', 'title', 'title_key', 'times_seen', 'first_seen_at', 'last_seen_at', 'last_file_id',
        'status', 'mapped_header_id', 'resolved_by', 'resolved_at',
    ];

    protected $casts = ['first_seen_at' => 'datetime', 'last_seen_at' => 'datetime', 'resolved_at' => 'datetime'];

    public function buyer()
    {
        return $this->belongsTo(Buyer::class);
    }

    public function header()
    {
        return $this->belongsTo(ExcelHeader::class, 'mapped_header_id');
    }

    public function lastFile()
    {
        return $this->belongsTo(ExcelFile::class, 'last_file_id');
    }
}
