# Compras (`Shop`)

Módulo backend de **Compras**: registro de comprobantes de compra recibidos
del proveedor y, cuando aplica, emisión electrónica al SRI de la
**Liquidación de Compra** y del **Comprobante de Retención**.

Este documento cubre el módulo en general (modelos, CRUD, validación,
endpoints, reglas de negocio). Para el detalle de emisión electrónica
(máquina de estados, firma, XML, retenciones server-side) ver
[facturacion-electronica/compras.md](./facturacion-electronica/compras.md)
y el flujo genérico en
[facturacion-electronica/README.md](./facturacion-electronica/README.md).
Para lógica de formulario/UI ver `facec-front-next/docs/compras.md`.

> Refactor 2026-09: `voucher_type` (int) pasó a `voucher_type_id` (FK al
> catálogo `voucher_types`, ver
> [facturacion-electronica/README.md §0](./facturacion-electronica/README.md#0-catálogo-de-tipos-de-comprobante-voucher_types)).
> Las referencias `archivo.php:línea` de este documento son anteriores a ese
> cambio y pueden estar corridas unas pocas líneas.

## 1. Overview

Una compra es una fila `Shop` que representa un comprobante recibido de un
`Provider`. El tipo lo define `voucher_type_id` (slugs del módulo `compra`):

| slug (antes `voucher_type`) | Comprobante | ¿Electrónico propio? | ¿Secuencial propio? |
|---|---|---|---|---|
| `compra_factura` (antes `1`) | Factura | No — documento externo del proveedor | No — la numera el proveedor |
| `compra_nota_venta` (antes `2`) | Nota de venta | No — documento externo | No — la numera el proveedor |
| `compra_liquidacion_compra` (antes `3`) | **Liquidación de Compra** | Sí — lo emite la empresa al SRI | **Sí** — la emite el contribuyente |
| `compra_nota_debito` (antes `5`) | Nota de débito | No — documento externo | No — la numera el proveedor |

Solo la **Liquidación de Compra** tiene ciclo de vida electrónico propio
(firma, envío, autorización, PDF generado por el sistema) **y** secuencial
propio: es el único tipo de Compras que el contribuyente mismo emite (los
otros tres los emite el proveedor — su numeración no le pertenece al
comprador, por eso `requires_sequence=false` en el catálogo para esos tres).
Los tipos externos se registran como referencia contable / soporte de la
retención (`ShopController.php:96-98`). Sobre cualquier `voucher_type_id` se
puede además emitir un **Comprobante de Retención** electrónico
(secuencial propio, slug `retencion`), independiente del estado de la
liquidación.

Modelos involucrados:

| Modelo | Tabla | Rol |
|---|---|---|
| `Shop` | `shops` | Cabecera de la compra + estado electrónico de LC y de retención |
| `ShopItem` | `shop_items` | Líneas de producto (solo se usan en LC, `voucher_type` 3) |
| `ShopRetentionItem` | `shop_retention_items` | Líneas del comprobante de retención |

`Shop` extiende `BaseModel`, que aplica el **global scope `BranchScope`**
(`app/BranchScope.php`): todas las consultas se filtran por el `branch_id`
de la *primera* sucursal (`orderBy('created_at')->first()`) de la empresa
del usuario autenticado. Esto da el aislamiento multi-tenant, incluido en el
route-model-binding (`Shop $shop` → 404 si no pertenece a la empresa).

### Campos clave de `Shop`

Definición en `database/migrations/2021_09_13_122325_create_shops_table.php`
y `..._add_bases_to_shops.php`. Casts en `app/Models/Shop/Shop.php:65-78`.

| Campo | Propósito |
|---|---|
| `branch_id`, `provider_id` | Sucursal emisora y proveedor (FKs) |
| `date` | Fecha del comprobante |
| `serie` | Serie del comprobante (estab. + punto + secuencia, 17 chars). Solo se genera server-side para liquidación — en los otros 3 tipos es el dato del proveedor tal cual se captura |
| `voucher_type_id` | FK a `voucher_types` (ver tabla arriba) |
| `doc_realeted` | Documento relacionado (para retención sobre factura) |
| `sub_total`, `total`, `discount`, `ice` | Totales de la compra |
| `no_iva`, `base0`, `base5`, `base12`, `base15` | Bases imponibles por tarifa |
| `iva5`, `iva`, `iva15` | Montos de IVA por tarifa (`iva` = monto genérico, ver §5) |
| `paid`, `expiration_days` | Pago y crédito |
| **LC electrónica** | |
| `state`, `autorized`, `authorization`, `xml`, `extra_detail` | Estado SRI de la liquidación (ver máquina de estados en el README) |
| `iva_retention`, `rent_retention` | Montos retenidos de IVA / renta |
| **Retención electrónica** | |
| `serie_retencion`, `date_retention` | Serie y fecha del comprobante de retención |
| `state_retencion`, `autorized_retention`, `authorization_retention`, `xml_retention`, `extra_detail_retention` | Estado SRI de la retención (paralelo e independiente de `state`) |

> Nota: `Shop` mantiene **dos máquinas de estado independientes** —
> `state` (liquidación) y `state_retencion` (retención) — porque sobre una
> factura externa (`voucher_type` 1) se puede emitir retención sin que haya
> liquidación. Solo `voucher_type` 3 usa `state`.

## 2. Flujo CRUD

### Crear (`store`)

`ShopController::store` → `ShopStoreService::createShop`
(`app/Services/Shop/ShopStoreService.php:27`). Todo dentro de una transacción:

1. `prepareShopData()` — descarta campos no persistibles (`taxes`,
   `pay_methods`, `app_retention`, `send`, `point_id`), resuelve el
   `VoucherType` desde `voucher_type_id` y, si `requires_sequence=true`
   (hoy solo liquidación), genera la `serie` vía
   `VoucherSequenceService::generateSerie()` y marca `state = 'CREADO'`. Si
   hay retención genera `serie_retencion` con el mismo servicio (slug
   `retencion`) y `state_retencion = 'CREADO'`. El incremento de secuencia
   ya ocurre dentro de `generateSerie()` — no hay paso aparte. Por eso
   `ShopStoreRequest.serie` es `required_unless:voucher_type_id,<id de
   compra_liquidacion_compra>` en vez de `required` fijo: para liquidación
   el valor que mande el cliente se descarta igual, exigirlo era un 422
   falso; para los otros 3 tipos (documento real del proveedor) sigue
   siendo obligatorio.
2. `createShopItems()` — crea `ShopItem` desde `data['products']`.
3. `createRetentionItems()` — crea `ShopRetentionItem`; **recalcula
   `value` server-side** vía `RetentionTotalsCalculator` antes de persistir
   (ver [facturacion-electronica/compras.md §2](./facturacion-electronica/compras.md)).
4. Fuera de la transacción, `sendVouchers()` — si `send` viene true **y**
   el tipo tiene `requires_electronic=true` (liquidación), dispara la
   emisión electrónica (`ShopLcXmlService::process` y/o
   `RetentionXmlService::process` si aplica retención).

### Editar (`update`)

`ShopController::update` → `new ShopUpdateService($shop)` →
`updateShop` (`app/Services/Shop/ShopUpdateService.php:15`). Actualiza la
cabecera (excluyendo `id`, `taxes`, `pay_methods`, `app_retention`, `send`)
y **re-sincroniza** los ítems de retención con un `delete` + `createMany`
(`:42-43`), recalculando `value` de nuevo. No re-sincroniza `shop_items`.

> El servicio se instancia manualmente en el controller (no por el
> container), por eso `RetentionTotalsCalculator` se crea con `new` directo
> y no por inyección.

### Anular (`cancel`)

`ShopLifecycleController::cancel` (liquidación) y
`RetentionController::cancel` (retención) delegan a
`ShopLcXmlService::cancel` / `RetentionXmlService::cancel`, que envían la
anulación al SRI vía `SriSoapService`. Ambos exigen que el comprobante sea
electrónico (`abort_unless voucher_type === 3` / `serie_retencion !== null`).

## 3. Validaciones y reglas de negocio

Requests: `ShopStoreRequest` y `ShopUpdateRequest`
(`app/Http/Requests/Shop/`).

- **Autorización**: solo `Auth::check()` (`ShopStoreRequest.php:12`). No hay
  policy — el aislamiento por empresa lo da `BranchScope`, no el request.
- **`voucher_type_id`**: debe existir en `voucher_types` con `module=compra`
  y `active=true` (`ShopStoreRequest.php`).
- **Identificación del proveedor según el tipo** (trait
  `App\Http\Requests\Shop\Concerns\ValidatesProviderIdentification`, usado
  por `ShopStoreRequest` y `ShopUpdateRequest`): liquidación de compra exige
  `provider.type_identification` en `cédula`/`pasaporte` — es el tipo
  reservado para proveedores **sin RUC** que no pueden emitir su propia
  factura, por eso el contribuyente liquida la compra en su lugar. Los otros
  3 tipos (factura, nota de venta, nota de débito) son documentos que el
  proveedor emitió él mismo, así que exigen `ruc`. En `update` cae al
  `voucher_type_id`/`provider_id` actuales del `Shop` cuando esos campos no
  vienen en el body (son `sometimes`).
- **Retención condicional**: `taxes` es `required_if:app_retention,true`
  (`ShopStoreRequest.php`). `serie_retencion` **no** es campo del request en
  `store` — la retención siempre es del contribuyente (`requires_sequence`
  fijo en el catálogo), el backend la genera igual que `serie` de
  liquidación. En `update` sigue siendo `required_if`, porque
  `ShopUpdateService` no vuelve a generarla — si conviene unificar ese
  camino con `VoucherSequenceService`, queda pendiente.
- **Líneas de producto** (`products.*`): `product_id` debe existir,
  `quantity`/`price`/`discount`/`iva` requeridos y `>= 0`
  (`ShopStoreRequest.php:53-58`).
- **Líneas de retención** (`taxes.*`): `code`, `tax_code`, `base`,
  `porcentage`, `value` requeridos (`ShopStoreRequest.php:61-66`). El `value`
  enviado se ignora y se recalcula server-side (ver §2 doc SRI).
- **Retención duplicada** (`ShopStoreRequest::withValidator`, `:70-97`): al
  aplicar retención, bloquea si ya existe una compra con la misma
  `serie` + `authorization` + `provider_id` con `state_retencion = AUTORIZADO`.
- **Estados protegidos en update** (`ShopUpdateRequest::after`, `:17-36`):
  impide modificar si `state` ∈ {ENVIADO, RECIBIDA, EN_PROCESO, AUTORIZADO,
  ANULADO}. **Ojo**: solo mira `state`, no `state_retencion` — ver §5.
- **Sync de retención en update** solo corre si `voucher_type < 4` y hay
  `app_retention` + `taxes` (`ShopUpdateService.php:28`).

## 4. Endpoints principales

Definidos en `routes/api.php:74-86` (grupo autenticado).

`ShopController::emisionData()` (compartido por `create`/`edit`) sigue el
mismo patrón que `OrderController::emisionData()`: `points` liviano
(id/store/point/recognition, sin preview de secuencial — se sacó el join
viejo contra `emision_point_sequences` que solo cubría liquidación/retención
y nunca se mostraba en el form) y `voucherTypes` filtrado a
`module=compra, active=true`, para que el frontend arme el selector "Tipo de
comprobante" sin hardcodear los 4 slugs de Compras.

| Método | Ruta | Acción | Controller |
|---|---|---|---|
| GET | `shops` | Listado paginado (búsqueda por serie/proveedor, filtro `date`) | `ShopController@index` |
| GET | `shops/create` | Datos para el formulario (taxes, puntos de emisión, `voucherTypes` módulo compra) | `ShopController@create` |
| POST | `shops` | Crear compra (+ emisión si `send`) | `ShopController@store` |
| GET | `shops/{shop}` | Datos para editar (ítems, retenciones, proveedor) | `ShopController@edit` |
| PUT | `shops/{shop}` | Actualizar compra | `ShopController@update` |
| GET | `shops/{shop}/pdf` | PDF de la liquidación (solo `voucher_type` 3) | `ShopController@pdf` |
| GET | `shops/{shop}/process` | Emitir/reintentar liquidación al SRI | `ShopLifecycleController@process` |
| POST | `shops/{shop}/cancel` | Anular liquidación en el SRI | `ShopLifecycleController@cancel` |
| GET | `shops/{shop}/xml` | Descargar XML de la liquidación | `ShopLifecycleController@download` |
| GET | `retentions/{id}/pdf` | PDF del comprobante de retención | `RetentionController@pdf` |
| GET | `retentions/{shop}/process` | Emitir/reintentar retención al SRI | `RetentionController@process` |
| POST | `retentions/{shop}/cancel` | Anular retención en el SRI | `RetentionController@cancel` |
| GET | `retentions/{shop}/xml` | Descargar XML de la retención | `RetentionController@download` |

**Listado** (`ShopController::index`, `:27`): join con `providers`, orden por
`created_at` desc, búsqueda `LIKE` sobre `shops.serie` y `p.name` (con
escape de `%`/`_`), filtro opcional `whereDate('shops.date')`. Devuelve
`ShopResources`.

## 5. Puntos de mejora

Hallazgos concretos con evidencia. Los totales de `Shop` confiados al
frontend (sin `ShopTotalsCalculator`) son el pendiente mayor y ya están
documentados en
[facturacion-electronica/compras.md §1 y §4](./facturacion-electronica/compras.md);
no se repiten aquí. Lo siguiente es específico del módulo CRUD:

1. **El guard de estados protegidos ignora la retención**
   (`ShopUpdateRequest.php:31` + `ShopUpdateService.php:42`). `after()` solo
   compara `$shop->state`, pero para `voucher_type` 1 y 2 ese campo es
   **siempre null** (solo `voucher_type` 3 setea `state='CREADO'`, ver
   `ShopStoreService.php:53-56`). Resultado: un `update` sobre una factura
   con retención ya **AUTORIZADA** pasa el guard, y `syncRetentionItems`
   hace `delete` + `createMany` de los ítems de retención de un comprobante
   ya autorizado ante el SRI. Debería también bloquear cuando
   `state_retencion` esté en un estado protegido.

2. **`ShopResources` expone un campo inexistente** (`ShopResources.php:33`):
   `'retention' => $this->retention`. No hay columna `retention` en `shops`
   (la migración define `iva_retention`/`rent_retention`) ni accesor, y el
   query de `index` selecciona `shops.*, p.name, p.email`. El campo sale
   siempre `null`; es código muerto o un alias mal escrito.

3. **`store()` devuelve el modelo crudo** (`ShopController.php:64`):
   `response()->json($shop, 201)` en vez de `ShopResources`. Filtra todos
   los campos internos (`xml`, `state`, `extra_detail`, etc.) e es
   inconsistente con `index`, que sí usa el resource.

4. **~~Comentario `voucher_type` desactualizado~~ (resuelto por el refactor
   2026-09)**: la columna `voucher_type` (int) fue eliminada de `shops`;
   ahora es `voucher_type_id` (FK), con nombre/código/flags en una sola
   fuente de verdad (`voucher_types`, seed en `VoucherTypeSeeder`) en vez de
   comentarios de migración y labels hardcodeados en varios archivos.

5. **El módulo asume una sola sucursal por empresa.** Tanto la creación
   (`ShopStoreService.php:24`, `branches()->orderBy('created_at')->first()`)
   como el `BranchScope` (`app/BranchScope.php`) usan siempre la *primera*
   sucursal. En una empresa multi-sucursal, las compras de sucursales
   distintas a la primera serían invisibles/inaccesibles. Es un límite de
   diseño transversal (no solo de Compras), pero afecta directamente al
   scope de este módulo.

6. **`update` no re-sincroniza `shop_items`** (`ShopUpdateService.php`): al
   editar una liquidación de compra se actualiza la cabecera y las
   retenciones, pero las líneas de producto quedan intactas. Si es
   intencional (los ítems no se editan tras crear), conviene documentarlo;
   si no, es una omisión.

7. **~~No se enviaba correo al proveedor al autorizar liquidación/retención~~
   (resuelto)**. `ShopLcXmlService::authorize()` y
   `RetentionXmlService::authorize()` tenían `onAuthorized: fn () => null`
   — a diferencia de Ventas (`OrderSriService::sendOrderMail`), nunca se
   armaba el correo. Los mailables `ShopShipped`/`RetentionShipped` que sí
   existían eran código muerto de la migración Lumen→Laravel (referenciaban
   `App\Http\Controllers\Api\*Controller`, clases que no existen en este
   proyecto Laravel; nunca se invocaban). Fix: `app/Mail/ShopLcShipped.php`
   (reemplaza al `ShopShipped` roto) y `RetentionShipped.php` reescritos
   siguiendo el patrón de `OrderShipped` (companyId/replyTo resueltos desde
   el propio modelo, sin `Auth::user()`, para funcionar igual en el Job de
   colas que en el request HTTP), enganchados en `onAuthorized` de ambos
   services. Destinatario: `Provider::find($shop->provider_id)->email` — si
   el proveedor no tiene correo registrado, no se envía (mismo criterio que
   Ventas con `Customer`). Flags `send_mail_set_purchase`/
   `send_mail_retention` (ya existían en la tabla `shops`, sin usar) ahora
   se marcan `true` tras el envío. `ShopLcPdfService` ganó un `savePdf()`
   (antes solo tenía `stream()`) para poder adjuntar el PDF igual que
   `RetentionPdfService`/`OrderPdfService`.
