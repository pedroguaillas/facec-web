<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Order\Order;
use App\Models\Shop\Shop;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserType;
use App\Models\VoucherType;
use App\Services\Dashboard\AdminDashboardService;
use App\StaticClasses\PlanTypes;
use App\StaticClasses\VoucherStates;
use App\StaticClasses\VoucherTypes;
use Illuminate\Support\Facades\Cache;

function dashboardAdmin(): void
{
    test()->actingAs(User::factory()->create(['user_type_id' => UserType::firstOrCreate(['type' => 'admin'])->id]));
}

function dashboardOrder(Branch $branch, string $state, array $attributes = []): Order
{
    return Order::factory()->create(['branch_id' => $branch->id, 'state' => $state, 'date' => now()->format('Y-m-d'), ...$attributes]);
}

test('solo el admin ve el tablero', function () {
    test()->actingAs(User::factory()->create(['user_type_id' => UserType::firstOrCreate(['type' => 'client'])->id]));

    test()->getJson(route('admin.dashboard'))->assertForbidden();
});

test('resume los comprobantes electrónicos del mes, los atascados y los motivos de rechazo', function () {
    dashboardAdmin();
    $branch = Branch::factory()->create();
    $typeId = fn (string $slug) => VoucherType::where('slug', $slug)->value('id');

    dashboardOrder($branch, VoucherStates::AUTHORIZED);
    dashboardOrder($branch, VoucherStates::REJECTED, ['extra_detail' => 'CLAVE ACCESO REGISTRADA']);
    dashboardOrder($branch, VoucherStates::REJECTED, ['extra_detail' => 'CLAVE ACCESO REGISTRADA']);
    $stuck = dashboardOrder($branch, VoucherStates::RETURNED);
    $stuck->forceFill(['created_at' => now()->subDays(2)])->save();
    // Atascado de hace más de 30 días: fuera de la ventana.
    dashboardOrder($branch, VoucherStates::SAVED, ['date' => now()->subDays(40)->format('Y-m-d')])->forceFill(['created_at' => now()->subDays(40)])->save();
    // No electrónicos: no cuentan.
    dashboardOrder($branch, VoucherStates::AUTHORIZED, ['voucher_type_id' => $typeId(VoucherTypes::VENTA_NOTA_VENTA)]);
    Shop::factory()->create(['branch_id' => $branch->id, 'state' => VoucherStates::AUTHORIZED]);
    // Liquidación autorizada y retención rechazada.
    Shop::factory()->create(['branch_id' => $branch->id, 'state' => VoucherStates::AUTHORIZED, 'voucher_type_id' => $typeId(VoucherTypes::COMPRA_LIQUIDACION_COMPRA)]);
    Shop::factory()->create(['branch_id' => $branch->id, 'state' => null, 'state_retencion' => VoucherStates::REJECTED, 'date_retention' => now(), 'extra_detail_retention' => 'ERROR RETENCION']);

    $vouchers = test()->getJson(route('admin.dashboard'))->assertOk()->json('vouchers');

    expect($vouchers['month'])->toBe(['authorized' => 2, 'pending' => 1, 'rejected' => 3])
        ->and($vouchers['stuck'])->toBe(1)
        ->and($vouchers['top_errors'][0])->toBe(['message' => 'CLAVE ACCESO REGISTRADA', 'count' => 2])
        ->and($vouchers['top_errors'][1])->toBe(['message' => 'ERROR RETENCION', 'count' => 1])
        ->and($vouchers['stuck_companies'])->toHaveCount(1)
        ->and($vouchers['stuck_companies'][0])->toMatchArray(['id' => $branch->company_id, 'pending' => 1])
        ->and($vouchers['by_day'])->toHaveCount(30)
        ->and(end($vouchers['by_day']))->toBe(['date' => now()->format('Y-m-d'), 'authorized' => 2, 'rejected' => 3]);
});

test('resume suscripciones, renovaciones, firmas y contribuyentes', function () {
    dashboardAdmin();
    $renewing = Company::factory()->create(['sign_valid_to' => now()->addDays(5)]);
    Subscription::factory()->for($renewing)->create(['plan' => PlanTypes::FINANCIERO, 'started_at' => now()->subMonth(), 'ended_at' => now()->addDays(10), 'amount' => 30]);
    $expired = Company::factory()->create(['sign_valid_to' => now()->subDay()]);
    Subscription::factory()->for($expired)->create(['started_at' => now()->subMonths(2), 'ended_at' => now()->subDays(5)]);
    $renewed = Company::factory()->create();
    Subscription::factory()->for($renewed)->create(['started_at' => now()->subYear(), 'ended_at' => now()->addDays(3)]);
    Subscription::factory()->for($renewed)->create(['plan' => PlanTypes::FACTURADOR, 'started_at' => now()->addDays(4), 'ended_at' => now()->addYear(), 'amount' => 20]);

    $dashboard = test()->getJson(route('admin.dashboard'))->assertOk()->json();

    expect($dashboard['subscriptions']['renewals'])->toHaveCount(1)
        ->and($dashboard['subscriptions']['renewals'][0])->toMatchArray(['id' => $renewing->id, 'plan' => PlanTypes::FINANCIERO, 'days_left' => 10])
        ->and($dashboard['subscriptions']['expired_recent'])->toBe(1)
        ->and(collect($dashboard['subscriptions']['by_plan'])->pluck('count', 'plan')->all())
        ->toEqual(Company::with('subscription')->get()->pluck('subscription.plan')->filter()->countBy()->all())
        ->and((float) $dashboard['subscriptions']['revenue_month'])->toBe(50.0)
        ->and($dashboard['signatures']['expired'])->toBe(1)
        ->and(collect($dashboard['signatures']['expiring'])->pluck('id')->all())->toBe([$renewing->id])
        ->and($dashboard['companies']['total'])->toBe(Company::count())
        ->and($dashboard['companies']['new_month'])->toBe(Company::where('created_at', '>=', now()->startOfMonth())->count())
        ->and($dashboard['companies']['by_month'])->toHaveCount(6)
        ->and(array_sum($dashboard['companies']['activity']))->toBe(Company::count());
});

test('el tablero se cachea unos minutos', function () {
    dashboardAdmin();

    test()->getJson(route('admin.dashboard'))->assertOk();

    expect(Cache::has(AdminDashboardService::CACHE_KEY))->toBeTrue();
});
