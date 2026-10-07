<?php

namespace Mainstay\Auth;

use Illuminate\Database\Eloquent\Model;

/* A named list of capabilities, `*` holding every one. */
class Role extends Model
{
    protected $table = 'mainstay_roles';

    protected $fillable = ['name', 'capabilities'];

    protected function casts(): array
    {
        return ['capabilities' => 'array'];
    }
}
