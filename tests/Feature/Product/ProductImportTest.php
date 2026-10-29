<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Inventory;
use App\Models\Product\Product;
use App\Models\Product\Warehouse;
use App\Models\User;
use App\Models\UserType;
use App\StaticClasses\InventoryMovementTypes;
use Database\Seeders\IvaTaxSeed;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * @param  list<list<mixed>>  $rows
 */
function productImportFile(array $rows): UploadedFile
{
    $spreadsheet = new Spreadsheet;
    $spreadsheet->getActiveSheet()->fromArray(source: [
        ['Código', 'Código Auxiliar', 'Tipo', 'Nombre', 'Precio', 'IVA', 'ICE', 'Stock', 'Costo'],
        ...$rows,
    ], strictNullComparison: true);

    $path = sys_get_temp_dir().'/'.uniqid('products', true).'.xlsx';
    (new Xlsx($spreadsheet))->save($path);

    return new UploadedFile($path, 'productos.xlsx', null, null, true);
}

function productImportCompany(bool $inventory = true): Branch
{
    test()->seed(IvaTaxSeed::class);

    $userType = UserType::firstOrCreate(['type' => 'client']);
    $company = Company::factory()->create(['inventory' => $inventory]);
    $branch = Branch::factory()->for($company)->create();
    Warehouse::factory()->create(['branch_id' => $branch->id, 'name' => 'Principal']);
    $user = User::factory()->create(['user_type_id' => $userType->id]);
    CompanyUser::create(['user_id' => $user->id, 'level' => 1, 'level_id' => $company->id]);
    test()->actingAs($user);

    return $branch;
}

test('importar con inventario activo registra el saldo inicial con el costo del excel', function () {
    productImportCompany();

    test()->post(route('products.import'), ['file' => productImportFile([
        ['P1', null, 1, 'Producto con costo', 10, 0, null, 5, 2.5],
        ['P2', null, 1, 'Producto sin costo', 10, 0, null, 3, null],
        ['S1', null, 2, 'Servicio', 10, 0, null, 4, 7],
    ])])->assertOk()->assertJsonPath('success', true);

    $withCost = Product::where('code', 'P1')->first();
    $opening = Inventory::where('product_id', $withCost->id)->sole();

    expect($opening->type)->toBe(InventoryMovementTypes::OPENING_BALANCE)
        ->and((float) $opening->unit_cost)->toBe(2.5)
        ->and($opening->average_cost)->toBe(2.5)
        ->and($opening->balance_value)->toBe(12.5)
        ->and($withCost->fresh()->stock)->toBe(5.0);

    $withoutCost = Product::where('code', 'P2')->first();
    expect(Inventory::where('product_id', $withoutCost->id)->sole()->average_cost)->toBe(0.0);

    $service = Product::where('code', 'S1')->first();
    expect($service->stock)->toBeNull()
        ->and(Inventory::where('product_id', $service->id)->exists())->toBeFalse();
});

test('importar rechaza un costo negativo', function () {
    productImportCompany();

    test()->post(route('products.import'), ['file' => productImportFile([
        ['P1', null, 1, 'Producto', 10, 0, null, 5, -1],
    ])])->assertOk()
        ->assertJsonPath('success', false)
        ->assertJsonPath('failures.0.attribute', 'costo');

    expect(Product::where('code', 'P1')->exists())->toBeFalse();
});

test('importar sin inventario activo no genera movimientos', function () {
    productImportCompany(inventory: false);

    test()->post(route('products.import'), ['file' => productImportFile([
        ['P1', null, 1, 'Producto', 10, 0, null, 5, 2.5],
    ])])->assertOk()->assertJsonPath('success', true);

    expect(Product::where('code', 'P1')->first()->stock)->toBe(5.0)
        ->and(Inventory::count())->toBe(0);
});

test('exportar conserva el IVA 0 para poder reimportar', function () {
    $branch = productImportCompany();
    Product::factory()->create(['branch_id' => $branch->id, 'code' => 'P0', 'type_product' => Product::TYPE_PRODUCT, 'iva' => 0]);

    $response = test()->get(route('products.export'))->assertOk();
    $sheet = IOFactory::load($response->getFile()->getPathname())->getActiveSheet();

    expect($sheet->getCell('A2')->getValue())->toBe('P0')
        ->and($sheet->getCell('F2')->getValue())->toBe(0);
});
