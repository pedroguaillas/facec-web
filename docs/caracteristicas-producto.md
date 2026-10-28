# Características de producto (plantillas) — Plan

> **Estado: Fases 1 (configuración), 2 (productos y unidades), 3 (compras), 4 (ventas), 5 (XML y PDF) y 6 (docs y frontend) implementadas.** Documento de diseño acordado
> antes de empezar. Al implementar cada fase, mover lo construido a los docs
> del módulo correspondiente (`productos.md`, `inventario.md`, `compras.md`,
> `ventas.md`, `guias-remision.md`) y marcar la fase como hecha aquí.

## 1. Contexto

Caso origen: contribuyente que vende motos. Cada moto lleva en el
comprobante características del modelo (Marca, Año, Modelo) y
características únicas de la unidad física (Chasis, Motor, RAMV), y cada
unidad debe tener un código propio que no se repita (ej. `M` + chasis).

Se diseña genérico: el propio contribuyente configura qué características
tienen sus productos (motos hoy; IMEI, número de serie, etc. otros
clientes). Empresas sin plantillas configuradas no cambian en nada: mismo
XML, mismo PDF.

### Límite del SRI

En el XML, cada `<detalle>` admite `<detallesAdicionales>` con **máximo 3**
`<detAdicional nombre="" valor=""/>` (`nombre`/`valor` máx. 300
caracteres). Por eso: el producto puede tener **cualquier cantidad** de
características, pero solo **3** (elegidas en la plantilla) van al XML; el
PDF (RIDE) muestra **todas**.

## 2. Decisiones

| Tema | Decisión |
|---|---|
| Quién configura | El propio contribuyente, en Configuración |
| Agrupación | Plantillas por empresa (`product_templates`); una empresa puede tener varias (Moto, Cuadrón…) y productos sin plantilla |
| Cantidad de características | Sin límite por producto |
| Cuáles van al XML | En la plantilla, de **todos** sus campos (nivel modelo o unidad) se marcan máximo 3 con `in_sri` |
| PDF (RIDE) | Muestra todas las características bajo la descripción del ítem |
| Código de la unidad | `code_prefix` + valor del campo de código (ej. `M5452`). `code_prefix` **nullable**: sin prefijo, el código es el valor del campo tal cual (`5452`) |
| Unidad física | Variante del producto modelo vía `products.parent_product_id` (columna ya existente, hoy sin uso) |
| Entrada de unidades | Por registro de producto (manual) y por compra |
| Stock con inventario activo | La variante siempre tiene stock 1 |
| Factura, liquidación de compra, guía de remisión | Llevan `<detallesAdicionales>` (los campos `in_sri`) |
| Nota de crédito | **No** lleva `<detallesAdicionales>` |

## 3. Modelo de datos

```
product_templates        id, branch_id, name,
                         code_prefix (nullable), code_field_id (nullable), active

product_template_fields  id, template_id, key, label,
                         type (text|number|year),
                         level (modelo|unidad),
                         required, unique, in_sri, sort, active

products                 + product_template_id   (en el producto modelo/padre)
                         parent_product_id        (ya existe; la variante apunta al modelo)

product_details          id, product_id, product_template_field_id, value

order_items              + details JSON   (foto, ver §6)
shop_items               + details JSON
referral_guide_items     + details JSON
```

`details` (foto): `[{label, value, in_sri, sort}]`.

"Por empresa" sigue la convención del resto del sistema: `ProductTemplate`
extiende `BaseModel` (`branch_id` + `BranchScope` = sucursal más antigua de
la empresa), igual que productos y bodegas. Así el route model binding ya
devuelve 404 para plantillas de otra empresa.

`code_field_id` nullable: una plantilla sin campo de código (ej. solo
características de modelo) no genera códigos automáticos.

### Reglas de configuración

- Máximo 3 campos con `in_sri = true` por plantilla.
- `key` única dentro de la plantilla.
- `code_field_id`, si se define, debe apuntar a un campo de nivel `unidad`, `unique` y activo.
- Un campo con valores no puede cambiar su `key` ni su `level`.
- Una plantilla asignada a productos no se elimina (422, desactivar con `active = false`).
- Un campo `unique` no puede repetir valor dentro de la empresa (entre las
  `product_details` de los productos de esa empresa).
- Un campo con valores guardados no se elimina, solo se desactiva (`active = false`).

## 4. Productos y variantes

- **Producto modelo (padre)**: elige plantilla y llena los campos de nivel
  `modelo` (Marca, Año, Modelo). Lleva precio, IVA, etc.
- **Variante (unidad física)**: `parent_product_id` = modelo; llena los
  campos de nivel `unidad` (Chasis, Motor, RAMV). Hereda nombre, precio e
  impuestos del modelo. Sus características efectivas = las del modelo + las
  propias.
- **Código**: `code_prefix . valor(code_field)`; sin prefijo, solo el valor.
  La unicidad ya la garantiza `product_unique (branch_id, code)`.
- **Alta manual**: `POST products/{product}/units` desde el producto modelo.
- **Búsqueda** (`products/lookup`): busca también por valor de los campos
  `unique` (escribir chasis o motor encuentra la unidad).

## 5. Inventario

Con `company.inventory` activo:

- La variante tiene stock 1 siempre que esté disponible.
- Alta manual → saldo inicial de 1 en el kardex.
- Alta por compra → entrada de 1 al costo de la compra.
- Cantidad 1 por línea en compra y venta; no se vende con stock 0 (aunque
  la empresa permita stock negativo).
- Anular venta → vuelve a stock 1.

Sin inventario: no se puede vender una variante que ya está en otra venta
no anulada.

## 6. Compras

- La línea de compra selecciona el producto **modelo** y envía los valores
  de nivel `unidad`. `ShopStoreService` crea la variante y la línea apunta a
  ella. Cantidad fija 1 (3 motos = 3 líneas).
- Anular compra: si la unidad no se vendió → stock 0 y variante inactiva;
  si ya se vendió → no se permite anular.

## 7. Ventas y foto de características

- Al guardar la venta se copian las características efectivas de la variante
  a `order_items.details`. El XML y el PDF se generan desde esa foto, así un
  reproceso no cambia si luego se edita el producto.
- Nota de crédito: no lleva `<detallesAdicionales>`; no necesita foto. La
  devolución de stock sigue el flujo normal de inventario.

## 8. XML y PDF

| Comprobante | XML | PDF |
|---|---|---|
| Factura (`InvoiceBuilder`, vía `HasItemTaxes::renderDetalles()`) | `<detallesAdicionales>` con los `in_sri` | Todas |
| Liquidación de compra (`SettlementOnPurchaseBuilder`) | `<detallesAdicionales>` con los `in_sri` | Todas |
| Guía de remisión (`ReferralGuideBuilder`) | `<detallesAdicionales>` con los `in_sri` | Todas |
| Nota de crédito (`CreditNoteBuilder`) | No | No |

- `renderDetalles()` es compartido por factura y nota de crédito: se agrega
  un parámetro para incluir o no los detalles adicionales.
- Solo se emite `<detallesAdicionales>` si el ítem tiene características
  `in_sri`; los valores se escapan para XML.
- El RIDE debe reflejar el XML; mostrar características extra en el PDF es
  informativo. Las que valen para el SRI son las 3 marcadas.

## 9. Fases

1. ✅ **Configuración** — migraciones (`product_templates`,
   `product_template_fields`, `product_details`, `products.product_template_id`),
   modelos, factories; CRUD `settings/product-templates` con reglas de §3.
   Tests: `tests/Feature/Http/Controllers/Settings/ProductTemplateTest.php`.
2. ✅ **Productos y variantes** — plantilla en el modelo, `product_details`,
   alta manual de unidades, código, unicidad, búsqueda en `lookup`, saldo
   inicial en kardex. Servicios `ProductDetailService` / `ProductUnitService`.
   Tests: `tests/Feature/Product/ProductTemplateDetailsTest.php`,
   `tests/Feature/Product/ProductUnitTest.php`.
3. ✅ **Compras** — alta de unidad desde la línea de compra, entrada al kardex,
   reglas de edición y anulación. Validación `Shop\Concerns\ValidatesUnitLines`.
   Tests: `tests/Feature/Inventory/ShopUnitPurchaseTest.php`.
4. ✅ **Ventas** — foto en `order_items.details`, cantidad 1, bloqueo de venta
   repetida (con y sin inventario). Servicio `UnitSaleService`.
   Tests: `tests/Feature/Inventory/OrderUnitSaleTest.php`.
5. ✅ **XML y PDF** — factura, liquidación, guía; nota de crédito sin cambios.
   Trait `Xml\Concerns\RendersDetallesAdicionales`; parcial
   `vouchers/theme/itemdetails.blade.php`.
   Tests: `tests/Feature/Inventory/UnitVoucherXmlPdfTest.php`.
6. ✅ **Docs y frontend** — secciones en `productos.md` §8, `inventario.md`
   §8, `compras.md` §6, `ventas.md` §6, `guias-remision.md` §8 y
   `facturacion-electronica/{ventas,compras}.md`; contrato (§11–§15)
   comunicado a `facec-front-next`.

Cada fase con tests Pest, incluido: una factura sin características genera
exactamente el mismo XML que hoy.

## 10. Resuelto en la fase 5

- **Guía de remisión**: sus ítems solo guardan `product_id` y cantidad, sin
  foto. El XML y el PDF toman las características actuales del producto de
  la línea. Si la línea es la unidad (copiada de la factura o elegida a mano
  buscando por chasis/motor), lleva sus datos; si es el modelo, solo los de
  nivel `modelo`. La unidad no se puede editar una vez usada, así que sus
  datos no cambian después.
- **Liquidación de compra**: igual que la guía (características actuales
  de la unidad que creó la compra).

## 11. API — Configuración (Fase 1)

Grupo `auth:sanctum` + `role:client`. Controlador
`App\Http\Controllers\Settings\ProductTemplateController`, validación
`ProductTemplateRequest`, guardado `ProductTemplateService`.

| Método | Ruta | Nombre |
|---|---|---|
| GET | `settings/product-templates` | `settings.product-templates.index` (con `fields`) |
| POST | `settings/product-templates` | `settings.product-templates.store` → 201 |
| GET | `settings/product-templates/{productTemplate}` | `settings.product-templates.show` |
| PUT | `settings/product-templates/{productTemplate}` | `settings.product-templates.update` |
| DELETE | `settings/product-templates/{productTemplate}` | `settings.product-templates.destroy` |

Payload de store/update (se envía **la lista completa** de campos; los que no
vienen se eliminan; `code_field_key` ausente deja la plantilla sin campo de
código):

```json
{
  "name": "Moto",
  "code_prefix": "M",
  "code_field_key": "chasis",
  "active": true,
  "fields": [
    { "label": "Marca", "type": "text", "level": "modelo", "required": true },
    { "label": "Año", "type": "year", "level": "modelo", "required": true },
    { "id": 12, "key": "chasis", "label": "Chasis", "type": "text", "level": "unidad",
      "required": true, "unique": true, "in_sri": true, "sort": 3, "active": true }
  ]
}
```

- `id`: solo en update, para editar un campo existente de esa plantilla.
- `key`: opcional; si falta se deriva del label (`"Año"` → `ano`). Formato `[a-z0-9_]`, sin repetir.
- `type`: `text|number|year`. `level`: `modelo|unidad`.
- `sort`: opcional; por defecto la posición en la lista.
- Errores 422 relevantes: `fields` (más de 3 `in_sri` activos; campo con
  valores eliminado o con key/level cambiado), `code_field_key` (no es de
  unidad/único/activo), `name` (repetido en la empresa), `fields.N.key` (repetida).

## 12. API — Productos y unidades (Fase 2)

### Producto modelo (`products.store` / `products.update`)

Campos nuevos, opcionales:

- `product_template_id`: plantilla activa de la empresa (en update se acepta
  conservar una ya desactivada). `null` quita la plantilla y borra sus
  características.
- `details`: `{key: valor}` de los campos de nivel `modelo`. Se recortan
  espacios; claves de otros niveles o desconocidas se ignoran.

Si el payload de update no trae `product_template_id` ni `details`, las
características no se tocan (compatibilidad con clientes actuales).

Reglas: obligatorios/tipo (`number` numérico, `year` 4 dígitos)/máx. 300 →
`details.{key}`; modelo con campos de nivel `unidad` activos no acepta
`stock > 0` (`stock`); no cambia de plantilla ni se elimina si tiene unidades
(`product_template_id` / 422 en destroy); una unidad no lleva plantilla propia.

`products.create` / `products.edit` devuelven `productTemplates` (activas, con
campos activos). `products.edit` además `details` (`{key: valor}` propios) y
`characteristics` (efectivas, ver abajo). `products.store` devuelve `details`.

### Unidades

| Método | Ruta | Nombre |
|---|---|---|
| GET | `products/{product}/units` | `products.units.index` (orden por código) |
| POST | `products/{product}/units` | `products.units.store` → 201 |
| PUT | `products/{product}/units/{unit}` | `products.units.update` |

Payload: `{ "details": {"chasis": "5452", "motor": "45262", "ramv": "6346653"}, "code": null }`.
`code` solo se usa (y es obligatorio) si la plantilla no tiene campo de código.

Respuesta: `{id, parent_product_id, code, name, price1, stock, details, characteristics}`.

- La unidad copia del modelo nombre, precios, impuestos, categoría, etc.
  (`ProductUnitService::INHERITED_ATTRIBUTES`); `stock = 1`. Con inventario
  activo entra al kardex como saldo inicial (`OpeningBalanceFromStockService`).
- Código = `code_prefix` + valor del campo de código, máx. 25; no puede
  repetir el de otro producto (incluidos eliminados) ni presentación →
  error en `details.{campo_código}` (o `code`).
- Campo `unique` repetido (entre productos no eliminados) →
  `details.{key}`: "Ya existe un producto con Chasis 5452.".
- Modelo sin plantilla o servicio → `product`. Unidad ya usada en ventas,
  compras o guías → no se edita (`product`). Unidad de otro modelo → 404.
- Eliminar una unidad: `DELETE products/{id}` existente.
- En `products.update` de una unidad se ignoran `stock` y las características.

`characteristics` = características efectivas (modelo + unidad), solo campos
activos, orden `sort`: `[{label, value, in_sri, sort}]`.

### `products` (index) y `products/lookup`

El frontend busca productos en ventas, compras, inventario y guías con
`GET products?search=` (no con `lookup`). Ambos endpoints también encuentran por el valor de campos `unique` activos (chasis, motor) y
cada resultado trae `parent_product_id`, `product_template_id` (solo index) y `characteristics` (vacío si no usa
plantilla); en el index van al nivel raíz del recurso, junto a `presentations`.

## 13. API — Compras con unidades (Fase 3)

`shops.store` / `shops.update`: cada línea de `products` admite además:

- `unit`: `{key: valor}` de nivel `unidad` sobre el **producto modelo**
  (`product_id` = modelo). Registra la moto nueva y la línea queda apuntando
  a ella.
- `unit_code`: código manual, solo si la plantilla no tiene campo de código.

```json
{ "product_id": 10, "quantity": 1, "price": 1800, "discount": 0, "iva": 0,
  "unit": { "chasis": "5452", "motor": "45262", "ramv": "6346653" } }
```

Reglas (`ValidatesUnitLines`):

- Cantidad 1 y sin presentación en toda línea de unidad (nueva o existente) →
  `products.N.quantity` / `products.N.presentation_id`.
- Modelo cuya plantilla tiene campos de nivel `unidad`, sin `unit` →
  `products.N.unit` ("Ingresa los datos de la unidad (Chasis, Motor).").
- Datos de la unidad: mismas reglas que el alta manual →
  `products.N.unit.{key}`, `products.N.unit_code`, `products.N.product_id`.
  Valores únicos o código repetidos entre líneas del mismo documento →
  `products.N.unit.{key}` ("Está repetido en otra línea de la compra.").
- Solo tipos que suman stock (factura, nota de venta, liquidación); otro
  tipo (nota de débito) con `unit` → `products.N.unit`.
- Línea con una unidad existente (`product_id` de la variante): solo si no
  está en stock (recompra tras venderse) o si ya estaba en esta compra →
  `products.N.product_id` ("La unidad M1000 ya está en stock.").
- Al editar, quitar una unidad que ya se vendió (venta no anulada) →
  `products`.

`shops.edit`: cada `shop_items[]` trae `parent_product_id` y
`characteristics` (vacío si la línea no es una unidad).

Efectos:

- Con inventario la unidad nace en stock 0 y la compra la sube a 1 con su
  costo (sin saldo inicial). Sin inventario, `stock = 1`.
- Al editar, las unidades quitadas que no se usan en otro comprobante se
  eliminan (soft delete si ya tienen kardex; el kardex se revierte con la
  edición).
- Anular la liquidación (`shops.cancel`): 422 si alguna unidad de la compra
  ya se vendió; si se anula, las unidades pasan a stock 0 y soft delete.
  Facturas/notas de venta de proveedor no tienen anulación en el sistema.

Límite: el código de una unidad eliminada sigue ocupado
(`product_unique (branch_id, code)` incluye eliminados), así que el mismo
chasis no puede volver a registrarse con el mismo prefijo.

## 14. API — Ventas con unidades (Fase 4)

`orders.store` / `orders.update`: la línea apunta a la **unidad**
(`product_id` de la variante, la que devuelve `products/lookup` al buscar
por chasis/motor). Reglas (`UnitSaleService::lineErrors`, no aplica a la
nota de crédito):

- Cantidad 1 y sin presentación → `products.N.quantity` /
  `products.N.presentation_id`.
- La misma unidad en dos líneas → `products.N.product_id` ("La unidad M5452
  está repetida en otra línea.").
- Factura / nota de venta:
  - Con inventario, unidad con stock 0 → `products.N.product_id` ("La unidad
    M5452 no está en stock."), aunque la empresa permita stock negativo.
  - Sin inventario, unidad en otra venta vigente → "La unidad M5452 ya se
    vendió.". Vendida = más facturas/notas de venta vigentes que notas de
    crédito vigentes (`ProductUnitService::soldUnitIdsQuery`).
  - Al editar, las unidades que ya estaban en la venta siguen permitidas.
  - Modelo con campos de nivel `unidad` → `products.N.product_id`
    ("Selecciona la unidad de MOTO M1 R1 2026 (Chasis, Motor)."). La
    proforma sí acepta el modelo (cotización), pero
    `orders/{id}/convert-to-invoice` responde 422 con ese mensaje.
- El lote (`orders/lot`) rechaza unidades y modelos por unidad: `msm` = "El
  lote no admite productos por unidad: M5452".

Foto: al crear/editar (excepto nota de crédito) cada línea guarda en
`order_items.details` las características efectivas del producto
(`[{label, value, in_sri, sort}]`, `null` si no tiene). `orders.edit`
devuelve `order_items[].details` y `order_items[].parent_product_id`.

Stock:

- Con inventario lo mueve el kardex (venta → 0, anulación o nota de crédito
  → 1).
- Sin inventario se recalcula tras crear/editar una venta y al anularla:
  0 si está vendida, 1 si no.

## 15. XML y PDF (Fase 5)

| Comprobante | Origen de las características | XML | PDF |
|---|---|---|---|
| Factura | Foto `order_items.details` | `<detallesAdicionales>` | Todas |
| Nota de venta / proforma | Foto `order_items.details` | — (no electrónicas) | Todas |
| Liquidación de compra | Producto actual de la línea | `<detallesAdicionales>` | Todas |
| Guía de remisión | Producto actual de la línea | `<detallesAdicionales>` | Todas |
| Nota de crédito | — | No | No |

- XML: solo las `in_sri`, ordenadas por `sort`, máximo 3
  (`ProductTemplate::MAX_SRI_FIELDS`), con `nombre`/`valor` escapados. Van
  después de `<precioTotalSinImpuesto>` y antes de `<impuestos>` (factura,
  liquidación) o después de `<cantidad>` (guía), como pide el XSD. Una
  línea sin características in_sri no lleva el bloque: el XML queda igual
  que antes.
- PDF (RIDE): bajo la descripción, todas las características activas:
  "Marca: M1 | Chasis: 5452 | Motor: 45262". El ticket (`printf`) y el lote
  no cambian.
