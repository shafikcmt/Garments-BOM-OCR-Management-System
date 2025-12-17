<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class ModelHasPermissions extends Pivot
{
    protected $table = 'model_has_permissions';

    protected $fillable = [
        'permission_id',
        'model_type',
        'model_id',
    ];
}
