<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>
        @switch($movement->voucher_type)
        @case(1)
        FACTURA
        @break

        @case(3)
        LIQUIDACIÓN DE COMPRA DE BIENES Y PRESTACIÓN DE SERVICIOS
        @break

        @case(4)
        NOTA DE CRÉDITO
        @break

        @case(5)
        NOTA DE DÉDITO
        @break

        @case(6)
        GUIA DE REMISIÓN
        @break

        @case(7)
        RETENCIÓN
        @break

        @default
        OTROS
        @endswitch
    </title>
    <style>
        * {
            box-sizing: border-box;
            padding: 0;
            margin: 0;
        }

        @page {
            margin: 5em 2.5em 3em;
            font-size: 12px;
            font-family: sans-serif;
        }

        /* Este estable el margen de la hoja */
        html {
            margin: 1.5em;
        }

        .widthboder {
            border: 2px solid #888;
            border-radius: 10px;
        }

        .relleno {
            padding: .5em;
        }

        .relleno-nc {
            padding: .2em .5em;
        }

        .m-1 {
            margin: 1em;
        }

        .m-2 {
            margin: 2em;
        }

        .mb-0 {
            margin-bottom: 0;
        }

        .table-collapse {
            border-collapse: collapse;
        }

        .table-collapse th,
        .table-collapse td {
            border: 1px solid #888;
            padding: 5px;
        }

        /* Tabla con bordes y esquinas redondeadas */
        .table-rounded {
            border-collapse: separate;
            border-spacing: 0;
            border: 1px solid #888;
            border-radius: 10px;
            width: 100%;
        }

        .table-rounded th,
        .table-rounded td {
            border-right: 1px solid #888;
            border-bottom: 1px solid #888;
            padding: 5px;
        }

        .table-rounded tr:last-child th,
        .table-rounded tr:last-child td {
            border-bottom: none;
        }

        .table-rounded th:last-child,
        .table-rounded td:last-child {
            border-right: none;
        }

        /* Tabla con bordes, esquinas redondeadas y secciones thead/tbody/tfoot */
        .table-rounded-full {
            border-collapse: separate;
            border-spacing: 0;
            border: 1px solid #888;
            border-radius: 10px;
            width: 100%;
        }

        .table-rounded-full th,
        .table-rounded-full td {
            border-right: 1px solid #888;
            border-bottom: 1px solid #888;
            padding: 5px;
        }

        /* Solo quitar bottom border en la última fila del tfoot (borde exterior lo cubre) */
        .table-rounded-full tfoot tr:last-child th,
        .table-rounded-full tfoot tr:last-child td {
            border-bottom: none;
        }

        .table-rounded-full th:last-child,
        .table-rounded-full td:last-child {
            border-right: none;
        }

        /* Cuando no hay tfoot, quitar bottom border de la última fila del tbody */
        .table-rounded-full tbody:last-child tr:last-child th,
        .table-rounded-full tbody:last-child tr:last-child td {
            border-bottom: none;
        }

        /* Tabla con solo borde exterior redondeado */
        .table-bordered {
            border-collapse: separate;
            border-spacing: 0;
            border: 1px solid #888;
            border-radius: 10px;
            width: 100%;
        }

        .parent-img {
            text-align: center;
        }
    </style>
</head>

<body>
    <table style="border-collapse: collapse;">
        <tbody>
            <tr>
                <td style="width: 410px;" class="mb-0">@include('vouchers.theme.company')</td>
                <td style="width: 340px;">@include('vouchers.theme.information')</td>
            </tr>
            @yield('body')
            <tr>
                <td colspan="2">@yield('footer')</td>
            </tr>
        </tbody>
    </table>
</body>

</html>