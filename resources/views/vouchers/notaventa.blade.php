@extends('vouchers.theme.voucher')

@section('body')
    <tr>
        <td colspan="2">
            <div style="padding-top: .7em;">
                <table class="table-bordered">
                    <tbody>
                        <tr>
                            <td style="padding: .5em;">Razón Social / Nombres Y Apellidos: {{ $movement->name }}</td>
                            <td class="align-middle">Identificación: {{ $movement->identication }}</td>
                        </tr>
                        <tr>
                            <td class="relleno">Fecha de Emisión: {{ date("d/m/Y", strtotime($movement->date)) }}</td>
                            @if($movement->email)
                                <td class="align-middle">Correo: {{ $movement->email }}</td>
                            @endif
                        </tr>
                        <tr>
                            @if($movement->address)
                            <td class="relleno">Dirección: {{ $movement->address }}</td>
                            @endif
                            @if($movement->phone)
                            <td class="align-middle">Teléfono: {{ $movement->phone }}</td>
                            @endif
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
                        <th style="width: 5em; background-color: #e6e6e6;">Código</th>
                        <th style="width: 4em; background-color: #e6e6e6;">Cant.</th>
                        <th style="background-color: #e6e6e6;">Descripción</th>
                        <th style="width: 5em; background-color: #e6e6e6;">Precio</th>
                        @if($enabledDiscount)
                            <th style="width: 5em; background-color: #e6e6e6;">Descuento</th>
                        @endif
                        <th style="width: 5em; background-color: #e6e6e6;">Subtotal</th>
                    </tr>
                </tbody>
            </table>
        </td>
    </tr>
    <!-- No quitar esta tabla porque si no no hace salto de pagina -->
    @foreach($movement_items as $item)
        <tr>
            <td colspan="2">
                <table style="width: 100%;" class="table-collapse">
                    <tbody>
                        <tr>
                            <td style="text-align: center; width: 5em;">{{ $item->code }}</td>
                            <td style="text-align: center; width: 4em;">{{ floatval($item->quantity) }}</td>
                            <td>{{ $item->name }}@include('vouchers.theme.itemdetails', ['details' => $item->details])</td>
                            <td style="text-align: right; width: 5em;">{{ number_format($item->price, $company->decimal) }}</td>
                            @if($enabledDiscount)
                                <td style="text-align: right; width: 5em;">{{ number_format($item->discount, 2) }}</td>
                            @endif
                            <td style="text-align: right; width: 5em;">
                                {{ number_format($item->quantity * $item->price - $item->discount, 2) }}
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
                <td style="width: 350px;">
                    @include('vouchers.theme.adiinfopayment')
                </td>
                <td style="width: 200px;">@include('vouchers.theme.total')</td>
            </tr>
        </tbody>
    </table>
@endsection
