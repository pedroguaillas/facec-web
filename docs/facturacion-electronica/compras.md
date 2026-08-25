# Facturación Electrónica — Compras (`Shop`)

Ver máquina de estados, firma y temas compartidos en [README.md](./README.md).
Para lógica de formulario/UI ver `facec-front-next/docs/compras.md`.

Compras usa `Shop` (liquidación de compra) en vez de `Order`, y
`SettlementOnPurchaseBuilder` en vez de `InvoiceBuilder`/`CreditNoteBuilder`.
Todo el flujo (armar XML, firmar, enviar) se orquesta desde
`ShopLcXmlService`, sin una capa `Lifecycle`/`Sri` propia separada como
tiene Ventas (`OrderLifecycleService`/`OrderSriService`).

## 1. Totales de `Shop`: sin recálculo server-side (pendiente)

A diferencia de `Order` (ver
[ventas.md §3.1](./ventas.md#31--error-en-diferencias-al-enviar-factura)),
`Shop` **no tiene** un calculador equivalente a `OrderTotalsCalculator`.
`ShopStoreService`/`ShopUpdateService` persisten `sub_total`, `total`,
`base0`, `base5`, `base15`, `no_iva`, `iva5`, `iva15` **tal cual las envía
el frontend**, sin recalcularlas desde `shop_items` — mismo patrón de bug
que tenía Ventas antes del fix, pero sin corregir todavía.

También hay inconsistencia conocida en frontend: `shop.iva` 12%/15%
inconsistente, totales no recalculados al cambiar `voucher_type` (ver
`facec-front-next/docs/compras.md`).

La depreciación de la tarifa 12% (código IVA 2, ver
[ventas.md](./ventas.md), decisión de negocio 2026-08-23) tampoco tiene
código backend equivalente que tocar en Compras hoy: no hay ningún bucket
de 12% activo en `RetentionTotalsCalculator` (los porcentajes de retención
son tarifas de retención en la fuente, no tarifas de IVA, no están
relacionados). Si en el futuro se construye un calculador de totales para
`Shop`, debe replicar la misma exclusión del código IVA 2.

### Por qué el fix "XML sin recalcular" de Ventas NO se aplicó aquí (2026-08-24)

Se evaluó extender a Compras el mismo cambio que se hizo en
`HasItemTaxes::groupTaxes()` (ver ventas.md §3.1): que
`SettlementOnPurchaseBuilder::groupSettlementTaxes()` (hoy recalcula
`quantity*price` desde `$this->items`, igual que hacía `groupTaxes()` en
Ventas antes de su fix) lea `$shop->base{0,5,15}` / `$shop->iva{5,15}` en
vez de sumar ítems.

**Se descartó** porque, a diferencia de `Order`, esas columnas en `Shop`
**no tienen ningún recálculo server-side** (ver §1 arriba). Cambiar el XML
de Compras para leer de `Shop` habría significado firmar y enviar al SRI un
total no verificado, potencialmente peor que el recálculo-desde-ítems
actual (que al menos usa filas reales de `shop_items`, no el payload
crudo del frontend). Conclusión: `groupSettlementTaxes()` se dejó igual
(recalcula desde ítems) hasta que exista un `ShopTotalsCalculator` — ver §3.

## 2. Retenciones de Compras: `valorRetenido` confiado al frontend (fix implementado)

**Mismo patrón que ventas.md §3.1 aplicado al Comprobante de Retención de
Compras (`Shop`).** Cada línea de retención (`ShopRetentionItem`: `code`,
`tax_code`, `base`, `porcentage`, `value`) guardaba el `value` (monto
retenido) **tal cual lo enviaba el frontend**, sin recalcular que
`value == base * porcentage / 100`. `app/Xml/RetentionBuilder.php` usa
`$item->value` directo en `<valorRetenido>`, así que un valor corrupto del
frontend viajaba firmado al SRI.

**Fix implementado:** recálculo server-side como fuente de verdad en
`app/Services/Shop/Retention/RetentionTotalsCalculator.php`:

- Fórmula: `value = round(base * porcentage / 100, 2)`.
- El array de salida **siempre** lleva el `value` recalculado, nunca el del
  frontend.
- Tolerancia de `0.01` respecto al valor enviado (redondeo normal): si la
  diferencia supera `0.01` se registra con `Log::warning()` (`shop_id`,
  `tax_code`, `base`, `porcentage`, `submitted`, `calculated`, `diff`) pero
  **no** se bloquea el guardado — igual se persiste el valor recalculado.
- Si el frontend no envía `value`, solo se calcula (no se compara ni loggea).

**Conectado en** (instanciación directa `new RetentionTotalsCalculator`, no
por el container, porque `ShopUpdateService` se crea manualmente en el
controller):

- `app/Services/Shop/ShopStoreService.php:106` (`createRetentionItems()`).
- `app/Services/Shop/ShopUpdateService.php:40` (`syncRetentionItems()`).

Tests: `tests/Unit/Services/Shop/Retention/RetentionTotalsCalculatorTest.php`.

> Nota: esto cubre solo `ShopRetentionItem.value` (el comprobante de
> retención). **No** cubre los totales de la compra en sí
> (`sub_total`/`total`/`base*` de `Shop`) — ver §1.

## 3. `SettlementOnPurchaseBuilder` vs bloque `<detalles>` compartido de Ventas

Se evaluó reusar `HasItemTaxes::renderDetalles()`/`itemImpuestos()` (ver
ventas.md §2) en `SettlementOnPurchaseBuilder`, pero los ítems de compra
tienen forma de dato distinta y no calzan sin cambiar la capa de datos:

| | Ítem de venta (`OrderItem` query) | Ítem de compra (`ShopItem` query) |
|---|---|---|
| campo código producto | `codeproduct` (alias `p.code AS codeproduct`) | `code` (sin alias) |
| `discount` por ítem | sí | no existe en el query |
| `valice`/`codice` (ICE) | sí | no existe |
| `percentage` | del join con `iva_taxes` | no existe — se hardcodea `iva===4?15:0` inline (ni siquiera contempla 5%/8%) |

Forzar la reutilización implicaría tocar el query de
`ShopLcXmlService::buildXml()` para aliasear/normalizar campos que hoy
Compras simplemente no tiene (descuento e ICE por ítem). Se descartó por
abstracción prematura sin beneficio real — queda como parte del pendiente
de `ShopTotalsCalculator` (§4), si ese trabajo llega a incluir
descuento/ICE por ítem en Compras.

## 4. Pendientes

- **Falta un `ShopTotalsCalculator`** análogo a `OrderTotalsCalculator`
  (ventas.md §3.1) que recalcule `sub_total`/`total`/`base0,5,15`/
  `no_iva`/`iva5,15` desde `shop_items` antes de persistir. Es el
  bloqueante para poder aplicar en Compras el mismo fix de "XML sin
  recalcular" que ya tiene Ventas (§1).
- Si en el futuro Compras gana descuento/ICE por ítem, revisar si conviene
  unificar `SettlementOnPurchaseBuilder` con `HasItemTaxes` (§3).
