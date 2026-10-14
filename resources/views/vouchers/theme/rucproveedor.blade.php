<table style="margin-top: .5em; border-radius: 0;" class="table-rounded">
    <tbody>
        <tr style="background-color: #e6e6e6;">
            <th style="padding: .5em 0em; background-color: #e6e6e6;" class="align-middle" colspan="2">INFORMACIÓN ADICIONAL</th>
        </tr>
        @if(($showArtisan ?? false) && $company->artisan_number)
        <tr>
            <td style="padding: .3em .3em; width: 100px;">Artesano Calificado</td>
            <td style="padding: .3em .3em; width: 354px">Nro. {{ $company->artisan_number }}</td>
        </tr>
        @endif
        <tr>
            <td style="padding: .3em .3em; width: 100px;">RUC Proveedor</td>
            <td style="padding: .3em .3em; width: 354px">{{ config('services.order.ruc_proveedor') }}</td>
        </tr>
    </tbody>
</table>
