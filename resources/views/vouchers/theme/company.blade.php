<div style="width: 400px;">
    <div class="parent-img">
        <img src="{{ storage_path('app/public/logos/'.$branch->logoFile()) }}" alt="Logo" style="width: auto; height: 125px;" />
    </div>
    <table style="margin-top: .5em;" class="table-bordered">
        <tbody>
            <tr>
                <td style="padding: .5em;" style="text-align: center;" colspan="3">{{ $company->company }}</td>
            </tr>
            @if($branch->name !== null)
            <tr>
                <th class="relleno" colspan="3">{{ $branch->name }}</th>
            </tr>
            @endif
            @php($matrix = $company->matrix())
            @if($matrix && $matrix->id !== $branch->id)
            <tr>
                <td class="relleno">Dir. Matriz</td>
                <td class="align-middle" colspan="2">{{ $matrix->address }}</td>
            </tr>
            <tr>
                <td class="relleno">Dir. Sucursal</td>
                <td class="align-middle" colspan="2">{{ $branch->address }}</td>
            </tr>
            @else
            <tr>
                <td class="relleno">Dirección</td>
                <td class="align-middle" colspan="2">{{ $branch->address }}</td>
            </tr>
            @endif
            <tr>
                <td class="relleno" colspan="2">Obligado a llevar contabilidad</td>
                <td class="align-middle">{{ $company->accounting ? 'SI' : 'NO' }}</td>
            </tr>
            @if($company->artisan_number)
            <tr>
                <td class="relleno" colspan="2">Artesano Calificado Nro.</td>
                <td class="align-middle">{{ $company->artisan_number }}</td>
            </tr>
            @endif
            @if($voucherTypeSlug !== \App\StaticClasses\VoucherTypes::VENTA_NOTA_CREDITO)

            @if($company->retention_agent)
            <tr>
                <td class="relleno" colspan="2">Agente de Retención Resolución No.</td>
                <td class="align-middle">{{ $company->retention_agent }}</td>
            </tr>
            @endif

            @if($company->rimpe === 1)
            <tr>
                <td style="text-align: left;" class="relleno" colspan="3">CONTRIBUYENTE RÉGIMEN RIMPE</td>
            </tr>
            @elseif($company->rimpe === 2)
            <tr>
                <td style="text-align: left;" class="relleno" colspan="3">CONTRIBUYENTE NEGOCIO POPULAR - RÉGIMEN RIMPE</td>
            </tr>
            @endif

            @endif
        </tbody>
    </table>
</div>