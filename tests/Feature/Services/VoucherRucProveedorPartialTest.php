<?php

test('el partial de RUC Proveedor muestra INFORMACIÓN ADICIONAL con el RUC configurado', function () {
    config(['services.order.ruc_proveedor' => '1234567890001']);

    $html = view('vouchers.theme.rucproveedor')->render();

    expect($html)->toContain('INFORMACIÓN ADICIONAL')
        ->toContain('RUC Proveedor')
        ->toContain('1234567890001');
});
