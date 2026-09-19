# Ventas (`Order`) — módulo backend

Referencia técnica del módulo **Ventas**: modelo `Order`, CRUD, listado,
validación y reglas de negocio.

Para el flujo de **emisión electrónica al SRI** (máquina de estados, firma,
`InvoiceBuilder`/`CreditNoteBuilder`, `OrderTotalsCalculator`, bugs de
redondeo ya corregidos) ver
[facturacion-electronica/ventas.md](./facturacion-electronica/ventas.md) y su
[README](./facturacion-electronica/README.md). Este documento **no** duplica
esa parte, solo enlaza a ella.

> Refactor 2026-09: `voucher_type` (int) pasó a `voucher_type_id` (FK a
> `voucher_types`) y se agregaron `venta_nota_venta`/`venta_proforma`. Las
> referencias `archivo.php:línea` de §2-§4 son anteriores a ese cambio y
> pueden estar corridas unas pocas líneas — el contenido descrito sigue
> siendo válido, solo verificar el número exacto en el archivo si hace falta.

## 1. Overview

Una `Order` representa un comprobante de venta. El tipo lo define
`voucher_type_id` (FK a `voucher_types`, catálogo compartido descrito en
[facturacion-electronica/README.md §0](./facturacion-electronica/README.md#0-catálogo-de-tipos-de-comprobante-voucher_types)),
con 4 slugs válidos para el módulo `venta`:

| slug | ¿Electrónico SRI? | Notas |
|---|---|---|
| `venta_factura` | Sí | Flujo original |
| `venta_nota_credito` | Sí | Flujo original |
| `venta_nota_venta` | No | Numeración interna propia, nunca dispara `ProcessVoucherJob` |
| `venta_proforma` | No | Numeración interna propia; ver §2.5 conversión a factura |

El mismo modelo/tabla `Order` soporta los 4, más campos residuales para
guía de remisión y retención. `OrderStoreRequest` acepta cualquier slug
`module=venta` activo — no hay una request/controlador separado por tipo.

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
| `serie` | Número de comprobante `EEE-PPP-NNNNNNNNN`, generado server-side vía `VoucherSequenceService` |
| `voucher_type_id` | FK a `voucher_types` — ver tabla de slugs en §1 |
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
| `plate` | Placa del vehículo (transporte terrestre). Nullable; obligatoria solo si algún producto de la venta es de tipo servicio (`type_product = 2`) con `aux_cod` de categoría SRI `transporte` (ver `OrderStoreRequest::hasTransportService()`, §3). Emitida en el XML como `<placa>` (nombre de tag fijo del manual SRI) entre `<moneda>` y `<pagos>` (`InvoiceBuilder.php:104-105`) según manual FE §2 (Tabla 33) |

> Los campos `base12` / `iva` (tarifa 12%) siguen existiendo solo para
> **visualizar** comprobantes históricos; la tarifa 12% está descontinuada en
> el cálculo (ver [facturacion-electronica/ventas.md §3](./facturacion-electronica/ventas.md#3-errores-detectados)).

## 2. Flujo CRUD

### Crear (`OrderController::store` → `OrderStoreService::createOrder`)

`app/Services/Order/OrderStoreService.php:28`. Dentro de una transacción:

1. Resuelve el `VoucherType` desde `voucher_type_id` y el `EmisionPoint`
   desde `point_id`.
2. `prepareOrderData()`: descarta `products/send/aditionals/point_id`,
   normaliza `guia` vacío a `null`, genera la `serie` vía
   `VoucherSequenceService::generateSerie()` (reserva atómica del siguiente
   secuencial para ese punto de emisión + tipo) y **recalcula todos los
   totales server-side** con `OrderTotalsCalculator::calculate()`, cuyos
   valores sobrescriben lo enviado por el frontend.
3. Crea la `Order` bajo la sucursal, luego `OrderItem`s (y movimientos de
   `Inventory` si aplica), crea `Repayment`s y `OrderAditional`s. (El
   incremento de secuencia ya ocurrió en el paso 2, dentro de
   `generateSerie()` — no hay un paso de "incrementar" separado.) El
   adicional obligatorio `Order::requiredAditional()` ("RUC Proveedor") solo
   se agrega si `voucherType->requires_electronic` es `true` — es un
   requisito del XML que va al SRI, no tiene sentido en nota de venta ni en
   proforma. Mismo guard en `OrderUpdateService::updateOrderAditionals()`.
4. Si `send = true` **y** el tipo tiene `requires_electronic = true`, invoca
   `OrderLifecycleService::process()` para emitir al SRI (ver doc de
   facturación electrónica). Para `venta_nota_venta`/`venta_proforma` el
   flag `send` se ignora silenciosamente: nunca disparan `ProcessVoucherJob`.

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

### Convertir proforma en factura (`OrderController::convertToInvoice`)

`app/Services/Order/OrderProformaToInvoiceService.php`. Endpoint
`GET orders/{order}/convert-to-invoice`, solo válido si `{order}` es
`venta_proforma` (422 si no — el guard vive directo en
`OrderController::convertToInvoice()`, no hay FormRequest: no queda ningún
campo que validar). Sin body, sin `point_id`, sin parámetro `send` — la
conversión siempre emite al SRI (`send` fijo en `true`), es justamente para
eso que existe el flujo proforma → factura.

- **Mismo punto de emisión que la proforma**: `Order` no guarda `point_id`
  como FK, así que `OrderProformaToInvoiceService::resolveEmisionPoint()` lo
  deriva parseando el establecimiento/punto ya codificados en la propia
  `serie` de la proforma (`estab-ptoEmi-secuencial`) y buscando el
  `EmisionPoint` de esa branch con ese `point`. La factura sale del mismo
  punto, con su propio secuencial (independiente del de la proforma).
- **Clona, no muta**: crea una `Order` nueva `voucher_type = venta_factura`
  copiando `customer_id`, `description`, `discount`, `expiration_days`,
  `pay_method`, `plate`, `guia`, ítems y adicionales (salvo el adicional
  requerido "RUC Proveedor", que `OrderStoreService` ya vuelve a agregar —
  clonarlo también duplicaría la fila) de la proforma origen. Pasa todo por
  `OrderStoreService::createOrder()` normal: totales se recalculan de nuevo,
  y siempre dispara `ProcessVoucherJob` (`send` fijo en `true` dentro del
  service, no viene del request).
- **La proforma original no se toca**: no se marca como "convertida", no se
  bloquea. El mismo `{order}` puede convertirse varias veces — cada
  conversión genera una factura nueva e independiente. Es una decisión de
  producto explícita (no un descuido): si en el futuro se necesita impedir
  reconversión, hay que agregar el guard a propósito.
- Sin `point_id`: la factura siempre sale del mismo punto de emisión que la
  proforma (ver arriba); si se necesita emitir desde otro punto, hay que
  editar la proforma antes de convertir, o crear la factura a mano con
  `POST orders`.

### PDF (`OrderController::pdf` → `OrderPdfService::buildPdf`)

`OrderPdfService::generatePdfView()` rutea por `voucherType->slug` a una
plantilla Blade distinta por tipo — no hay una sola plantilla genérica:

| slug | vista | notas |
|---|---|---|
| `venta_factura` | `vouchers.invoice` | agrega `payMethod` y `repayments` (facturas de reembolso) |
| `venta_nota_credito` | `vouchers.creditnote` | sin cambios |
| `venta_nota_venta` | `vouchers.notaventa` | mismo layout de `invoice` (mismo cuerpo de tabla/totales), agrega `payMethod`, sin sección de reembolsos |
| `venta_proforma` | `vouchers.proforma` | plantilla propia, **no** extiende `vouchers.theme.voucher` — diseño distinto a propósito, ver abajo |

Las 3 primeras comparten `vouchers.theme.voucher` (+ `theme.information` +
`theme.company`), que ahora reciben `$isElectronic` (=
`voucherType->requires_electronic`) para ocultar número de
autorización/fecha de autorización/ambiente/clave de acceso cuando el
comprobante no pasa por el SRI (nota de venta) — antes de esto,
`notaventa`/`proforma` no existían y esos campos siempre se mostraban vacíos
sobre un layout de nota de crédito ajeno (bug real, corregido).

**Proforma** es intencionalmente una plantilla aparte, no una factura con
campos ocultos: es el primer documento que ve un cliente potencial, así que
tiene su propio diseño (banda de color, tabla de items con encabezado
oscuro, caja de total destacada) en vez del layout utilitario estilo SRI.
Incluye una nota de vigencia (usa `expiration_days`, ya existente en
`Order`) y un disclaimer fijo ("Documento sin valor tributario — no
constituye un comprobante de venta autorizado por el SRI") para que nunca
se confunda con un comprobante electrónico real.

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
4. Inserta el adicional obligatorio `Order::requiredAditional()` ("RUC
   Proveedor" + el RUC leído de `config('services.order.ruc_proveedor')`,
   env `RUC_PROVEEDOR`) por cada orden, igual que en la creación individual
   (`OrderStoreService::createOrderAditionals`) — antes del refactor esto
   **no se insertaba** en el flujo de lote. El RUC estaba hardcodeado en el
   modelo; se movió a config/env para no tener el dato en el código fuente.
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
  `voucher_type_id` debe existir en `voucher_types` con `module=venta` y
  `active=true` (acepta los 4 slugs de §1, no solo factura/nota crédito),
  `point_id` exists, `date` requerido,
  `products` present array con `product_id/quantity/price/discount/iva`
  requeridos por ítem. Todos los campos de totales (`sub_total`, `base*`,
  `iva*`, `ice`, `discount`) son `sometimes|numeric` — se aceptan pero luego
  se **descartan y recalculan** (§2). `serie` **no** es un campo del
  request — la genera el backend a partir de `point_id` (ver
  [facturacion-electronica/README.md §0](./facturacion-electronica/README.md#0-catálogo-de-tipos-de-comprobante-voucher_types)),
  cualquier valor que se mande se ignora.
- **Regla de negocio — tope consumidor final** (`after()`, `:57-71`): si el
  cliente tiene `identication === '9999999999999'` y `total > 50`, error
  `"No es posible una venta mayor a $50 a consumidor final."`
- **Regla de negocio — `plate` obligatoria en transporte** (`after()` +
  `hasTransportService()`): si algún `products[].product_id` corresponde a un
  `Product` con `type_product == Product::TYPE_SERVICE` y `aux_cod` dentro de
  los códigos de `SriCategory` tipo `transporte`, `plate` (placa del
  vehículo) es obligatoria aunque la regla base la marque `nullable`.

### `OrderUpdateRequest` (`app/Http/Requests/Order/OrderUpdateRequest.php`)

- Reglas mínimas (`:16`): `total`, `products`, `aditionals`, `plate`
  (`sometimes|nullable`, igual que en creación). No re-valida `customer_id`,
  `date`, `serie`, `point_id` ni `voucher_type` (no se editan). A diferencia
  de `OrderStoreRequest`, no repite la regla de "obligatoria si hay servicio
  de transporte" — se puede guardar sin `plate` aunque la orden tenga un
  producto de transporte.
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
| POST | `orders` | Crear venta (factura, nota crédito, nota venta o proforma) | `OrderController::store` |
| GET | `orders/{order}/convert-to-invoice` | Clonar una proforma en factura nueva | `OrderController::convertToInvoice` |
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

### 5.8 — Reglas de `after()` no distinguen tipo de comprobante

El tope de $50 a consumidor final y la placa obligatoria en transporte
(§3) corren igual para los 4 slugs de venta, incluyendo `venta_proforma` y
`venta_nota_venta`. No estaba en el alcance del refactor a `voucher_type_id`
decidir si una proforma (que ni siquiera es un documento fiscal) debería
quedar exenta de esas reglas — queda como decisión de negocio pendiente.

### 5.9 — `authorize()` siempre `true` en los FormRequest

Tanto `OrderStoreRequest` como `OrderUpdateRequest` retornan `true` en
`authorize()` sin verificar que la `Order`/cliente pertenezcan a la empresa del
usuario autenticado. La segregación multiempresa depende enteramente de que los
servicios asignen `branch_id` desde `Auth::user()->company`; no hay defensa a
nivel de request contra manipular `{order}` de otra empresa vía route-model
binding (p.ej. en `update`, que hace binding directo de `Order`).
