<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
// use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasFactory, Notifiable, HasRoles;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role_id', // Primary role
        'status',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

  
    // Primary role relation
    public function primaryRole()
    {
        return $this->belongsTo(Role::class, 'role_id');
    }
}
