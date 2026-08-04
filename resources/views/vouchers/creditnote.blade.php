@extends('vouchers.theme.voucher')

@section('body')
    <tr>
        <td colspan="2">
            <div style="padding-top: .5em;">
                <table class="table-bordered">
                    <tbody>
                        <tr>
                            <td class="relleno-nc" colspan="3"><strong>Razón Social / Nombres Y Apellidos:</strong>
                                {{ $movement->name }}</td>
                            <td class="align-middle"><strong>Identificación:</strong> {{ $movement->identication }}</td>
                        </tr>
                        <tr>
                            <td class="relleno-nc" colspan="4"><strong>Fecha de Emisión:</strong>
                                {{ date("d/m/Y", strtotime($movement->date)) }}</td>
                        </tr>
                        <tr>
                            <th style="padding-top: 0;" class="relleno-nc" colspan="4">
                                _________________________________________________________________________________________________________
                            </th>
                        </tr>
                        <tr>
                            <td class="relleno-nc" colspan="2"><strong>Comprobante que se modifica:</strong></td>
                            <td class="align-middle">FACTURA</td>
                            <td class="align-middle">{{ $movement->serie_order }}</td>
                        </tr>
                        <tr>
                            <td class="relleno-nc" colspan="2"><strong>Fecha Emisión (Comprobante a modificar):</strong>
                            </td>
                            <td class="align-middle" colspan="2">{{ date("d/m/Y", strtotime($movement->date_order)) }}
                            </td>
                        </tr>
                        <tr>
                            <td class="relleno-nc" colspan="2"><strong>Razón de Modificación:</strong></td>
                            <td class="align-middle" colspan="2">{{ $movement->reason }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </td>
    </tr>
    <tr>
        <td colspan="2">
            <table style="margin-top: .5em; border-radius: 0;" class="table-rounded">
                <tbody>
                    <tr style="background-color: #e6e6e6;">
                        <th style="width: 5em; background-color: #e6e6e6;">Cod. Principal</th>
                        <th style="width: 4em; background-color: #e6e6e6;">Cant.</th>
                        <th style="background-color: #e6e6e6;">Descripción</th>
                        <th style="width: 5em; background-color: #e6e6e6;">Precio Unitario</th>
                        @if($enabledDiscount)
                            <th style="width: 5em; background-color: #e6e6e6;">Descuento</th>
                        @endif
                        <th style="width: 5em; background-color: #e6e6e6;">Subtotal</th>
                    </tr>
                </tbody>
            </table>
        </td>
    </tr>
    @foreach($movement_items as $item)
        <tr>
            <td colspan="2">
                <table style="width: 100%;" class="table-collapse">
                    <tbody>
                        <tr>
                            <td style="text-align: center; width: 5em;">{{ $item->code }}</td>
                            <td style="text-align: center; width: 4em;">{{ floatval($item->quantity) }}</td>
                            <td>{{ $item->name }}</td>
                            <td style="text-align: right; width: 5em;">{{ number_format($item->price, $company->decimal) }}</td>
                            @if($enabledDiscount)
                                <td style="text-align: right; width: 5em;">{{ number_format($item->discount, 2) }}</td>
                            @endif
                            <td style="text-align: right; width: 5em;">{{ number_format($item->quantity * $item->price, 2) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </td>
        </tr>
    @endforeach
@endsection

@section('footer')
    <table style="width: 100%;">
        <tbody>
            <tr>
                <td style="width: 460px;">
                    <!-- @include('vouchers.theme.payment') -->
                </td>
                <td>@include('vouchers.theme.total')</td>
            </tr>
        </tbody>
    </table>
@endsection
