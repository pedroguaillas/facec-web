<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Order\Order;
use App\Models\Shop\Shop;
use App\Models\User;
use App\Models\UserType;
use App\Models\VoucherType;
use App\StaticClasses\CompanyActivityStatuses;
use App\StaticClasses\VoucherStates;
use App\StaticClasses\VoucherTypes;
use Illuminate\Support\Facades\DB;

/**
 * Empresa con un usuario cuyo token se usó hace `$lastUsedDaysAgo` días
 * (null: nunca entró) y una venta por cada antigüedad de `$orderDaysAgo`.
 *
 * @param  array<int, int>  $orderDaysAgo
 */
function activityCompany(?int $lastUsedDaysAgo, array $orderDaysAgo = []): Company
{
    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create();
    $user = User::factory()->create(['user_type_id' => UserType::firstOrCreate(['type' => 'client'])->id]);
    CompanyUser::create(['user_id' => $user->id, 'level' => 'owner', 'level_id' => $company->id]);

    if ($lastUsedDaysAgo !== null) {
        $user->createToken('web')->accessToken->forceFill(['last_used_at' => now()->subDays($lastUsedDaysAgo)])->save();
    }

    foreach ($orderDaysAgo as $daysAgo) {
        Order::factory()->create(['branch_id' => $branch->id, 'date' => now()->subDays($daysAgo)->format('Y-m-d')]);
    }

    return $company;
}

beforeEach(function () {
    test()->actingAs(User::factory()->create(['user_type_id' => UserType::firstOrCreate(['type' => 'admin'])->id]));
});

test('el listado admin devuelve la actividad y el estado de cada contribuyente', function () {
    $active = activityCompany(2, [5, 40]);
    $riskStale = activityCompany(15, [5]);
    $riskIdle = activityCompany(1, [40]);
    $inactive = activityCompany(null, [5]);
    $never = activityCompany(1);

    $companies = collect(test()->getJson(route('admin.companies.index', ['per_page' => 50]))->assertOk()->json('data'))->keyBy('id');

    expect($companies[$active->id])
        ->toMatchArray([
            'orders_30d' => 1,
            'shops_30d' => 0,
            'first_voucher_at' => now()->subDays(40)->format('Y-m-d'),
            'activity_status' => CompanyActivityStatuses::ACTIVE,
        ])
        ->not->toHaveKey('first_order_at')
        ->and($companies[$active->id]['last_activity_at'])->toStartWith(now()->subDays(2)->format('Y-m-d'))
        ->and($companies[$riskStale->id]['activity_status'])->toBe(CompanyActivityStatuses::RISK)
        ->and($companies[$riskIdle->id]['activity_status'])->toBe(CompanyActivityStatuses::RISK)
        ->and($companies[$inactive->id]['activity_status'])->toBe(CompanyActivityStatuses::INACTIVE)
        ->and($companies[$inactive->id]['last_activity_at'])->toBeNull()
        ->and($companies[$never->id]['activity_status'])->toBe(CompanyActivityStatuses::NEVER)
        ->and($companies[$never->id]['first_voucher_at'])->toBeNull();
});

test('los comprobantes de 30 días excluyen anulados y proformas, y cuentan compras', function () {
    $company = activityCompany(1);
    $branch = $company->branches()->first();
    Order::factory()->create(['branch_id' => $branch->id, 'state' => VoucherStates::CANCELED]);
    Order::factory()->create(['branch_id' => $branch->id, 'voucher_type_id' => VoucherType::where('slug', VoucherTypes::VENTA_PROFORMA)->value('id')]);
    Shop::factory()->create(['branch_id' => $branch->id, 'date' => now()->subDays(3)->format('Y-m-d')]);

    $row = collect(test()->getJson(route('admin.companies.index'))->json('data'))->firstWhere('id', $company->id);

    expect($row)->toMatchArray([
        'orders_30d' => 0,
        'shops_30d' => 1,
        'first_voucher_at' => now()->subDays(3)->format('Y-m-d'),
        'activity_status' => CompanyActivityStatuses::ACTIVE,
    ]);
});

test('el filtro activity pagina solo los contribuyentes de ese estado', function () {
    $active = activityCompany(2, [5]);
    $never = activityCompany(null);

    $response = test()->getJson(route('admin.companies.index', ['activity' => CompanyActivityStatuses::NEVER, 'per_page' => 50]))->assertOk();
    $rows = collect($response->json('data'));

    // Los factories crean otras empresas sin comprobantes: también son `never`.
    expect($rows->pluck('id'))->toContain($never->id)->not->toContain($active->id)
        ->and($rows->pluck('activity_status')->unique()->all())->toBe([CompanyActivityStatuses::NEVER])
        ->and($response->json('total'))->toBe($rows->count());
    test()->getJson(route('admin.companies.index', ['activity' => 'otro']))->assertJsonValidationErrors('activity');
});

test('la actividad se calcula con consultas agrupadas, sin N+1', function () {
    $queries = function (int $companies) {
        foreach (range(1, $companies) as $i) {
            activityCompany(1, [2]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        test()->getJson(route('admin.companies.index', ['per_page' => 50]))->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    test()->getJson(route('admin.companies.index'))->assertOk(); // calienta consultas de una sola vez (rol, tipos)

    expect($queries(1))->toBe($queries(4));
});
