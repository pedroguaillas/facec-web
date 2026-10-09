<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Product\Product;
use App\Models\User;
use App\Models\UserType;
use Illuminate\Support\Facades\DB;

/**
 * @return array{0: Company, 1: Product, 2: Product}
 */
function regimeChangeCompany(int $rimpe = 0): array
{
    DB::table('iva_taxes')->insertOrIgnore([
        ['code' => 0, 'percentage' => 0, 'state' => 'active'],
        ['code' => 4, 'percentage' => 15, 'state' => 'active'],
    ]);

    $company = Company::factory()->create(['rimpe' => $rimpe]);
    $firstBranch = Branch::factory()->for($company)->create(['created_at' => now()->subDay()]);
    $secondBranch = Branch::factory()->for($company)->create();

    return [
        $company,
        Product::factory()->create(['branch_id' => $firstBranch->id, 'iva' => 4]),
        Product::factory()->create(['branch_id' => $secondBranch->id, 'iva' => 4]),
    ];
}

function regimeChangeActingAsClient(Company $company): void
{
    $userType = UserType::firstOrCreate(['type' => 'client']);
    $user = User::factory()->create(['user_type_id' => $userType->id]);
    CompanyUser::create(['user_id' => $user->id, 'level' => 1, 'level_id' => $company->id]);

    test()->actingAs($user);
}

function regimeChangeActingAsAdmin(): void
{
    $adminType = UserType::firstOrCreate(['type' => 'admin']);

    test()->actingAs(User::factory()->create(['user_type_id' => $adminType->id]));
}

test('pasar a negocio popular sin confirmación devuelve 422 con la cantidad de productos', function () {
    [$company, $product] = regimeChangeCompany();
    regimeChangeActingAsClient($company);

    $this->putJson(route('settings.company.update'), ['rimpe' => Company::RIMPE_NEGOCIO_POPULAR])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['confirm_iva_change' => '2 producto(s) cambiarán a IVA 0%']);

    expect($company->fresh()->rimpe)->toBe(0)
        ->and($product->fresh()->iva)->toBe(4);
});

test('el cliente pasa a negocio popular con confirmación y todos sus productos quedan en IVA 0%', function () {
    [$company, $firstProduct, $secondProduct] = regimeChangeCompany();
    $otherCompanyProduct = Product::factory()->create(['iva' => 4]);
    regimeChangeActingAsClient($company);

    $this->putJson(route('settings.company.update'), [
        'rimpe' => Company::RIMPE_NEGOCIO_POPULAR,
        'confirm_iva_change' => true,
    ])->assertOk()->assertJsonPath('rimpe', Company::RIMPE_NEGOCIO_POPULAR);

    expect($firstProduct->fresh()->iva)->toBe(0)
        ->and($secondProduct->fresh()->iva)->toBe(0)
        ->and($otherCompanyProduct->fresh()->iva)->toBe(4);
});

test('el admin también necesita confirmar para pasar una empresa a negocio popular', function () {
    [$company, $product] = regimeChangeCompany();
    regimeChangeActingAsAdmin();

    $this->putJson(route('admin.companies.update', $company), ['rimpe' => Company::RIMPE_NEGOCIO_POPULAR])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('confirm_iva_change');

    $this->putJson(route('admin.companies.update', $company), [
        'rimpe' => Company::RIMPE_NEGOCIO_POPULAR,
        'confirm_iva_change' => 'true',
    ])->assertOk();

    expect($company->fresh()->rimpe)->toBe(Company::RIMPE_NEGOCIO_POPULAR)
        ->and($product->fresh()->iva)->toBe(0);
});

test('cambios de régimen que no pasan a negocio popular no tocan los productos', function (int $from, int $to) {
    [$company, $product] = regimeChangeCompany($from);
    regimeChangeActingAsClient($company);

    $this->putJson(route('settings.company.update'), ['rimpe' => $to])->assertOk();

    expect($company->fresh()->rimpe)->toBe($to)
        ->and($product->fresh()->iva)->toBe(4);
})->with([
    'general a rimpe emprendedor' => [0, 1],
    'negocio popular a general' => [Company::RIMPE_NEGOCIO_POPULAR, 0],
    'negocio popular sin cambio' => [Company::RIMPE_NEGOCIO_POPULAR, Company::RIMPE_NEGOCIO_POPULAR],
]);
