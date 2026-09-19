    <table class="table-bordered">
        <tbody>
            <tr style="font-size: 16px;">
                <td style="padding: .5em;">R.U.C.:</td>
                <td class="align-middle">{{ $company->ruc }}</td>
            </tr>
            <tr>
                <th style="font-size: 16px; letter-spacing: 2px;" class="align-middle" colspan="2">
                    @switch($voucherTypeSlug)
                    @case(\App\StaticClasses\VoucherTypes::VENTA_FACTURA)
                    @case(\App\StaticClasses\VoucherTypes::COMPRA_FACTURA)
                    FACTURA
                    @break

                    @case(\App\StaticClasses\VoucherTypes::COMPRA_LIQUIDACION_COMPRA)
                    LIQUIDACIÓN EN COMPRA
                    @break

                    @case(\App\StaticClasses\VoucherTypes::VENTA_NOTA_CREDITO)
                    NOTA DE CRÉDITO
                    @break

                    @case(\App\StaticClasses\VoucherTypes::VENTA_NOTA_VENTA)
                    @case(\App\StaticClasses\VoucherTypes::COMPRA_NOTA_VENTA)
                    NOTA DE VENTA
                    @break

                    @case(\App\StaticClasses\VoucherTypes::VENTA_PROFORMA)
                    PROFORMA
                    @break

                    @case(\App\StaticClasses\VoucherTypes::COMPRA_NOTA_DEBITO)
                    NOTA DE DÉBITO
                    @break

                    @case(\App\StaticClasses\VoucherTypes::GUIA_REMISION)
                    GUIA DE REMISIÓN
                    @break

                    @case(\App\StaticClasses\VoucherTypes::RETENCION)
                    COMPROBANTE DE RETENCIÓN
                    @break

                    @default
                    OTROS
                    @endswitch
                </th>
            </tr>
            <tr>
                <td class="relleno">No.</td>
                <td class="align-middle">{{ $movement->serie }}</td>
            </tr>
            @if($isElectronic)
            <tr>
                <td class="relleno" colspan="2">NÚMERO DE AUTORIZACIÓN</td>
            </tr>
            <tr>
                <td class="relleno" colspan="2">{{ $movement->authorization }}</td>
            </tr>
            <tr>
                <td class="relleno">FECHA Y HORA DE AUTORIZACIÓN: </td>
                <td class="align-middle">
                    {{ $movement->autorized !== null ? date( "d/m/Y H:i:s.000", strtotime( $movement->autorized ) ) : null }}
                </td>
            </tr>
            <tr>
                <td class="relleno">AMBIENTE:</td>
                <td class="align-middle">{{ $movement->xml!== null ? (int)substr($movement->xml, -30, 1) === 1 ? 'PRUEBAS' : 'PRODUCCION' : null }}</td>
            </tr>
            <tr>
                <td class="relleno">EMISIÓN:</td>
                <td class="align-middle">NORMAL</td>
            </tr>
            <tr>
                <td class="relleno" colspan="2">CLAVE DE ACCESO</td>
            </tr>
            <tr>
                <td class="relleno" colspan="2">{{ substr($movement->xml ?? '', -53, 49) }}</td>
            </tr>
            @else
            <tr>
                <td class="relleno" colspan="2" style="color: #888;">Documento no electrónico — sin autorización SRI</td>
            </tr>
            @endif
        </tbody>
    </table>