# Productos (Backend)

Módulo de catálogo de productos y servicios usados como líneas en Ventas
(`Order`), Compras (`Shop`) y Guías de Remisión (`ReferralGuide`). Vive en
el namespace `App\Models\Product\` y expone un CRUD REST más un endpoint de
búsqueda rápida (`lookup`) para autocompletar en los formularios de venta.

Para el detalle del cálculo de totales/impuestos ver
[ventas.md](./facturacion-electronica/ventas.md); aquí solo se describe el
catálogo y su relación con esos módulos.

## 1. Overview

El modelo principal es `App\Models\Product\Product`
(`app/Models/Product/Product.php:14`), que extiende `BaseModel` y por tanto
arrastra el **global scope de sucursal** (ver §7.3) y `SoftDeletes`.

### Campos clave (`products`)

| Campo | Tipo | Notas |
|---|---|---|
| `branch_id` | FK `branches` | Asignado automáticamente (sucursal más antigua de la empresa) |
| `category_id` | FK `categories`, nullable | Un producto puede no estar categorizado |
| `code` | string(25) | Único por `(branch_id, code)` (`product_unique`) |
| `aux_cod` | string(25), nullable | Código auxiliar SRI; obligatorio solo si `type_product = 1` (bien) e `iva = 5` (código de categoría `ferreteria`). Opcional en `type_product = 2` (servicio), incluida la categoría `transporte` |
| `type_product` | integer | `1` = bien, `2` = servicio (validado `in:1,2`) |
| `name` | string(300) | Descripción que va al comprobante |
| `unity_id` | FK `unities`, nullable | Solo para inventarios |
| `price1` / `price2` / `price3` | decimal(14,6), nullable | Tres listas de precio; solo `price1` se expone/valida hoy |
| `iva` | integer | **Código SRI** de tarifa, no el porcentaje. FK lógica a `iva_taxes.code` |
| `ice` | integer, nullable | Código de catálogo ICE (`ice_cataloges.code`) |
| `irbpnr` | integer, nullable | Impuesto a botellas plásticas (IRBPNR) |
| `stock` | integer, nullable | Campo informativo — ver §2 (no refleja el stock real) |
| `tourism` | boolean (cast) | Marca producto de turismo/reembolso |
| `entry/active/inventory_account_id` | integer, nullable | Cuentas contables (no usadas en el flujo FE) |

`code` mide 25 por el manual de Facturación Electrónica del SRI
(`database/migrations/2019_04_15_182053_create_products_table.php`);
`aux_cod` se agregó después en
`database/migrations/2024_07_25_153143_create_lots_table.php:37`.

### Modelos del namespace `Product/`

| Modelo | Tabla | Rol |
|---|---|---|
| `Product` | `products` | Catálogo principal |
| `Category` | `categories` | Categoría (con flags `buy`/`sale` para filtrar por uso) |
| `Unity` | `unities` | Unidad de medida (inventario) |
| `IvaTax` | `iva_taxes` | Tarifas de IVA vigentes (código SRI → porcentaje) |
| `IceCataloge` | `ice_cataloges` | Catálogo de tarifas ICE (`code`, `description`) |
| `SriCategory` | `sri_categories` | Categorías del SRI para transporte / régimen IVA 5% |

**`IvaTax`** es la tabla de tarifas de IVA vigentes: PK `code` (código SRI),
`percentage` (0, 5, 12, 15 según `database/seeders/IvaTaxSeed.php`) y
`state`. El producto guarda el `code`, no el porcentaje; la traducción a
porcentaje se resuelve por join. Es la misma tabla que consume
`OrderTotalsCalculator` para el cálculo de impuestos de venta — el detalle
está en [ventas.md](./facturacion-electronica/ventas.md), aquí no se repite.

`IvaTax` es un modelo intencionalmente vacío
(`app/Models/Product/IvaTax.php`), usado solo en lecturas/joins.

## 2. Inventario y stock

Hay **dos mecanismos distintos** y conviene no confundirlos:

1. **`products.stock`** — un entero editable en el formulario del producto.
   Se acepta en `store`/`update` y se muestra en `index`/`lookup`, pero
   **no se descuenta al vender ni se incrementa al comprar** en ningún punto
   del código (ver §7.1). Es, en la práctica, un dato informativo estático.

2. **Tabla `inventories`** (`App\Models\Inventory`,
   `app/Models/Inventory.php`) — el libro de movimientos real (kardex).
   Cada fila es un movimiento tipado (`Inventario inicial`, `Compra`,
   `Venta`, `Devolución en compra/venta`, `Ajuste ingreso/salida`) con
   `quantity`, `price`, `date` y `model_id` (id de la compra o venta que lo
   originó). Relación: `Product hasMany Inventory`
   (`app/Models/Product/Product.php:60`) y `Order hasMany Inventory` por
   `model_id` (`app/Models/Order/Order.php:106`).

Los movimientos de venta se crean en
`app/Services/Order/OrderStoreService.php:98-113`, **solo si la empresa tiene
inventario activo** (`company->inventory`), dentro de la misma transacción
que la orden (`OrderStoreService.php:30`):

```php
if ($this->company->inventory) {
    $inventoryItems[] = [
        'product_id' => $product['product_id'],
        'quantity'   => $product['quantity'],
        'price'      => $product['price'],
        'type'       => 'Venta',
        'date'       => Carbon::today()->format('Y-m-d'),
    ];
}
```

No existe cálculo de saldo/stock consolidado a partir de `inventories`; el
kardex se registra pero el saldo disponible tendría que derivarse sumando
movimientos (hoy no se hace en backend).

## 3. Endpoints

Todas bajo el grupo autenticado de `routes/api.php:48-56`:

| Método | Ruta | Acción | Descripción |
|---|---|---|---|
| GET | `products/lookup` | `ProductLookupController@index` | Búsqueda rápida para autocompletar (ver abajo) |
| GET | `products` | `ProductController@index` | Listado paginado con búsqueda por `code`/`name` |
| GET | `products/create` | `ProductController@create` | Catálogos para el formulario de alta |
| POST | `products` | `ProductController@store` | Crea producto en la sucursal principal |
| GET | `products/{product}` | `ProductController@edit` | Producto + catálogos para edición |
| PUT | `products/{product}` | `ProductController@update` | Actualiza |
| DELETE | `products/{product}` | `ProductController@destroy` | Borrado suave o físico según uso |

### `index` (`ProductController.php:21-41`)

Hace `join` con `iva_taxes` para devolver el porcentaje junto al producto,
filtra por `search` (LIKE sobre `code`/`name`, con escape de `%` y `_`) y
pagina. La respuesta pasa por `ProductResources` (§ estructura anidada
`atts` + `iva`).

### `create` / `edit` (`createEditData`, `ProductController.php:99-115`)

Devuelven los catálogos que necesita el formulario, **condicionados a flags
de la empresa**:

- `ivaTaxes`: solo `state = active`; si la empresa **no** tiene `base5`, se
  excluye el código 5 (IVA 5%).
- `iceCataloges`: solo si `company->ice`.
- `sriCategories`: solo si `company->transport || company->base5`.
- `transport`: bandera de la empresa.

### `lookup` (`ProductLookupController.php:12-38`)

Endpoint ligero para el buscador de la línea de venta. Recibe `q`, devuelve
vacío si viene en blanco, y como máximo **20** productos que coincidan por
`code` o `name`, con una forma reducida y estable:
`id, code, name, price (= price1), stock, iva, ice`. No pagina ni usa
`ProductResources` — es un contrato aparte pensado para el autocompletar.

## 4. Importación y exportación (Excel)

Usa `maatwebsite/excel` (v4). Cada responsabilidad vive en su propia clase
(SRP): `App\Exports\ProductExport` genera el archivo, `App\Imports\ProductsImport`
lo parsea/valida/inserta, y `ProductController::export`/`import` solo
orquestan (resuelven la sucursal y delegan).

### Endpoints

| Método | Ruta | Acción |
|---|---|---|
| GET | `products/export` | `ProductController@export` — descarga `productos.xlsx` |
| POST | `products/import` | `ProductController@import` — sube `file` (multipart) |

Ambas rutas están registradas **antes** de `products/{product}` en
`routes/api.php` para que `export`/`import` no se interpreten como un `{product}`.

### Columnas (mismo layout en export e import, para poder reimportar el export)

| Columna | slug (heading row) | Campo `Product` |
|---|---|---|
| Código | `codigo` | `code` |
| Código Auxiliar | `codigo_auxiliar` | `aux_cod` |
| Tipo | `tipo` | `type_product` (1 = bien, 2 = servicio) |
| Nombre | `nombre` | `name` |
| Precio | `precio` | `price1` |
| IVA | `iva` | `iva` (código SRI, no el porcentaje — ver §1) |
| ICE | `ice` | `ice` |
| Stock | `stock` | `stock` |

> Decisión: el export escribe el **código SRI** de IVA (p. ej. `2`), no el
> porcentaje formateado (`12%`), para que el mismo archivo se pueda
> reimportar sin transformación. `WithHeadingRow` normaliza los encabezados
> con `Str::slug(.., '_')`, de ahí los slugs de la tabla.

### `ProductExport` (`app/Exports/ProductExport.php`)

`FromQuery` + `WithHeadings` + `WithMapping` + `WithColumnFormatting`, filtra
por `branch_id` (recibido por constructor, no depende del `BranchScope`
global). Formatea `code`/`aux_cod` como texto (`NumberFormat::FORMAT_TEXT`)
para no perder ceros a la izquierda, igual que `CustomerExport`.

### `ProductsImport` (`app/Imports/ProductsImport.php`)

`ToModel` + `WithHeadingRow` + `WithValidation` + `SkipsOnFailure` +
`WithBatchInserts`/`WithChunkReading` (chunks de 200 filas). Fila inválida →
se omite y se acumula en `failures()`, **no aborta el resto del archivo**.

Reglas por fila (`rules()`):

- `codigo`: `required` + `App\Rules\UniqueBranchScoped` (misma regla que ya
  usan Provider/Carrier/Customer) contra `(branch_id, code)`.
- `codigo_auxiliar`: `nullable` + `App\Rules\RequiredAuxCodRule`, que
  replica la regla de negocio de `ProductStoreRequest::after()` (obligatorio
  si `tipo == 1` e `iva == 5`; lee ambos de la fila del Excel, no del flag
  de empresa). Marca `public $implicit = true` para que corra aunque el
  valor venga vacío — sin eso, Laravel salta las reglas de un campo
  `nullable` cuando el valor es `null` y la validación nunca se ejecutaría.
- `tipo`, `nombre`, `precio`, `iva`, `ice`, `stock`: mismas reglas que
  `ProductStoreRequest`.

`ProductController::import` responde con `success` (bool) y `failures`
(array de `{row, attribute, errors}` por fila rechazada); no aborta si hay
errores parciales — inserta las filas válidas y reporta el resto.

### Límite conocido

No se valida "código duplicado dentro del mismo archivo" (dos filas con el
mismo `codigo` en un solo Excel); `UniqueBranchScoped` solo consulta contra
lo que ya existe en BD. Si el archivo trae códigos repetidos, la segunda
fila fallaría en el `insert` por la constraint `product_unique`, no por la
validación. No se resolvió por alcance/tiempo — si se vuelve un problema
real, agregar una regla `distinct` a nivel de todo el import (no por fila).

## 5. Validaciones y reglas de negocio

`ProductStoreRequest` (`app/Http/Requests/Product/ProductStoreRequest.php`)
y `ProductUpdateRequest` comparten reglas casi idénticas:

| Campo | Regla | Ref |
|---|---|---|
| `code` | `required` + único por `(branch_id, code)` | `ProductStoreRequest.php:25-29` |
| `type_product` | `required|integer|in:1,2` | `:30` |
| `name` | `required|string|max:300` | `:31` |
| `price1` | `required|numeric|min:0` | `:32` |
| `iva` | `required|integer|exists:iva_taxes,code` | `:33` |
| `ice` | `nullable|integer|exists:ice_cataloges,code` | `:34` |
| `aux_cod` | `nullable|string|max:10` | `:35` |
| `stock` | `nullable|integer|min:0` | `:36` |

En update, el `unique` de `code` ignora el propio producto
(`ProductUpdateRequest.php:28-31`).

**Regla de negocio — `aux_cod` obligatorio** (`ProductStoreRequest::after`,
`:40-52`, y el mismo `after()` en `ProductUpdateRequest`): obligatorio
solo si `type_product == Product::TYPE_PRODUCT` (`1`, bien) **y**
`iva == 5` (código de categoría SRI `ferreteria`) — aunque la regla base lo
marque `nullable`. En `type_product == 2` (servicio) el campo es siempre
opcional, incluidos los productos de la categoría SRI `transporte`
(`H492001`/`H492002`, ver `SriCategorySeeder`); antes de este fix el flag
`company->transport` forzaba `aux_cod` también en servicios, lo que rompía
la creación de ventas de transporte sin código auxiliar. `company->transport`
sigue usado para filtrar qué `sriCategories` se ofrecen en el formulario
(`ProductController::sriCategoriesFor`, `:162-174`), solo dejó de disparar
la obligatoriedad de `aux_cod`.

**Borrado** (`ProductController::destroy`, `:74-94`): si el producto está
referenciado por alguna venta, compra, guía o movimiento de inventario, se
hace **soft delete** (preserva integridad referencial); si no lo usa nadie,
`forceDelete()` (borrado físico). Cualquier excepción se devuelve como 422.

> `authorize()` devuelve `true` en ambos requests
> (`ProductStoreRequest.php:12`): no hay autorización por política, solo el
> aislamiento por sucursal del global scope (§7.3).

## 6. Relación con Ventas / Compras

Un producto se referencia como línea en tres tablas hijas, todas vía
`hasMany` (`Product.php:65-78`): `OrderItem` (ventas), `ShopItem` (compras)
y `ReferralGuideItem` (guías).

En la línea de venta (`OrderItem`) los impuestos y el descuento **no se leen
del producto**, se envían por payload por línea:

| Campo de la línea | Origen | Ref |
|---|---|---|
| `price` | payload (frontend) | `OrderStoreService.php:92` |
| `discount` | payload | `:93` |
| `ice` | payload (`?? 0`) | `:94` |
| `iva` | payload | `:95` |

`OrderStoreRequest` solo valida el **tipo** de estos campos, no que
coincidan con el producto guardado (`OrderStoreRequest.php:27-31`). El
producto aporta los valores por defecto en el formulario (vía `lookup`),
pero el backend confía en lo que llega. Ver §7.2.

## 7. Puntos de mejora

Hallazgos concretos con evidencia:

### 6.1 — `products.stock` nunca se actualiza (dato inexacto)

El campo se acepta en `store`/`update` y se expone en `index` y `lookup`,
pero no hay ni un `decrement`/`increment` ni asignación a `stock` en todo
`app/` fuera de los requests. Al vender solo se inserta en `inventories`
(`OrderStoreService.php:98-113`); `products.stock` queda congelado en el
valor que se tipeó al crear el producto. Consecuencia: el `stock` mostrado
en el buscador de ventas y en el listado es engañoso. Debería o bien
eliminarse del contrato, o derivarse del kardex (`inventories`).

### 6.2 — Precio e impuestos por línea se confían del frontend

`OrderStoreService.php:88-96` usa `price`, `iva`, `ice` y `discount` tal
como llegan en el payload, y `OrderStoreRequest.php:27-31` solo valida que
sean numéricos/presentes. No se compara contra `products.price1` ni contra
el `iva`/`ice` guardados del producto. Un cliente manipulado podría emitir
una factura al SRI con un precio o una tarifa de IVA distinta a la del
catálogo. Mitigación: revalidar (o al menos re-derivar el `iva`) contra el
producto en el servidor.

### 6.3 — `BranchScope` fija siempre la sucursal más antigua

El global scope (`app/BranchScope.php`) resuelve la sucursal con
`Branch::where('company_id', …)->orderBy('created_at')->first()`, y lo mismo
hace `store` al crear (`ProductController.php:52`) y `OrderStoreService.php:25`.
En una empresa con **varias sucursales**, solo se ven/crean/consultan
productos de la primera; el catálogo de las demás sucursales queda
inaccesible por API. Si el multi-sucursal es un requisito, el scope debería
tomar la sucursal del contexto (usuario/emisión), no siempre la más antigua.

### 6.4 — Inconsistencia de longitud de `aux_cod`

La columna es `string(25)` (`create_lots_table.php:37`) pero la validación
la limita a `max:10` (`ProductStoreRequest.php:35`). Códigos auxiliares del
SRI de 11–25 caracteres serían rechazados pese a caber en BD. Alinear ambos.

### 6.5 — Menores

- Typo en la clave de respuesta de `destroy`: `'succes'` en vez de
  `'success'` (`ProductController.php:85` y `:91`) — el frontend debe leer la
  clave mal escrita.
- `IvaTax` no declara `$primaryKey = 'code'` ni `$incrementing = false`
  pese a que su PK (`code`) no es autoincremental. Hoy es inocuo porque solo
  se usa por `code`/join, pero `IvaTax::find()` asumiría columna `id`
  inexistente.
- `ProductResources` (`app/Http/Resources/ProductResources.php`) depende de
  columnas (`percentage`, `iva_code`) que solo existen en el `selectRaw` de
  `index`; usarlo con un `Product` normal devolvería esos campos en `null`.
  El recurso está acoplado a esa consulta concreta.
