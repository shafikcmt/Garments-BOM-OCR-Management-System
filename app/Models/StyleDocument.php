<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One file in a style's document bank (tech pack, lab dip, PP comment ...). */
class StyleDocument extends Model
{
    /** Suggestions only; the category is free text kept by the user. */
    public const CATEGORY_HINTS = ['Tech Pack', 'Lab Dip', 'PP Comment', 'Test Report', 'PI Copy', 'Buyer Email', 'Other'];

    protected $fillable = ['mother_style_id', 'category', 'title', 'file_path', 'original_name', 'mime_type', 'file_size', 'uploaded_by'];

    public function motherStyle()
    {
        return $this->belongsTo(MotherStyle::class);
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function sizeLabel(): string
    {
        $kb = $this->file_size / 1024;

        return $kb >= 1024 ? number_format($kb / 1024, 1) . ' MB' : max(1, (int) round($kb)) . ' KB';
    }
}
