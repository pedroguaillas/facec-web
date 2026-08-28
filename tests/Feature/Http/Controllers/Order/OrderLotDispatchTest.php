<?php

use App\Jobs\ProcessVoucherJob;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Customer;
use App\Models\EmisionPoint;
use App\Models\Order\Order;
use App\Models\Product\Product;
use App\Models\User;
use App\Models\UserType;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

function lotUploadFile(array $rows): UploadedFile
{
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();

    // Fila 1: encabezado, descartado por OrderLotController::getData().
    $sheet->fromArray(['identificacion', 'nombre', 'codigo', 'cantidad', 'precio'], null, 'A1');

    foreach ($rows as $i => $row) {
        $rowNumber = $i + 2;
        // identificacion y codigo forzados a texto: si Excel los detecta como numéricos,
        // la comparación estricta (===) contra el string de la BD nunca matchea.
        $sheet->getCell("A{$rowNumber}")->setValueExplicit((string) $row[0], DataType::TYPE_STRING);
        $sheet->setCellValue("B{$rowNumber}", $row[1]);
        $sheet->getCell("C{$rowNumber}")->setValueExplicit((string) $row[2], DataType::TYPE_STRING);
        $sheet->setCellValue("D{$rowNumber}", $row[3]);
        $sheet->setCellValue("E{$rowNumber}", $row[4]);
    }

    $path = tempnam(sys_get_temp_dir(), 'lot').'.xlsx';
    (new Xlsx($spreadsheet))->save($path);

    return new UploadedFile($path, 'lote.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
}

test('orders.lot.store encola un ProcessVoucherJob por cada comprobante del lote', function () {
    Queue::fake();

    $userType = UserType::firstOrCreate(['type' => 'client']);
    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create();
    $user = User::factory()->create(['user_type_id' => $userType->id]);
    CompanyUser::create(['user_id' => $user->id, 'level' => 1, 'level_id' => $company->id]);
    test()->actingAs($user);

    DB::table('iva_taxes')->insertOrIgnore([
        ['code' => 2, 'percentage' => 12, 'state' => 'active'],
    ]);

    EmisionPoint::factory()->for($branch)->create(['point' => 1]);

    $customerA = Customer::factory()->create(['branch_id' => $branch->id, 'identication' => '1111111111']);
    $customerB = Customer::factory()->create(['branch_id' => $branch->id, 'identication' => '2222222222']);
    $product = Product::factory()->create(['branch_id' => $branch->id, 'code' => 'PROD-1', 'iva' => 2]);

    $file = lotUploadFile([
        [$customerA->identication, 'Cliente A', $product->code, 2, 5.00],
        [$customerB->identication, 'Cliente B', $product->code, 1, 10.00],
    ]);

    $response = $this->post(route('orders.lot.store'), ['lot' => $file]);

    $response->assertOk();

    Queue::assertPushed(ProcessVoucherJob::class, 2);
    Queue::assertPushed(ProcessVoucherJob::class, function (ProcessVoucherJob $job) use ($company) {
        return (new ReflectionProperty($job, 'voucherType'))->getValue($job) === 'order'
            && (new ReflectionProperty($job, 'companyId'))->getValue($job) === $company->id;
    });

    // order_items se insertan en batch (no uno por uno) — confirma que cada fila
    // quedó mapeada al order_id correcto, no cruzada con la otra fila del lote.
    $orderA = Order::where('customer_id', $customerA->id)->first();
    $orderB = Order::where('customer_id', $customerB->id)->first();

    expect($orderA->orderitems)->toHaveCount(1)
        ->and($orderA->orderitems->first()->quantity)->toBe(2.0)
        ->and($orderB->orderitems)->toHaveCount(1)
        ->and($orderB->orderitems->first()->quantity)->toBe(1.0);
});

test('orders.lot.store usa forma de pago 01 para consumidor final, y la de la empresa para el resto', function () {
    Queue::fake();

    $userType = UserType::firstOrCreate(['type' => 'client']);
    $company = Company::factory()->create(['pay_method' => 20]);
    $branch = Branch::factory()->for($company)->create();
    $user = User::factory()->create(['user_type_id' => $userType->id]);
    CompanyUser::create(['user_id' => $user->id, 'level' => 1, 'level_id' => $company->id]);
    test()->actingAs($user);

    DB::table('iva_taxes')->insertOrIgnore([
        ['code' => 2, 'percentage' => 12, 'state' => 'active'],
    ]);

    EmisionPoint::factory()->for($branch)->create(['point' => 1]);

    $consumidorFinal = Customer::factory()->create(['branch_id' => $branch->id, 'identication' => '9999999999999']);
    $customerB = Customer::factory()->create(['branch_id' => $branch->id, 'identication' => '2222222222']);
    $product = Product::factory()->create(['branch_id' => $branch->id, 'code' => 'PROD-1', 'iva' => 2]);

    $file = lotUploadFile([
        [$consumidorFinal->identication, 'Consumidor Final', $product->code, 1, 5.00],
        [$customerB->identication, 'Cliente B', $product->code, 1, 10.00],
    ]);

    $this->post(route('orders.lot.store'), ['lot' => $file])->assertOk();

    expect(Order::where('customer_id', $consumidorFinal->id)->first()->pay_method)->toBe(1)
        ->and(Order::where('customer_id', $customerB->id)->first()->pay_method)->toBe(20);
});
