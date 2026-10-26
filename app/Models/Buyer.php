<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Buyer extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'buyer_code',
        'buyer_name',
        'department_admin_id',
        'contact_person',
        'email',
        'phone',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeInactive($query)
    {
        return $query->where('is_active', false);
    }

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    /** The one Merchant department-admin who owns this buyer, if assigned. */
    public function departmentAdmin()
    {
        return $this->belongsTo(User::class, 'department_admin_id');
    }

    /**
     * The team members assigned to this buyer.
     *
     * The inverse of User::buyers(). Assignment and ownership are two different
     * columns for two different kinds of user — a department admin OWNS buyers
     * through departmentAdmin() above and never appears here.
     */
    public function users()
    {
        return $this->belongsToMany(User::class)->orderBy('users.id');
    }

    /** Workspace columns hidden for this buyer's files (Admin > Buyers > Workspace Columns). */
    public function hiddenHeaders()
    {
        return $this->belongsToMany(ExcelHeader::class, 'buyer_hidden_headers', 'buyer_id', 'excel_header_id')->withTimestamps();
    }

    public function seasons()
    {
        return $this->hasMany(BuyerSeason::class)->orderByDesc('id');
    }

    public function motherStyles()
    {
        return $this->hasMany(MotherStyle::class);
    }

    public function excelFiles()
    {
        return $this->hasMany(ExcelFile::class);
    }
}
