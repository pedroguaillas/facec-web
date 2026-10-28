<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <title>Proforma {{ $movement->serie }}</title>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            color: #1f2933;
            font-family: 'Helvetica', 'Arial', sans-serif;
        }

        @page {
            size: A4;
            margin: 1.8cm 2cm 2.2cm 2cm;
        }

        body {
            font-size: 12px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        /* Cada fila de la tabla raíz es una "sección" del documento; el espacio
           entre secciones se da con padding-top en la celda, no con margin-top
           entre tablas hermanas — dompdf no siempre respeta ese margin. */
        .sections > tbody > tr > td {
            padding-top: 1.4em;
        }

        .sections > tbody > tr:first-child > td {
            padding-top: 0;
        }

        /* dompdf no pinta el borde de una <table> con border-collapse: collapse
           cuando el borde solo está definido en la tabla (no por celda) — hay
           que usar separate + border-spacing:0, mismo patrón que ya usa
           `.table-rounded` en theme/voucher.blade.php. */
        .card {
            border: 1px solid #9ca3af;
            border-radius: 10px;
            border-collapse: separate;
            border-spacing: 0;
        }

        .card > tbody > tr > td {
            padding: .9em 1.1em;
        }

        /* Tablas con <thead> no pintan su borde de forma confiable en dompdf
           ni con separate — envolver en un <div> con borde es 100% fiable
           porque no depende para nada del motor de tablas. */
        .boxed-wrap {
            border: 1px solid #9ca3af;
        }

        .muted {
            color: #6b7280;
        }

        .small {
            font-size: 10px;
        }

        /* ===== Encabezado ===== */
        .header-inner td {
            vertical-align: top;
        }

        .company-name {
            font-size: 16px;
            font-weight: bold;
            padding-top: .5em;
        }

        .badge {
            background-color: #3f3f46;
            border-radius: 10px;
            padding: .8em 1.1em;
            text-align: right;
        }

        .badge .title {
            font-size: 22px;
            font-weight: bold;
            letter-spacing: 2px;
            color: #ffffff;
        }

        .badge .meta {
            font-size: 11px;
            color: #d4d4d8;
            padding-top: .3em;
        }

        .badge .meta strong {
            color: #ffffff;
        }

        /* ===== Cliente ===== */
        .client-label {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #6b7280;
        }

        .client-value {
            font-size: 12px;
            font-weight: bold;
        }

        /* ===== Tabla de productos ===== */
        /* table-layout: fixed es necesario: con layout automático (default) y
           columnas de ancho fijo mezcladas con una columna sin ancho, dentro
           de la tabla .sections, dompdf calcula un ancho real mayor al 100%
           del contenedor — la tabla se desborda a la derecha y el borde de
           .boxed-wrap que la envuelve deja de pintarse en 3 de sus 4 lados. */
        .items {
            table-layout: fixed;
        }

        .items .items-head th {
            background-color: #3f3f46;
            color: #ffffff;
            padding: .7em .8em;
            text-align: left;
            font-size: 11px;
        }

        .items tbody td {
            padding: .6em .8em;
            border-bottom: 1px solid #e5e7eb;
            font-size: 11px;
        }

        .items tbody tr.even td {
            background-color: #f3f4f6;
        }

        .text-right {
            text-align: right;
        }

        .text-center {
            text-align: center;
        }

        /* ===== Totales ===== */
        .totals td {
            padding: .5em .8em;
            font-size: 11px;
        }

        .totals .total-row td {
            background-color: #3f3f46;
            color: #ffffff;
            font-size: 14px;
            font-weight: bold;
        }

        /* ===== Footer ===== */
        .notice {
            background-color: #f3f4f6;
            border: 1px solid #9ca3af;
            border-radius: 8px;
            border-collapse: separate;
            border-spacing: 0;
        }

        .notice td {
            padding: .7em .9em;
            font-size: 10px;
            color: #374151;
        }

        .disclaimer {
            font-size: 9px;
            color: #9ca3af;
            text-align: center;
        }
    </style>
</head>

<body>
    <table class="sections">
        <tbody>
            <tr>
                <td>
                    <table class="card">
                        <tbody>
                            <tr>
                                <td style="width: 60%;">
                                    <table class="header-inner">
                                        <tr>
                                            <td>
                                                @if($company->logo_dir)
                                                    <img src="{{ storage_path('app/public/logos/'.$company->logo_dir) }}" alt="Logo" style="height: 70px; width: auto;" />
                                                @endif
                                                <div class="company-name">{{ $company->company }}</div>
                                                <div class="muted">RUC: {{ $company->ruc }}</div>
                                                @if($branch->address)
                                                    <div class="muted small">{{ $branch->address }}</div>
                                                @endif
                                            </td>
                                        </tr>
                                    </table>
                                </td>
                                <td style="width: 40%;">
                                    <table class="badge">
                                        <tr>
                                            <td>
                                                <div class="title">PROFORMA</div>
                                                <div class="meta">No. <strong>{{ $movement->serie }}</strong></div>
                                                <div class="meta">Fecha: <strong>{{ date('d/m/Y', strtotime($movement->date)) }}</strong></div>
                                                @if($movement->expiration_days > 0)
                                                    <div class="meta">Válida por <strong>{{ $movement->expiration_days }} días</strong></div>
                                                @endif
                                            </td>
                                        </tr>
                                    </table>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </td>
            </tr>

            <tr>
                <td>
                    <table class="card">
                        <tbody>
                            <tr>
                                <td style="width: 60%;">
                                    <div class="client-label">Cliente</div>
                                    <div class="client-value">{{ $movement->name }}</div>
                                    <div class="muted">{{ $movement->identication }}</div>
                                    @if($movement->address)
                                        <div class="muted small">{{ $movement->address }}</div>
                                    @endif
                                </td>
                                <td style="width: 40%;">
                                    @if($movement->email)
                                        <div class="client-label">Correo</div>
                                        <div class="muted">{{ $movement->email }}</div>
                                    @endif
                                    @if($movement->phone)
                                        <div class="client-label">Teléfono</div>
                                        <div class="muted">{{ $movement->phone }}</div>
                                    @endif
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </td>
            </tr>

            @if($movement->description)
                <tr>
                    <td>
                        <div class="muted small">{{ $movement->description }}</div>
                    </td>
                </tr>
            @endif

            <tr>
                <td>
                    <div class="boxed-wrap">
                    <table class="items">
                        <tbody>
                            <tr class="items-head">
                                <th style="width: 8%;">Cant.</th>
                                <th style="width: {{ $enabledDiscount ? '47%' : '62%' }};">Descripción</th>
                                <th style="width: 15%;" class="text-right">Precio</th>
                                @if($enabledDiscount)
                                    <th style="width: 15%;" class="text-right">Desc.</th>
                                @endif
                                <th style="width: 15%;" class="text-right">Subtotal</th>
                            </tr>
                            @foreach($movement_items as $item)
                                <tr class="{{ $loop->even ? 'even' : '' }}">
                                    <td class="text-center">{{ floatval($item->quantity) }}</td>
                                    <td>{{ $item->name }}@include('vouchers.theme.itemdetails', ['details' => $item->details])</td>
                                    <td class="text-right">{{ number_format($item->price, $company->decimal) }}</td>
                                    @if($enabledDiscount)
                                        <td class="text-right">{{ number_format($item->discount, 2) }}</td>
                                    @endif
                                    <td class="text-right">{{ number_format($item->quantity * $item->price - $item->discount, 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    </div>
                </td>
            </tr>

            <tr>
                <td>
                    <table>
                        <tr>
                            <td style="width: 55%;"></td>
                            <td style="width: 45%;">
                                <div class="boxed-wrap">
                                <table class="totals">
                                    @if($movement->base5 > 0)
                                        <tr>
                                            <td>Subtotal 5%</td>
                                            <td class="text-right">{{ number_format($movement->base5, 2) }}</td>
                                        </tr>
                                    @endif
                                    @if($movement->base8 > 0)
                                        <tr>
                                            <td>Subtotal 8%</td>
                                            <td class="text-right">{{ number_format($movement->base8, 2) }}</td>
                                        </tr>
                                    @endif
                                    @if(!$beforeTaxChange)
                                        <tr>
                                            <td>Subtotal 15%</td>
                                            <td class="text-right">{{ number_format($movement->base15, 2) }}</td>
                                        </tr>
                                    @else
                                        <tr>
                                            <td>Subtotal 12%</td>
                                            <td class="text-right">{{ number_format($movement->base12, 2) }}</td>
                                        </tr>
                                    @endif
                                    <tr>
                                        <td>Subtotal 0%</td>
                                        <td class="text-right">{{ number_format($movement->base0, 2) }}</td>
                                    </tr>
                                    @if($movement->discount > 0)
                                        <tr>
                                            <td>Descuento</td>
                                            <td class="text-right">-{{ number_format($movement->discount, 2) }}</td>
                                        </tr>
                                    @endif
                                    @if($movement->ice > 0)
                                        <tr>
                                            <td>ICE</td>
                                            <td class="text-right">{{ number_format($movement->ice, 2) }}</td>
                                        </tr>
                                    @endif
                                    @if(!$beforeTaxChange)
                                        <tr>
                                            <td>IVA 15%</td>
                                            <td class="text-right">{{ number_format($movement->iva15, 2) }}</td>
                                        </tr>
                                    @else
                                        <tr>
                                            <td>IVA 12%</td>
                                            <td class="text-right">{{ number_format($movement->iva, 2) }}</td>
                                        </tr>
                                    @endif
                                    <tr class="total-row">
                                        <td>TOTAL</td>
                                        <td class="text-right">${{ number_format($movement->total, 2) }}</td>
                                    </tr>
                                </table>
                                </div>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>

            <tr>
                <td>
                    <table class="notice">
                        <tr>
                            <td>
                                Esta proforma es una cotización informativa
                                @if($movement->expiration_days > 0)
                                    válida por {{ $movement->expiration_days }} días desde su fecha de emisión
                                @endif
                                . Precios y disponibilidad sujetos a cambio sin previo aviso hasta la confirmación del pedido.
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>

            <tr>
                <td>
                    <div class="disclaimer">
                        Documento sin valor tributario — no constituye un comprobante de venta autorizado por el SRI.
                    </div>
                </td>
            </tr>
        </tbody>
    </table>
</body>

</html>
