<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\EmisionPoint;
use App\Models\Provider;
use App\Models\Shop\Shop;
use App\Models\Shop\ShopRetentionItem;
use App\Models\User;
use App\Models\UserType;
use App\Models\VoucherType;
use App\StaticClasses\PaymentTypes;
use App\StaticClasses\VoucherTypes;
use Illuminate\Support\Facades\DB;

/**
 * @return array{0: Branch, 1: Provider}
 */
function overduePayableActingAsCompany(): array
{
    $userType = UserType::firstOrCreate(['type' => 'client']);
    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create();
    $user = User::factory()->create(['user_type_id' => $userType->id]);
    CompanyUser::create(['user_id' => $user->id, 'level' => 1, 'level_id' => $company->id]);
    test()->actingAs($user);

    $provider = Provider::factory()->create(['branch_id' => $branch->id, 'type_identification' => 'ruc']);

    return [$branch, $provider];
}

function overdueShopPayload(Branch $branch, Provider $provider, float $total, string $dueDate, array $overrides = []): array
{
    $emisionPoint = EmisionPoint::factory()->for($branch)->create();

    return array_merge([
        'date' => now()->subDays(40)->format('Y-m-d'),
        'voucher_type_id' => VoucherType::where('slug', VoucherTypes::COMPRA_FACTURA)->value('id'),
        'provider_id' => $provider->id,
        'point_id' => $emisionPoint->id,
        'serie' => '001-001-000123',
        'total' => $total,
        'due_date' => $dueDate,
        'payment_type' => PaymentTypes::CREDITO,
    ], $overrides);
}

test('compra vencida con saldo pendiente aparece en el reporte, agrupada por proveedor', function () {
    [$branch, $provider] = overduePayableActingAsCompany();

    $vencida = overdueShopPayload($branch, $provider, 100, now()->subDays(7)->format('Y-m-d'));
    test()->postJson(route('shops.store'), $vencida)->assertCreated();

    $response = test()->getJson(route('providers.overdue'));

    $response->assertOk();
    expect((float) $response->json('total_overdue'))->toBe(100.0)
        ->and($response->json('parties'))->toHaveCount(1)
        ->and($response->json('parties.0.provider_id'))->toBe($provider->id)
        ->and($response->json('parties.0.documents.0.days_overdue'))->toBe(7);
});

test('compra a crédito sin vencer no aparece en el reporte', function () {
    [$branch, $provider] = overduePayableActingAsCompany();

    $vigente = overdueShopPayload($branch, $provider, 100, now()->addDays(7)->format('Y-m-d'));
    test()->postJson(route('shops.store'), $vigente)->assertCreated();

    $response = test()->getJson(route('providers.overdue'));

    $response->assertOk();
    expect($response->json('parties'))->toHaveCount(0);
});

test('compra vencida pero ya pagada no aparece en el reporte', function () {
    [$branch, $provider] = overduePayableActingAsCompany();

    $vencida = overdueShopPayload($branch, $provider, 100, now()->subDays(3)->format('Y-m-d'));
    test()->postJson(route('shops.store'), $vencida)->assertCreated();

    $shop = Shop::first();
    $shop->update(['paid' => $shop->total]);

    $response = test()->getJson(route('providers.overdue'));

    $response->assertOk();
    expect($response->json('parties'))->toHaveCount(0);
});

test('el saldo vencido descuenta la retención y la compra pagada neta de retención no aparece', function () {
    [$branch, $provider] = overduePayableActingAsCompany();
    DB::table('taxes')->insertOrIgnore(['code' => '303', 'conception' => 'Retención renta', 'porcentage' => 1, 'type' => 'renta']);

    test()->postJson(route('shops.store'), overdueShopPayload($branch, $provider, 100, now()->subDays(3)->format('Y-m-d')))->assertCreated();
    test()->postJson(route('shops.store'), overdueShopPayload($branch, $provider, 50, now()->subDays(3)->format('Y-m-d'), ['serie' => '001-001-000124']))->assertCreated();
    [$paidShop, $pendingShop] = Shop::orderBy('id')->get();

    foreach ([$paidShop, $pendingShop] as $shop) {
        ShopRetentionItem::create(['shop_id' => $shop->id, 'code' => '1', 'tax_code' => '303', 'base' => $shop->total, 'porcentage' => 2, 'value' => $shop->total * 0.02]);
    }
    $paidShop->update(['paid' => 98]);
    $pendingShop->update(['paid' => 10]);

    $response = test()->getJson(route('providers.overdue'))->assertOk();

    expect($response->json('parties.0.documents'))->toHaveCount(1)
        ->and($response->json('parties.0.documents.0.shop_id'))->toBe($pendingShop->id)
        ->and((float) $response->json('parties.0.documents.0.pending'))->toBe(39.0)
        ->and((float) $response->json('total_overdue'))->toBe(39.0);
});
