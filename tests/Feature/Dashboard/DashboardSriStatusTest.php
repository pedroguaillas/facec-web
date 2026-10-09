<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Order\Order;
use App\Models\Shop\Shop;
use App\Models\User;
use App\Models\UserType;
use App\Models\VoucherType;
use App\StaticClasses\VoucherStates;
use App\StaticClasses\VoucherTypes;

function dashboardBranch(): Branch
{
    $userType = UserType::firstOrCreate(['type' => 'client']);
    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create();
    $user = User::factory()->create(['user_type_id' => $userType->id]);
    CompanyUser::create(['user_id' => $user->id, 'level' => 1, 'level_id' => $company->id]);
    test()->actingAs($user);

    return $branch;
}

test('el dashboard cuenta comprobantes pendientes y rechazados en el SRI', function () {
    $branch = dashboardBranch();
    $voucherTypeId = fn (string $slug) => VoucherType::where('slug', $slug)->value('id');

    Order::factory()->create(['branch_id' => $branch->id, 'state' => VoucherStates::SIGNED, 'date' => '2026-09-15']);
    Order::factory()->create(['branch_id' => $branch->id, 'state' => VoucherStates::IN_PROCESS, 'date' => '2026-10-01']);
    Order::factory()->create(['branch_id' => $branch->id, 'state' => VoucherStates::RETURNED]);
    Order::factory()->create(['branch_id' => $branch->id, 'state' => VoucherStates::AUTHORIZED]);
    Order::factory()->create(['branch_id' => $branch->id,
        'state' => VoucherStates::SAVED,
        'voucher_type_id' => $voucherTypeId(VoucherTypes::VENTA_PROFORMA),
    ]);

    Shop::factory()->create(['branch_id' => $branch->id,
        'voucher_type_id' => $voucherTypeId(VoucherTypes::COMPRA_LIQUIDACION_COMPRA),
        'state' => VoucherStates::REJECTED,
    ]);
    Shop::factory()->create(['branch_id' => $branch->id,
        'state' => VoucherStates::SAVED,
        'state_retencion' => VoucherStates::SENDED,
        'date_retention' => '2026-08-20',
    ]);

    // Otra empresa: no debe contarse.
    Order::factory()->create(['state' => VoucherStates::RETURNED]);

    $this->getJson(route('dashboard'))
        ->assertOk()
        ->assertJsonPath('sri', [
            'pending' => 3,
            'rejected' => 2,
            'oldest_pending_date' => '2026-08-20',
            'by_type' => [
                'orders' => ['pending' => 2, 'rejected' => 1],
                'shops' => ['pending' => 0, 'rejected' => 1],
                'retentions' => ['pending' => 1, 'rejected' => 0],
                'referralguides' => ['pending' => 0, 'rejected' => 0],
            ],
        ]);
});

test('el dashboard informa si la empresa tiene certificado cargado', function () {
    $branch = dashboardBranch();
    $branch->company->update(['cert_dir' => null, 'sign_valid_to' => null]);

    $this->getJson(route('dashboard'))->assertOk()->assertJsonPath('hasCert', false);

    $branch->company->update(['cert_dir' => 'empresa.p12', 'sign_valid_to' => null]);
    auth()->user()->unsetRelation('company');

    $this->getJson(route('dashboard'))
        ->assertOk()
        ->assertJsonPath('hasCert', true)
        ->assertJsonPath('certExpiration', null);
});

test('el dashboard devuelve sri en cero sin comprobantes pendientes', function () {
    dashboardBranch();

    $this->getJson(route('dashboard'))
        ->assertOk()
        ->assertJsonPath('sri.pending', 0)
        ->assertJsonPath('sri.rejected', 0)
        ->assertJsonPath('sri.oldest_pending_date', null)
        ->assertJsonPath('sri.by_type.orders', ['pending' => 0, 'rejected' => 0])
        ->assertJsonPath('sri.by_type.referralguides', ['pending' => 0, 'rejected' => 0]);
});

test('el listado de ventas filtra por uno o varios estados', function () {
    $branch = dashboardBranch();

    Order::factory()->create(['branch_id' => $branch->id, 'state' => VoucherStates::RETURNED]);
    Order::factory()->create(['branch_id' => $branch->id, 'state' => VoucherStates::REJECTED]);
    Order::factory()->create(['branch_id' => $branch->id, 'state' => VoucherStates::AUTHORIZED]);

    $this->getJson(route('orders.index', ['state' => VoucherStates::RETURNED]))
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->getJson(route('orders.index', ['state' => VoucherStates::RETURNED.','.VoucherStates::REJECTED]))
        ->assertOk()
        ->assertJsonCount(2, 'data');
});
