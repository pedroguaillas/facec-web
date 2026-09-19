<?php

namespace App\Http\Requests\Shop\Concerns;

use App\Models\Provider;
use App\Models\VoucherType;
use App\StaticClasses\VoucherTypes;
use Illuminate\Validation\Validator;

/**
 * Solo la liquidación de compra la emite el contribuyente para un proveedor
 * SIN RUC (persona natural/informal que no puede emitir su propia factura).
 * El resto de tipos de Compras (factura, nota de venta, nota de débito) son
 * documentos que el proveedor emitió él mismo, así que exigen RUC.
 */
trait ValidatesProviderIdentification
{
    private function validateProviderIdentification(Validator $validator, mixed $voucherTypeId, mixed $providerId): void
    {
        if (! $voucherTypeId || ! $providerId) {
            return;
        }

        $voucherType = VoucherType::find($voucherTypeId);
        $provider = Provider::find($providerId);

        if (! $voucherType || ! $provider) {
            return;
        }

        if ($voucherType->slug === VoucherTypes::COMPRA_LIQUIDACION_COMPRA) {
            if (! in_array($provider->type_identification, ['cédula', 'pasaporte'], true)) {
                $validator->errors()->add(
                    'provider_id',
                    'Para liquidación de compra, el proveedor debe tener identificación de cédula o pasaporte (no RUC).'
                );
            }

            return;
        }

        if ($provider->type_identification !== 'ruc') {
            $validator->errors()->add(
                'provider_id',
                'Para este tipo de comprobante, el proveedor debe tener RUC.'
            );
        }
    }
}
