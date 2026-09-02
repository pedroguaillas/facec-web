<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Product\Product;
use App\Models\User;
use App\Models\UserType;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Excel as ExcelType;
use Maatwebsite\Excel\Facades\Excel;

function productActingAsCompanyUser(array $companyAttributes = []): Company
{
    $userType = UserType::firstOrCreate(['type' => 'client']);

    $company = Company::factory()->create($companyAttributes);
    $user = User::factory()->create(['user_type_id' => $userType->id]);
    CompanyUser::create(['user_id' => $user->id, 'level' => 1, 'level_id' => $company->id]);

    test()->actingAs($user);

    DB::table('iva_taxes')->insertOrIgnore([
        ['code' => 2, 'percentage' => 12, 'state' => 'active'],
        ['code' => 5, 'percentage' => 5, 'state' => 'active'],
    ]);

    return $company;
}

function productsUploadFile(array $rows): UploadedFile
{
    $export = new class($rows) implements FromArray, WithHeadings
    {
        public function __construct(private readonly array $rows) {}

        public function array(): array
        {
            return $this->rows;
        }

        public function headings(): array
        {
            return ['Código', 'Código Auxiliar', 'Tipo', 'Nombre', 'Precio', 'IVA', 'ICE', 'Stock'];
        }
    };

    $binary = Excel::raw($export, ExcelType::XLSX);
    $path = tempnam(sys_get_temp_dir(), 'products').'.xlsx';
    file_put_contents($path, $binary);

    return new UploadedFile($path, 'productos.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
}

test('export descarga un xlsx con los productos de la sucursal', function () {
    $company = productActingAsCompanyUser();
    $branch = Branch::factory()->for($company)->create();
    Product::factory()->for($branch)->create(['code' => 'P001', 'name' => 'Producto Uno']);

    $response = $this->get(route('products.export'));

    $response->assertOk();
    $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
});

test('import crea productos válidos desde el xlsx', function () {
    $company = productActingAsCompanyUser();
    $branch = Branch::factory()->for($company)->create();

    $file = productsUploadFile([
        ['P100', null, 1, 'Producto Importado', 10.5, 2, null, 5],
    ]);

    $response = $this->post(route('products.import'), ['file' => $file]);

    $response->assertOk()->assertJson(['success' => true]);

    expect(Product::where('branch_id', $branch->id)->where('code', 'P100')->exists())->toBeTrue();
});

test('import rechaza filas con código duplicado en la sucursal', function () {
    $company = productActingAsCompanyUser();
    $branch = Branch::factory()->for($company)->create();
    Product::factory()->for($branch)->create(['code' => 'DUP']);

    $file = productsUploadFile([
        ['DUP', null, 1, 'Producto Duplicado', 10, 2, null, 5],
    ]);

    $response = $this->post(route('products.import'), ['file' => $file]);

    $response->assertOk()->assertJson(['success' => false]);
    expect($response->json('failures.0.attribute'))->toBe('codigo');
});

test('import exige código auxiliar cuando el IVA es 5%', function () {
    $company = productActingAsCompanyUser();
    Branch::factory()->for($company)->create();

    $file = productsUploadFile([
        ['P200', null, 1, 'Producto IVA 5', 10, 5, null, null],
    ]);

    $response = $this->post(route('products.import'), ['file' => $file]);

    $response->assertOk()->assertJson(['success' => false]);
    expect($response->json('failures.0.attribute'))->toBe('codigo_auxiliar');
});

test('import no exige código auxiliar en productos tipo servicio aunque la empresa sea de transporte', function () {
    $company = productActingAsCompanyUser(['transport' => true]);
    Branch::factory()->for($company)->create();

    $file = productsUploadFile([
        ['P300', null, 2, 'Servicio Transporte', 10, 2, null, null],
    ]);

    $response = $this->post(route('products.import'), ['file' => $file]);

    $response->assertOk()->assertJson(['success' => true]);
});
