# Ventas (`Order`) — módulo backend

Referencia técnica del módulo **Ventas**: modelo `Order`, CRUD, listado,
validación y reglas de negocio.

Para el flujo de **emisión electrónica al SRI** (máquina de estados, firma,
`InvoiceBuilder`/`CreditNoteBuilder`, `OrderTotalsCalculator`, bugs de
redondeo ya corregidos) ver
[facturacion-electronica/ventas.md](./facturacion-electronica/ventas.md) y su
[README](./facturacion-electronica/README.md). Este documento **no** duplica
esa parte, solo enlaza a ella.

## 1. Overview

Una `Order` representa un comprobante de venta: **factura** (`voucher_type = 1`)
o **nota de crédito** (`voucher_type = 4`). El mismo modelo/tabla soporta
ambos, más campos residuales para guía de remisión y retención.

### Modelos involucrados

| Modelo | Archivo | Rol |
|---|---|---|
| `Order` | `app/Models/Order/Order.php` | Cabecera del comprobante (totales, cliente, estado SRI) |
| `OrderItem` | `app/Models/Order/OrderItem.php` | Línea de detalle (`quantity`, `price`, `discount`, `ice`, `iva`) |
| `OrderAditional` | `app/Models/Order/OrderAditional.php` | Campos adicionales (`name`/`description`) del comprobante |
| `Repayment` / `RepaymentTax` | `app/Models/Order/Repayment/` | Reembolsos (facturas de intermediación/reembolso de gastos) |
| `OrderRetentionItem` | `app/Models/Order/OrderRetentionItem.php` | Ítems de retención asociados |
| `Lot` | `app/Models/Order/Lot.php` | Agrupa órdenes cargadas por lote (Excel) |

### Relaciones (`Order.php:89-107`)

- `orderitems()` → `hasMany(OrderItem)`
- `repayments()` → `hasMany(Repayment)`
- `orderaditionals()` → `hasMany(OrderAditional)`
- `inventories()` → `hasMany(Inventory, 'model_id', 'id')` — movimientos de
  stock generados si `company->inventory` está activo.

Relaciones externas por FK (no declaradas como relación Eloquent en `Order`):
`customer_id` → `Customer`, `branch_id` → `Branch`, `point_id` (en el request)
→ `EmisionPoint`, `pay_method` → `MethodOfPayment` (código SRI), cada
`OrderItem.product_id` → `Product`.

### Campos clave del `Order`

Todos con cast `float` salvo indicación (`Order.php:70-87`).

| Campo | Propósito |
|---|---|
| `branch_id` | Sucursal emisora (se asigna server-side, no viene del request) |
| `customer_id` | Cliente |
| `date` | Fecha de emisión (`Y-m-d`) |
| `serie` | Número de comprobante `EEE-PPP-NNNNNNNNN`, generado server-side |
| `voucher_type` | `int` — `1` factura, `4` nota de crédito |
| `no_iva` | Base "No objeto de IVA" (código IVA 6) |
| `base0` / `base5` / `base8` / `base12` / `base15` | Bases imponibles por tarifa |
| `iva5` / `iva8` / `iva` / `iva15` | IVA por tarifa (`iva` = histórico 12%) |
| `ice` | Total ICE |
| `discount` | Descuento global de la orden |
| `sub_total` | Σ de las bases |
| `total` | `sub_total + ice + iva_total − discount` |
| `paid` / `pay_method` | Pago / método de pago SRI |
| `state` | Estado del ciclo SRI (ver [README](./facturacion-electronica/README.md#1-máquina-de-estados-appstaticclassesvoucherstates)) |
| `authorization` / `autorized` | Clave de acceso y n° de autorización SRI |
| `xml` | Ruta del XML en `storage` |
| `extra_detail` | Último mensaje de rechazo/estado del SRI |
| `send_mail` (`bool`) / `expiration_days` / `description` | Correo enviado, crédito, observación |
| `guia`, `serie_retencion`, `date_order`, `serie_order`, `reason`, `lot_id` | Campos de guía de remisión, retención, nota de crédito referenciada y lote |

> Los campos `base12` / `iva` (tarifa 12%) siguen existiendo solo para
> **visualizar** comprobantes históricos; la tarifa 12% está descontinuada en
> el cálculo (ver [facturacion-electronica/ventas.md §3](./facturacion-electronica/ventas.md#3-errores-detectados)).

## 2. Flujo CRUD

### Crear (`OrderController::store` → `OrderStoreService::createOrder`)

`app/Services/Order/OrderStoreService.php:28`. Dentro de una transacción:

1. Resuelve el `EmisionPoint` desde `point_id`.
2. `prepareOrderData()` (`:51`): descarta `products/send/aditionals/point_id`,
   normaliza `guia` vacío a `null`, genera la `serie` con la secuencia del punto
   de emisión (`generateSerie()`, `:70`) y **recalcula todos los totales
   server-side** con `OrderTotalsCalculator::calculate()` (`:61`), cuyos valores
   sobrescriben lo enviado por el frontend.
3. Crea la `Order` bajo la sucursal, luego `OrderItem`s (y movimientos de
   `Inventory` si aplica), incrementa la secuencia del punto de emisión,
   crea `Repayment`s y `OrderAditional`s.
4. Si `send = true`, invoca `OrderLifecycleService::process()` para emitir al
   SRI (ver doc de facturación electrónica).

### Editar (`OrderController::update` → `OrderUpdateService::updateOrder`)

`app/Services/Order/OrderUpdateService.php:17`. En transacción: recalcula
totales igual que en store, actualiza la `Order`, **fuerza `state = SAVED`**
(`:31`, permite reemitir), y regenera ítems y adicionales con
delete + `createMany` (`:43`, `:63`). La serie no se regenera (se excluye en
`:20`).

### Anular (`OrderLifecycleController::cancel` → `OrderLifecycleService::cancel`)

`app/Http/Controllers/Order/OrderLifecycleController.php:26`. Delega en
`OrderSriService::cancel()` (SOAP de anulación). No hay `destroy`: los
comprobantes no se borran, se anulan (estado `ANULADO`).

### Ver / detalle (`OrderController::edit` → `OrderShowService::getOrderDetail`)

`app/Services/Order/OrderShowService.php:13`. Devuelve la orden (sin campos
`null`, `:24`), sus ítems con `percentage` de IVA resuelto por join, los
adicionales, los productos y el cliente. `OrderController::create`/`edit`
adjuntan además `emisionData()` (puntos de emisión, métodos de pago, tarifas
IVA activas, flag `tourism`) para poblar el formulario.

### Carga por lote (`OrderController::storeLot` → `OrderLotService::store`)

`app/Services/Order/OrderLotService.php:44`. Arquitectura de 4 piezas, single
responsibility:

- **`OrderLotStoreRequest`** (`app/Http/Requests/Order/`) — valida que venga
  el archivo `lot` (`required|file|mimes:xlsx,xls,csv`).
- **`OrderLotExcelReader`** (`app/Services/Order/`) — lee el Excel fila por
  fila con PhpSpreadsheet (sin heading row: descarta la primera fila como
  encabezado, columnas posicionales `[0]` identificación, `[2]` código,
  `[3]` cantidad, `[4]` precio).
- **`OrderLotService`** — orquesta todo el flujo (ver detalle abajo).
- **`OrderController::storeLot`** — controlador delgado: llama al service,
  atrapa `RuntimeException` para las 4 validaciones de negocio y responde
  `['msm' => ...]`.

Flujo en `OrderLotService::store()`:

1. Lee el Excel, valida que no haya celdas en blanco y que el número de filas
   no supere `OrderLotService::MAX_ROWS` (**2000**) — si no, lanza
   `RuntimeException`.
2. Resuelve clientes (`identication`) y productos (`code`) contra la
   `branch_id` de la sucursal — si falta alguno, `RuntimeException`.
3. Crea un `Lot` y, por cada fila, una `Order` con su `OrderItem` (misma
   sucursal, mismo `lot_id`). Consumidor final (`9999999999999`) usa forma de
   pago `01`; el resto usa `company->pay_method`.
4. Inserta el adicional obligatorio `Order::REQUIRED_ADITIONAL` ("RUC
   Proveedor") por cada orden, igual que en la creación individual
   (`OrderStoreService::createOrderAditionals`) — antes del refactor esto
   **no se insertaba** en el flujo de lote.
5. Encola un `ProcessVoucherJob` por orden en la cola **`lots`**
   (`->onQueue('lots')`), separada de la cola `default` que usan los
   comprobantes creados uno a uno — así un lote de hasta 2000 filas no le
   quita turno a un comprobante individual que llegue mientras tanto (ver
   `compose.prod.yaml`, workers `queue`/`queue2`).

Igual que antes del refactor, **no hay un flujo de "lote" a nivel SRI**: cada
`Order` se firma/envía/autoriza individualmente vía `ProcessVoucherJob`, no
existe agrupación de envío por lote (ver §5.2).

## 3. Validaciones y reglas de negocio

### `OrderStoreRequest` (`app/Http/Requests/Order/OrderStoreRequest.php`)

- `authorize()` retorna `true` sin comprobar propiedad del recurso (`:11`).
- Reglas principales (`:16`): `customer_id` exists, `total` numeric,
  `voucher_type` `in:1,4`, `point_id` exists, `serie`/`date` requeridos,
  `products` present array con `product_id/quantity/price/discount/iva`
  requeridos por ítem. Todos los campos de totales (`sub_total`, `base*`,
  `iva*`, `ice`, `discount`) son `sometimes|numeric` — se aceptan pero luego
  se **descartan y recalculan** (§2).
- **Regla de negocio — tope consumidor final** (`after()`, `:57-71`): si el
  cliente tiene `identication === '9999999999999'` y `total > 50`, error
  `"No es posible una venta mayor a $50 a consumidor final."`

### `OrderUpdateRequest` (`app/Http/Requests/Order/OrderUpdateRequest.php`)

- Reglas mínimas (`:16`): `total`, `products`, `aditionals`. No re-valida
  `customer_id`, `date`, `serie`, `point_id` ni `voucher_type` (no se editan).
- **Regla — estados protegidos** (`after()`, `:30-48`): bloquea la edición si
  el estado es `ENVIADO`, `RECIBIDA`, `EN_PROCESO`, `AUTORIZADO` o `ANULADO`.
  Solo se puede editar en `CREADO`, `DEVUELTA` o `NO AUTORIZADO`.

### Listado (`OrderController::index`, `:26`)

Join con `customers`, orden por `created_at` desc, paginación configurable
(`paginate`, default 15). Filtros: `search` (LIKE sobre `serie` y nombre del
cliente, con escape de `%`/`_` — `:35`) y `date` (`whereDate`). Devuelve
`OrderResources` (id + `atts` + `customer`, `app/Http/Resources/OrderResources.php`).

## 4. Endpoints principales

Todas bajo el grupo autenticado de `routes/api.php:34-45`.

| Método | Ruta | Acción | Controlador |
|---|---|---|---|
| GET | `orders` | Listado paginado + filtros | `OrderController::index` |
| GET | `orders/create` | Datos para el formulario | `OrderController::create` |
| POST | `orders` | Crear venta | `OrderController::store` |
| POST | `orders/lot` | Carga por lote (Excel) | `OrderController::storeLot` (`OrderLotService::store`) |
| GET | `orders/{order}` | Detalle para edición | `OrderController::edit` |
| PUT | `orders/{order}` | Actualizar venta | `OrderController::update` |
| GET | `orders/{order}/pdf` | PDF del comprobante | `OrderController::pdf` |
| GET | `orders/{order}/printf` | PDF formato ticket | `OrderController::printf` |
| GET | `orders/{order}/process` | Emitir/avanzar en el SRI | `OrderLifecycleController::process` |
| POST | `orders/{order}/cancel` | Anular | `OrderLifecycleController::cancel` |
| GET | `orders/{order}/mail` | Reenviar correo | `OrderLifecycleController::mail` |
| GET | `orders/{order}/xml` | Descargar XML | `OrderLifecycleController::download` |

## 5. Puntos de mejora

Hallazgos concretos con evidencia. Los relativos a cálculo de totales y SOAP
ya están cubiertos en [facturacion-electronica/ventas.md](./facturacion-electronica/ventas.md);
aquí van los específicos del módulo Ventas general.

### 5.1 — `Lot::create()` descarta `date` y `voucher_type` (bug de fillable)

`OrderLotService::store` crea el lote con `date` y `voucher_type`
(`OrderLotService.php:53-60`), pero el `$fillable` de `Lot`
(`Lot.php:9-12`) solo incluye `emision_point_id, serie, authorization, state`.
Esas dos columnas existen en la tabla (migración
`2026_04_13_234605_add_date_voucher_type_to_lots_table.php`) pero **se
descartan silenciosamente** por mass-assignment: el lote queda con `date` y
`voucher_type` en `null`. Sigue sin corregirse tras el refactor a service (no
era el alcance de ese cambio).

### 5.2 — `OrderController::storeLot` no responde en el happy path

`OrderLotService::store()` no retorna nada y el controlador no construye una
respuesta de éxito → HTTP 200 con cuerpo vacío si todo sale bien. Preservado
intencionalmente en el refactor a service (no se tocó el contrato con el
frontend); si el frontend en algún momento necesita el `Lot`/las `Order`
creadas en la respuesta, hay que decidirlo explícitamente. Además, los
`response()->json(['msm' => ...])` de error dentro de `OrderLotService::store`
(vía `RuntimeException`, atrapada en `OrderController::storeLot`) usan status
200 para condiciones que son de validación.

### 5.3 — La carga por lote no usa `OrderTotalsCalculator`

`OrderLotService::buildOrderRows` calcula totales inline con la fórmula
antigua `iva = subTotal * percentage * 0.01` y `total = subTotal + iva`
(`OrderLotService.php:154-183`), sin descuento, sin ICE y sin la corrección
de doble redondeo. Es exactamente la **segunda fuente de cálculo** que el fix
documentado en facturación electrónica eliminó del flujo normal, pero que
sigue viva aquí — mismo riesgo de `ERROR EN DIFERENCIAS` del SRI. Además arma
las columnas dinámicamente (`base{$percentage}`, `iva{$percentage}`): para un
producto al 12% generaría `iva12`, columna que no existe y sería descartada.
Sigue sin corregirse tras el refactor a service.

### 5.4 — Regla de tope de $50 a consumidor final solo en creación

`OrderStoreRequest::after()` valida `total > 50` para consumidor final
(`OrderStoreRequest.php:67`), pero `OrderUpdateRequest` no replica esa regla.
Una venta a consumidor final creada bajo el tope puede **editarse** por encima
de $50 sin que la validación la bloquee.

### 5.5 — `OrderUpdateService` no reconcilia `repayments`

`OrderStoreService::createOrder` crea `repayments` (`OrderStoreService.php:38`),
pero `OrderUpdateService::updateOrder` (`OrderUpdateService.php:17-39`) solo
regenera ítems y adicionales — **nunca toca los reembolsos**. Al editar una
factura de reembolso, los `Repayment`/`RepaymentTax` originales quedan
huérfanos y desincronizados de los ítems recreados.

### 5.6 — ~~`OrderExport` está definido pero nunca se invoca~~ (resuelto)

Ya cableado: `GET orders/export/{yearMonth}` (`OrderController::export`,
`routes/api.php`) descarga el xlsx del mes vía `OrderExport`. De paso se
encontró y corrigió un bug latente: `OrderExport::query()` no declaraba tipo
de retorno, incompatible con `Maatwebsite\Excel\Concerns\FromQuery::query()`
— cualquier intento de usar la clase tiraba `Fatal error` (nunca se había
detectado porque nada la invocaba). `ShopExport` tiene el mismo problema de
tipo y **tampoco está enrutada** — si se cablea un endpoint equivalente para
Compras, aplicar el mismo fix (`query(): Builder`) antes.

### 5.7 — Typo `'succes'` en las respuestas de `OrderLifecycleController`

Todas las respuestas JSON usan la clave mal escrita `'succes'` en vez de
`'success'` (`OrderLifecycleController.php:20,23,30,34,42,45`). No es solo
cosmético: el frontend queda acoplado a la clave con typo; corregirla exige un
cambio coordinado.

### 5.8 — `authorize()` siempre `true` en los FormRequest

Tanto `OrderStoreRequest` como `OrderUpdateRequest` retornan `true` en
`authorize()` sin verificar que la `Order`/cliente pertenezcan a la empresa del
usuario autenticado. La segregación multiempresa depende enteramente de que los
servicios asignen `branch_id` desde `Auth::user()->company`; no hay defensa a
nivel de request contra manipular `{order}` de otra empresa vía route-model
binding (p.ej. en `update`, que hace binding directo de `Order`).
