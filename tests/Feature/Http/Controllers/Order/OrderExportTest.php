<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Customer;
use App\Models\Order\Order;
use App\Models\User;
use App\Models\UserType;

function orderExportActingAsCompanyUser(array $companyAttributes = []): Company
{
    $userType = UserType::firstOrCreate(['type' => 'client']);

    $company = Company::factory()->create($companyAttributes);
    $user = User::factory()->create(['user_type_id' => $userType->id]);
    CompanyUser::create(['user_id' => $user->id, 'level' => 1, 'level_id' => $company->id]);

    test()->actingAs($user);

    return $company;
}

test('orders.export descarga un xlsx con las órdenes del mes de la sucursal', function () {
    $company = orderExportActingAsCompanyUser();
    $branch = Branch::factory()->for($company)->create();
    $customer = Customer::factory()->create(['branch_id' => $branch->id]);

    Order::factory()->create([
        'branch_id' => $branch->id,
        'customer_id' => $customer->id,
        'date' => '2026-08-15',
    ]);

    // Fuera del mes pedido: no debe aparecer, pero tampoco debe romper la consulta.
    Order::factory()->create([
        'branch_id' => $branch->id,
        'customer_id' => $customer->id,
        'date' => '2026-07-15',
    ]);

    $response = $this->get(route('orders.export', ['yearMonth' => '2026-08']));

    $response->assertOk();
    expect($response->headers->get('content-type'))
        ->toBe('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
});

test('orders.export rechaza un formato de mes inválido', function () {
    orderExportActingAsCompanyUser();

    $response = $this->getJson('/api/orders/export/agosto-2026');

    $response->assertStatus(404);
});
