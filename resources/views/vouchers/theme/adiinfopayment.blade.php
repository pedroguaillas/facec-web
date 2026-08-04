    @if(count($orderaditionals) > 0)
    <table style="padding-bottom: .7em; border-radius: 0;" class="table-rounded">
        <tbody>
            <tr style="background-color: #e6e6e6;">
                <th style="padding: .5em 0em; background-color: #e6e6e6;" class="align-middle" colspan="2">INFORMACIÓN ADICIONAL</th>
            </tr>
            @foreach($orderaditionals as $orderaditional)
            <tr>
                <td style="padding: .3em .3em; width: 100px;">{{ $orderaditional->name }}</td>
                <td style="padding: .3em .3em; width: 354px">{{ $orderaditional->description }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @endif

    <table style="margin-top: .5em; border-radius: 0;" class="table-rounded">
        <tbody>
            <tr style="background-color: #e6e6e6;">
                <th style="width: 375px; background-color: #e6e6e6;">Forma de pago</th>
                <th style="width: 70px; background-color: #e6e6e6;">Valor</th>
            </tr>
            <tr>
                <td>{{ $payMethod }}</td>
                <td style="padding-right: .5em; text-align: right;">{{ number_format($movement->total, 2) }}</td>
            </tr>
        </tbody>
    </table>