<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Services\BranchService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function __construct(private BranchService $branchService) {}

    public function run(): void
    {
        User::create([
            'name' => 'Admin',
            'user' => 'admin',
            'email' => 'pier_surdito@hotmail.com',
            'password' => Hash::make('Laravel'),
            'user_type_id' => 1,
        ]);

        $user = User::factory()->create([
            'name' => 'Client',
            'user' => 'client',
            'email' => 'client@hotmail.com',
            'password' => Hash::make('Laravel'),
            'user_type_id' => 2,
        ]);

        $company = Company::factory()->create();

        $user->companyusers()->create([
            'level' => 'owner',
            'level_id' => $company->id,
        ]);

        $branch = Branch::factory()->create([
            'company_id' => $company->id,
            'store' => 1,
            'type' => 'matriz',
        ]);

        $this->branchService->createFinalConsumer($branch);
    }
}
