<?php

namespace App\Services;

use App\Models\User;

class UserService
{
    public function create(array $attributes)
    {
        return User::create($attributes);
    }

    public function findByAttributes(array $attributes)
    {
        return User::where($attributes)->firstOrFail();
    }
}
