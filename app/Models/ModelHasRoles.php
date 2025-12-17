<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class ModelHasRoles extends Pivot
{
    protected $table = 'model_has_roles';

    protected $fillable = [
        'role_id',
        'model_type',
        'model_id',
    ];
}
