# Inventario (kardex)

Solo aplica a empresas con `companies.inventory = true` (lo activa el admin).
Sin eso, ventas/compras no generan movimientos y los endpoints de abajo
responden 422.

## 1. Modelo

**Solo productos (`type_product = 1`) manejan inventario; los servicios
nunca.** `Product::scopeStockable()` / `isStockable()` es el único criterio y
se aplica en todo el módulo:

- `InventoryLedgerService` lanza `InvalidArgumentException` si se intenta
  registrar un servicio (ningún camino de la app lo hace; es una red de seguridad).
- Ventas/compras ignoran las líneas de servicio; ajustes, inventario inicial
  y transferencias las rechazan (422 en `items.N.product_id`).
- Un servicio nunca guarda `stock`/`min_stock` (`Product::booted()` los pone
  en null; la importación Excel también; migración `2026_10_16_171530` limpió
  los existentes), así que `lookup`/`index` devuelven `stock: null` para servicios.
- Reportes (stock, valorización, costo de ventas) solo incluyen productos, aun
  si un servicio tuviera filas heredadas en el kardex. Kardex de un servicio → 422.
- Un producto con movimientos en bodega no puede cambiarse a servicio
  (422 en `type_product`).

- **`warehouses`**: bodegas de la sucursal. Si no hay ninguna,
  `WarehouseResolver` crea "Bodega principal" la primera vez que se necesita.
  Ventas y compras siempre van a la bodega **más antigua** de la sucursal
  (mismo criterio que `BranchScope`).
- **`inventories`**: el kardex. **Solo se inserta**: nunca se edita ni se
  borra. Las correcciones son movimientos de reversa.

| Campo | Uso |
|---|---|
| `product_id`, `warehouse_id` | El saldo se lleva por producto + bodega. Filas históricas con `warehouse_id` NULL quedan fuera del saldo. |
| `model_type` + `model_id` | Documento de origen (morph map: `order`, `shop`). NULL en operaciones manuales. |
| `document_uuid` | Agrupa las filas de una operación manual (inventario inicial, ajuste, transferencia). |
| `type` | Etiqueta (`InventoryMovementTypes`): Venta, Devolución en venta, Compra, Inventario inicial, Ajuste ingreso/salida, Transferencia ingreso/salida, Reversa. |
| `direction` | `in` / `out`. `quantity` siempre positiva. |
| `price` | Precio de la línea del documento (venta o compra). No es costo. |
| `unit_cost` | Costo unitario del movimiento. |
| `previous_balance` / `new_balance` | Saldo antes/después. **Stock actual = `new_balance` de la última fila (mayor id).** |
| `average_cost` / `balance_value` | Costo promedio ponderado y valor del saldo después del movimiento. |
| `reverses_id` | Si es reversa, el movimiento que anula. |
| `user_id`, `note` | Quién y por qué (obligatorio en ajustes). |

`products.stock` es una **caché**: suma del último saldo de cada bodega, la
recalcula `InventoryLedgerService` tras cada movimiento. Con inventario
activo no se edita desde el producto (`update` lo ignora). El trigger MySQL
`stock_edit` se eliminó (`2026_10_15_184111`).

## 2. Costo promedio ponderado

- **Entrada**: `valor = valor_previo + cant × costo`; `promedio = valor / saldo`.
  Sin saldo previo positivo, el promedio pasa a ser el costo de la entrada.
- **Salida**: sale al promedio vigente; el promedio no cambia.
- **Compra**: costo = `(precio × cant − descuento de la línea) / cant`.
- **Venta / nota de crédito / ajuste de ingreso sin costo**: promedio vigente.
- **Transferencia**: entra al destino con el costo con que salió del origen.
- **Reversa**: usa el `unit_cost` del movimiento original, así revertir una
  compra deja el promedio como si la compra no hubiera existido.

## 3. Comprobantes → movimientos

`InventoryMovementTypes::VOUCHER_MOVEMENTS`; solo líneas de producto (no servicios):

| Comprobante | Movimiento |
|---|---|
| Factura, nota de venta | Venta (salida) |
| Nota de crédito | Devolución en venta (entrada) |
| Compra factura, nota de venta, liquidación de compra | Compra (entrada) |
| Proforma, nota de débito, retención, guía | — |

`VoucherInventoryService::sync*()` se llama al crear (`OrderStoreService`,
`ShopStoreService`) y al editar (`OrderUpdateService`, `ShopUpdateService`; en compras `products` es opcional en el update: si no viene, se conservan las líneas). Si las líneas
vigentes del kardex ya coinciden con el documento no hace nada; si no,
revierte las vigentes y registra las nuevas. Al **anular** (el SRI confirma
`ANULADO` en `OrderLifecycleService::cancel` / `ShopLcXmlService::cancel`)
revierte todo con fecha de hoy. La fecha de los movimientos es la del
comprobante.

### Presentaciones (ej. cubeta x30)

El kardex siempre está en la **unidad base** del producto (huevo). Una línea
de venta/compra puede venir en una presentación (`products.*.presentation_id`,
de `product_presentations`):

- La línea guarda `quantity` y `price` **en la presentación** (1 cubeta a
  $4.50): eso va al comprobante, al SRI y a los totales, sin cambios.
- Guarda `presentation_id` y una **copia** de `conversion_factor` (30).
  Editar o borrar la presentación después no altera ventas/compras ya hechas.
- El kardex mueve `quantity × conversion_factor` (30 unidades) con
  `price` y `unit_cost` por unidad base (precio neto ÷ unidades).
- En ventas, `order_items.code`/`description` toman el código de la
  presentación y "Producto (Presentación)", así el XML/PDF muestran lo vendido.
  En liquidación de compra el XML/PDF lo arman con un join a la presentación.
- `products/lookup` devuelve `presentations[{id, name, code, conversion_factor,
  price}]` (precio propio o `price1 × factor`) y encuentra el producto por
  el código de una presentación.
- `conversion_factor` admite hasta 6 decimales (catálogo y copia en la línea).
  El kardex (`quantity`, `previous_balance`, `new_balance`) y `products.stock`/
  `min_stock` también van a 6 decimales para no perder esa precisión al
  multiplicar; `InventoryLedgerService` redondea cantidad y saldos a 6
  decimales antes de comparar con 0 (evita un falso "stock insuficiente" por
  ruido de float). Un factor fraccionario igual deja residuo
  (12 × 0.083333 = 0.999996): es inherente a la fracción, no a la precisión.
- `code` es único en la sucursal sin distinguir mayúsculas: no puede repetir
  el código de otra presentación ni el de un producto (`lookup` busca por
  ambos), y el `code` de un producto (alta, edición, importación Excel) no
  puede repetir el de una presentación (`AvailablePresentationCodeRule`).
- `active`: una presentación inactiva no sale en `lookup` y se rechaza (422 en
  `products.N.presentation_id`) en ventas/compras nuevas. Al editar un
  comprobante se acepta si ya estaba en sus líneas. `GET products/{product}/presentations`
  lista activas e inactivas.
- Sin `presentation_id` la línea es en unidad base (factor 1).
- Ajustes, transferencias e inventario inicial se cargan en unidad base.
- Factura recurrente: aún no guarda presentación (sus líneas van en unidad base).

## 4. Stock negativo

`companies.allow_negative_stock` (por defecto `false`; el cliente lo cambia
en `PUT settings`). Si está apagado, una venta, ajuste de salida o
transferencia que deje la bodega en negativo responde **422**
(`errors.products`) y se revierte todo el documento. Las reversas nunca se
bloquean.

## 5. Endpoints (middleware `inventory.enabled`)

| Método | Ruta | Descripción |
|---|---|---|
| GET/POST | `warehouses` | Listar / crear bodega (`name`) |
| PUT/DELETE | `warehouses/{warehouse}` | Renombrar / eliminar (422 si tiene movimientos) |
| POST | `inventory/opening-balance` | `{warehouse_id?, date, note?, items[{product_id, quantity, unit_cost?}]}`. Solo productos sin movimientos en esa bodega. |
| POST | `inventory/adjustments` | `{warehouse_id?, direction: in\|out, date, note, items[{product_id, quantity, unit_cost?}]}` |
| POST | `inventory/transfers` | `{from_warehouse_id, to_warehouse_id, date, note?, items[{product_id, quantity}]}` |
| GET | `inventory/stock` | `?search&warehouse_id&below_min&paginate`: stock, costo promedio y valor por producto, con detalle por bodega |
| GET | `products/{product}/kardex` | `?warehouse_id&from&to&paginate`: movimientos en orden de registro |
| GET | `inventory/valuation` | `?date&warehouse_id`: valor del inventario a esa fecha |
| GET | `inventory/cost-of-sales` | `?from&to&warehouse_id`: cantidad, costo, ingreso y utilidad bruta por producto |

Exportación a Excel (mismos filtros que el reporte, sin paginar):
`GET inventory/stock/export` (una fila por producto + bodega, total de
valor), `GET products/{product}/kardex/export`, `GET inventory/valuation/export`,
`GET inventory/cost-of-sales/export` (con margen % = utilidad / ventas).
Clases en `app/Exports/Inventory*Export.php`.

Sin `warehouse_id`, las operaciones van a la bodega por defecto.

## 6. Saldo inicial desde `products.stock`

`OpeningBalanceFromStockService` convierte el `stock` tipeado en un
"Inventario inicial" (costo 0) para productos sin movimientos en bodega. Es
idempotente. Corre:

- al activar `inventory` en la empresa (`CompanyService::update`),
- al crear un producto con stock o importar el Excel con inventario activo,
- con `php artisan inventory:seed-opening-balances [--company=ID]`: **correr
  una vez en producción después de migrar**, para las empresas que ya tenían
  inventario (antes el stock lo mantenía el trigger).

Para cargar el costo real, usar `inventory/opening-balance` en vez del stock
del producto, o la columna opcional **Costo** del Excel de productos (la
importación la pasa como `unit_cost` de este saldo inicial).

## 7. Límites conocidos

- Filas históricas sin `warehouse_id` (anteriores a las bodegas) no cuentan en
  el saldo ni se revierten al editar/anular el documento.

- **Nota de crédito siempre devuelve stock.** Una NC solo por descuento o
  corrección de precio también reingresa las cantidades de sus líneas.
- **Facturación por lote** (`OrderLotService`) inserta órdenes en bloque y no
  pasa por `VoucherInventoryService`: no mueve stock.
- El orden del kardex es el de registro (`id`), no el de `date`: un documento
  con fecha atrasada se registra al final. Por eso `valuation` a una fecha
  pasada es aproximada si hubo movimientos con fecha retroactiva.
- `cost-of-sales` considera solo los movimientos vigentes: una venta anulada
  después sale también de los reportes de periodos anteriores. `revenue` usa
  el precio antes del descuento.
- Ventas y compras usan siempre la bodega por defecto; elegir otra bodega por
  documento queda pendiente (requiere `warehouse_id` en el request).

## 8. Unidades (motos)

Ver `docs/caracteristicas-producto.md` §5, §13 y §14.

- Cada unidad tiene stock 1 mientras está disponible. El alta manual entra
  como saldo inicial; el alta desde compra entra con el movimiento
  `Compra`, a su costo.
- Va con cantidad 1 por línea en compra y venta. No se vende con stock 0,
  aunque la empresa tenga `allow_negative_stock`.
- Al anular una venta o emitir una nota de crédito, la unidad vuelve a
  stock 1 por el kardex. Al anular una liquidación con unidades no
  vendidas, quedan en stock 0 y soft delete.
- Sin inventario el kardex no corre: `UnitSaleService::syncStockWithoutInventory`
  pone 0 si la unidad está vendida y 1 si no.
