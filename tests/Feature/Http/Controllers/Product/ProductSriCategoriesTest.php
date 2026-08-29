<?php

use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Product\SriCategory;
use App\Models\User;
use App\Models\UserType;

function actingAsProductCompany(array $companyAttributes = []): Company
{
    $userType = UserType::firstOrCreate(['type' => 'client']);
    $company = Company::factory()->create($companyAttributes);
    $user = User::factory()->create(['user_type_id' => $userType->id]);
    CompanyUser::create(['user_id' => $user->id, 'level' => 1, 'level_id' => $company->id]);

    test()->actingAs($user);

    return $company;
}

beforeEach(function () {
    SriCategory::create(['code' => 'F1', 'type' => 'ferreteria', 'description' => 'Ferretería 1']);
    SriCategory::create(['code' => 'H1', 'type' => 'transporte', 'description' => 'Transporte 1']);
});

test('sin transport ni base5, sriCategories viene vacío', function () {
    actingAsProductCompany(['transport' => false, 'base5' => false]);

    $response = $this->getJson(route('products.create'));

    $response->assertOk();
    expect($response->json('sriCategories'))->toBe([]);
});

test('con transport activo, sriCategories trae solo tipo transporte', function () {
    actingAsProductCompany(['transport' => true, 'base5' => false]);

    $response = $this->getJson(route('products.create'));

    $response->assertOk();
    $types = collect($response->json('sriCategories'))->pluck('type')->unique()->values()->all();
    expect($types)->toBe(['transporte']);
});

test('con base5 activo, sriCategories trae solo tipo ferreteria', function () {
    actingAsProductCompany(['transport' => false, 'base5' => true]);

    $response = $this->getJson(route('products.create'));

    $response->assertOk();
    $types = collect($response->json('sriCategories'))->pluck('type')->unique()->values()->all();
    expect($types)->toBe(['ferreteria']);
});

test('con transport y base5 activos, sriCategories trae ambos tipos', function () {
    actingAsProductCompany(['transport' => true, 'base5' => true]);

    $response = $this->getJson(route('products.create'));

    $response->assertOk();
    $types = collect($response->json('sriCategories'))->pluck('type')->unique()->sort()->values()->all();
    expect($types)->toBe(['ferreteria', 'transporte']);
});
