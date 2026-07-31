<?php

namespace Database\Seeders;

use App\Models\UserType;
use Illuminate\Database\Seeder;

class UserTypeSeeder extends Seeder
{
    public function run()
    {
        UserType::create(['id' => 1, 'type' => 'admin']);
        UserType::create(['id' => 2, 'type' => 'client']);
    }
}
