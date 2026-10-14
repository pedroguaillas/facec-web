    @php($artisanNumber = $company->artisan_number ?? null)
    @if(count($orderaditionals) > 0 || $artisanNumber)
    <table style="padding-bottom: .7em; border-radius: 0;" class="table-rounded">
        <tbody>
            <tr style="background-color: #e6e6e6;">
                <th style="padding: .5em 0em; background-color: #e6e6e6;" class="align-middle" colspan="2">INFORMACIÓN ADICIONAL</th>
            </tr>
            @if($artisanNumber)
            <tr>
                <td style="padding: .3em .3em; width: 100px;">Artesano Calificado</td>
                <td style="padding: .3em .3em; width: 354px">Nro. {{ $artisanNumber }}</td>
            </tr>
            @endif
            @foreach($orderaditionals as $orderaditional)
            <tr>
                <td style="padding: .3em .3em; width: 100px;">{{ $orderaditional->name }}</td>
                <td style="padding: .3em .3em; width: 354px">{{ $orderaditional->description }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @endif

    @isset($payMethod)
    <table style="margin-top: .5em; border-radius: 0;" class="table-rounded">
        <tbody>
            <tr style="background-color: #e6e6e6;">
                <th style="width: {{ ($isCredito ?? false) ? 225 : 375 }}px; background-color: #e6e6e6;">Forma de pago</th>
                <th style="width: 70px; background-color: #e6e6e6;">Valor</th>
                @if($isCredito ?? false)
                <th style="width: 80px; background-color: #e6e6e6;">Plazo (días)</th>
                <th style="width: 70px; background-color: #e6e6e6;">Vence</th>
                @endif
            </tr>
            <tr>
                <td>{{ $payMethod }}</td>
                <td style="padding-right: .5em; text-align: right;">{{ number_format($movement->total, 2) }}</td>
                @if($isCredito ?? false)
                <td style="text-align: center;">{{ $movement->expiration_days }}</td>
                <td style="text-align: center;">{{ $dueDate }}</td>
                @endif
            </tr>
        </tbody>
    </table>
    @endisset