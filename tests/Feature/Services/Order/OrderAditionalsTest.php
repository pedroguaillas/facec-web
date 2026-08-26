<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Order\Order;
use App\Models\Order\OrderAditional;
use App\Models\User;
use App\Models\UserType;
use App\Services\Order\OrderStoreService;
use App\Services\Order\OrderUpdateService;

function callProtected(object $object, string $method, array $args): mixed
{
    $reflection = new ReflectionMethod($object, $method);
    $reflection->setAccessible(true);

    return $reflection->invoke($object, ...$args);
}

function actingAsCompanyUser(): Company
{
    $userType = UserType::firstOrCreate(['type' => 'client']);

    $company = Company::factory()->create();
    $user = User::factory()->create(['user_type_id' => $userType->id]);
    CompanyUser::create(['user_id' => $user->id, 'level' => 1, 'level_id' => $company->id]);

    test()->actingAs($user);

    return $company;
}

test('createOrderAditionals agrega el RUC Proveedor fijo aunque no venga nada del frontend', function () {
    $company = actingAsCompanyUser();
    $branch = Branch::factory()->for($company)->create();
    $order = Order::factory()->create(['branch_id' => $branch->id]);

    callProtected(app(OrderStoreService::class), 'createOrderAditionals', [$order, []]);

    $aditionals = OrderAditional::where('order_id', $order->id)->get();

    expect($aditionals)->toHaveCount(1)
        ->and($aditionals->first()->name)->toBe(Order::REQUIRED_ADITIONAL['name'])
        ->and($aditionals->first()->description)->toBe(Order::REQUIRED_ADITIONAL['description']);
});

test('createOrderAditionals conserva los aditionals del frontend y agrega el RUC Proveedor fijo', function () {
    $company = actingAsCompanyUser();
    $branch = Branch::factory()->for($company)->create();
    $order = Order::factory()->create(['branch_id' => $branch->id]);

    callProtected(app(OrderStoreService::class), 'createOrderAditionals', [
        $order,
        [['name' => 'Guía', 'description' => '001-001-123456789']],
    ]);

    $aditionals = OrderAditional::where('order_id', $order->id)->get();

    expect($aditionals)->toHaveCount(2)
        ->and($aditionals->pluck('name'))->toContain('Guía', Order::REQUIRED_ADITIONAL['name']);
});

test('updateOrderAditionals reemplaza los aditionals pero nunca deja de incluir el RUC Proveedor fijo', function () {
    $order = Order::factory()->create();
    OrderAditional::create(['order_id' => $order->id, 'name' => 'Viejo', 'description' => 'dato viejo']);

    callProtected(app(OrderUpdateService::class), 'updateOrderAditionals', [$order, []]);

    $aditionals = OrderAditional::where('order_id', $order->id)->get();

    expect($aditionals)->toHaveCount(1)
        ->and($aditionals->first()->name)->toBe(Order::REQUIRED_ADITIONAL['name'])
        ->and($aditionals->first()->description)->toBe(Order::REQUIRED_ADITIONAL['description']);
});

test('updateOrderAditionals combina lo enviado por el frontend con el RUC Proveedor fijo, sin duplicarlo', function () {
    $order = Order::factory()->create();

    callProtected(app(OrderUpdateService::class), 'updateOrderAditionals', [
        $order,
        [['name' => 'Guía', 'description' => '001-001-123456789']],
    ]);

    $aditionals = OrderAditional::where('order_id', $order->id)->get();

    expect($aditionals)->toHaveCount(2)
        ->and($aditionals->where('name', Order::REQUIRED_ADITIONAL['name']))->toHaveCount(1);
});
