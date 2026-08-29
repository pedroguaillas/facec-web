<?php

use App\Models\Company;
use App\Models\User;
use App\Models\UserType;

function actingAsAdmin(): User
{
    $adminType = UserType::firstOrCreate(['type' => 'admin']);
    $admin = User::factory()->create(['user_type_id' => $adminType->id]);

    test()->actingAs($admin);

    return $admin;
}

test('admin.companies.update persiste active y active_voucher', function () {
    actingAsAdmin();
    $company = Company::factory()->create(['active' => true, 'active_voucher' => true]);

    $response = $this->putJson(route('admin.companies.update', $company), [
        'active' => false,
        'active_voucher' => false,
    ]);

    $response->assertOk();
    $company->refresh();
    expect($company->active)->toBeFalsy();
    expect($company->active_voucher)->toBeFalsy();
});

test('admin.companies.update persiste los toggles agregados en 2022-2025 (base5, base8, ice, inventory, printf, etc.)', function () {
    actingAsAdmin();
    $company = Company::factory()->create([
        'base5' => false,
        'base8' => false,
        'ice' => false,
        'inventory' => false,
        'printf' => false,
        'guia_in_invoice' => false,
        'import_in_invoice' => false,
        'import_in_invoices' => false,
        'transport' => false,
        'repayment' => false,
        'pay_method' => 20,
    ]);

    $response = $this->putJson(route('admin.companies.update', $company), [
        'base5' => true,
        'base8' => true,
        'ice' => true,
        'inventory' => true,
        'printf' => true,
        'guia_in_invoice' => true,
        'import_in_invoice' => true,
        'import_in_invoices' => true,
        'transport' => true,
        'repayment' => true,
        'pay_method' => 1,
    ]);

    $response->assertOk();
    $company->refresh();
    expect($company->base5)->toBeTrue();
    expect($company->base8)->toBeTrue();
    expect($company->ice)->toBeTrue();
    expect($company->inventory)->toBeTrue();
    expect($company->printf)->toBeTrue();
    expect($company->guia_in_invoice)->toBeTrue();
    expect($company->import_in_invoice)->toBeTrue();
    expect($company->import_in_invoices)->toBeTrue();
    expect($company->transport)->toBeTrue();
    expect($company->repayment)->toBeTrue();
    expect($company->pay_method)->toBe(1);
});
