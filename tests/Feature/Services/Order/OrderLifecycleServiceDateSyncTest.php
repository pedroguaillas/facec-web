<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Order\Order;
use App\Services\Order\OrderLifecycleService;
use App\StaticClasses\VoucherStates;

test('process() sincroniza $order->date en el mismo objeto antes de firmar/enviar', function () {
    $company = Company::factory()->create(['active_voucher' => true]);
    $branch = Branch::factory()->for($company)->create();
    $order = Order::factory()->create([
        'branch_id' => $branch->id,
        'state' => VoucherStates::SAVED,
        'date' => now()->subDay()->toDateString(), // cruzó medianoche
    ]);

    try {
        app(OrderLifecycleService::class)->process($order, $company);
    } catch (Throwable) {
        // buildXml()/InvoiceBuilder puede fallar más adelante por datos de fixture
        // incompletos (customer/items reales) — no es lo que se está probando acá.
        // Lo que importa: la corrección de fecha ocurre ANTES, sobre el mismo $order,
        // no solo en una copia descartable dentro de buildXml().
    }

    expect($order->date)->toBe(now()->toDateString());
});
