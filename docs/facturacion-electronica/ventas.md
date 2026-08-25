# Facturación Electrónica — Ventas (`Order`)

Ver máquina de estados, firma y temas compartidos en [README.md](./README.md).
Para lógica de formulario/UI ver `facec-front-next/docs/ventas.md`.

## 1. Flujo completo (Order / Factura)

```
1. Frontend guarda el Order (OrderStoreService / OrderUpdateService)
   → sub_total, total, base0-15, iva5/8/iva/iva15, ice, discount
     se recalculan server-side antes de persistir (ver §3, OrderTotalsCalculator).

2. OrderLifecycleService::process($order)
   estado CREADO/DEVUELTA/NO AUTORIZADO
     → buildXml()  (arma InvoiceBuilder o CreditNoteBuilder desde $order + items)
     → VoucherLifecycleService::saveAndSign()
         a. guarda XML sin firmar en storage: xmls/{ruc}/{año}/{mes}/CREADO/{claveAcceso}.xml
         b. state = CREADO, authorization = claveAcceso, xml = ruta
         c. llama a go-signer (XmlSignerService::sign → POST {XML_SIGNER_URL}/sign)
         d. si firma OK: mueve a .../FIRMADO/{claveAcceso}.xml, state = FIRMADO
     → onSigned callback → OrderSriService::send($order)

3. OrderSriService::send()
     → si order->date no es hoy: solo actualiza la fecha y vuelve a CREADO
       (evita mandar un comprobante con fecha vencida)
     → SriSoapService::send()  (SOAP validarComprobante contra RecepcionComprobantes)
         - RECIBIDA   → state = RECIBIDA → callback onReceived → authorize()
         - DEVUELTA   → state = DEVUELTA, extra_detail = mensaje SRI parseado
                        (ver §3.1, error histórico ya corregido)

4. OrderSriService::authorize() / SriSoapService::authorize()
     → SOAP autorizacionComprobante contra AutorizacionComprobantes
     → saveAuthorizationResult():
         - AUTORIZADO → mueve XML a .../AUTORIZADO/, guarda numeroAutorizacion
                        y fecha, dispara email (MailController::orderMail)
         - NO AUTORIZADO → state = NO AUTORIZADO, guarda mensaje SRI
         - otro (en proceso) → state = EN_PROCESO
```

`OrderLifecycleService::process()` es llamado repetidamente (reintentos /
cron) y avanza el comprobante según el estado en que quedó la última vez —
por eso late-binding: un comprobante `DEVUELTA` con
`extra_detail === 'CLAVE ACCESO REGISTRADA.'` se reintenta directo en
`authorize()` en vez de reconstruir el XML.

## 2. Construcción del XML (`InvoiceBuilder`)

`app/Xml/InvoiceBuilder.php` arma el XML de factura tomando los valores
**ya calculados** desde el modelo `Order` y sus `OrderItem` — no recalcula
nada, solo formatea:

| Campo XML | Origen |
|---|---|
| `totalSinImpuestos` | `$order->sub_total` (línea 67) |
| `totalDescuento` | `$order->discount` (línea 68) |
| `totalImpuesto[].baseImponible` / `.valor` (IVA) | `$order->base{0,5,8,15}` / `$order->iva{,5,8,15}` — leídos directo por `HasItemTaxes::groupTaxes($items, $order)`, **no** se suma `quantity*price` de los ítems (ver §3.1, fix 2026-08-24) |
| `totalImpuesto[].valor` (ICE, code 3) | `$order->ice` (sin columna de base persistida para ICE, esa sí se sigue sumando desde `$items`) |
| `descuentoAdicional` (solo tax code 2 / IVA) | `$order->discount - Σ(item.discount)` (línea 82-85) |
| `importeTotal` | `$order->total` (línea 105) |
| `pago.total` | `$order->total` (línea 111) |
| `detalle.precioTotalSinImpuesto` (por línea) | `quantity * price + ice - discount` recalculado por línea (línea 120-121) — este sí se calcula aquí: es el desglose por ítem exigido por el esquema SRI, no existe columna persistida equivalente por línea |
| `detalle.impuestos.valor` (por línea, IVA) | `HasItemTaxes::itemImpuestos()`: `percentage * (subTotal + ice - discount) * .01` calculado **sobre la base sin redondear** para evitar doble redondeo (ver §3.1) |

`CreditNoteBuilder` (nota de crédito) sigue exactamente el mismo patrón —
mismo `groupTaxes()`, mismo bloque `<detalles>` compartido vía
`HasItemTaxes::renderDetalles()` (parametrizado por tag de código de
producto: `codigoPrincipal`+`codigoAuxiliar` en factura,
`codigoInterno` sin auxiliar en nota de crédito).

## 3. Errores detectados

### 3.1 — `ERROR EN DIFERENCIAS` al enviar Factura

**Síntoma real:**
```
El importe total esperado 69.0 no coincide con el calculado 48.79:
total sin impuestos 39.79 - total descuento adicional 0.0 -
total devolucion iva 0.0 - total compe...
```
SRI recalcula `importeTotal` a partir de `totalSinImpuestos` + impuestos del
XML y lo compara contra el `importeTotal` que mandamos. Si no coincide,
devuelve `DEVUELTA` con este mensaje (capturado en
`SriSoapService::parseReturnedMessage()` y guardado en
`order->extra_detail`).

**Causa raíz original — el backend no recalculaba los totales de dinero:**
`OrderUpdateService`/`OrderStoreService` persistían `sub_total`, `total`,
`base0..15`, `iva5/8/iva/iva15`, `discount`, `ice` **tal cual los enviaba
el frontend**, sin recalcularlos desde `orderitems`, y `InvoiceBuilder`
tomaba esos campos de BD sin validar que cuadraran entre sí ni contra la
suma real de items.

**Escenario de falla concreto:** se guarda la factura una primera vez y
todo cuadra (frontend calculó bien en ese momento). Al **editar** la
factura, un bug conocido de recálculo de totales en el frontend
(`facec-front-next/docs/ventas.md`, sección `## Errores detectados` —
p.ej. doble descuento en `Totals.tsx`, o `recalculate` que no resta el
descuento global) deja `order.total` desincronizado de `order.sub_total`
y de la suma real de los items. El backend guardaba esos valores
inconsistentes sin chequearlos, armaba el XML, lo firmaba (gasta la clave de
acceso) y lo enviaba — SRI lo rechazaba porque `totalSinImpuestos` (39.79)
más los impuestos calculados (48.79) no coincidían con el `importeTotal`
(69.0) que se mandó.

> Consecuencia adicional: como la firma y el envío ya se ejecutaron con
> datos inválidos, se "quema" una clave de acceso y hay que corregir y
> reintentar — no es solo un error cosmético, cuesta un ciclo completo.

**Estado: IMPLEMENTADO (opción a — recálculo server-side como fuente de
verdad).** `app/Services/Order/OrderTotalsCalculator::calculate()` recalcula
`no_iva`, `base0..base15`, `iva5/8/iva/iva15`, `ice`, `sub_total` y `total`
desde el array de `products` del payload antes de persistir, y sus valores
sobrescriben lo que envía el frontend.

- **Fórmula:** por ítem `itemBase = quantity * price - discount + ice`
  (**sin redondear** — ver bug de doble redondeo abajo); se acumula en el
  bucket de base según el código `iva` del ítem (`6→no_iva`, `0→base0`,
  `5→base5`, `8→base8`, `4→base15`). Los IVAs se derivan de cada base **sin
  redondear** por su tarifa (`ivaX = round(baseX_sin_redondear * tarifa /
  100, 2)`), con las tarifas traídas de la tabla `iva_taxes` (`IvaTax`), no
  hardcodeadas. Las bases (`base0..base15`) recién se redondean al final,
  para persistir. Finalmente `sub_total = Σ bases_redondeadas`,
  `total = sub_total + ice + iva_total - discount` (donde `discount` es el
  descuento global de la orden).

- **Bug de doble redondeo (2026-08-24):** `itemBase` y las bases por tarifa
  se redondeaban a 2 decimales **antes** de multiplicar por el porcentaje
  de IVA (`round(qty*price,2)` por ítem, y otra vez `round($base,2)` antes
  de sacar el IVA). Con montos no redondos (p.ej. `price = 0.434783`,
  típico de precios con IVA incluido desglosado) esto perdía precisión:
  `0.434783 → round → 0.43 → *0.15 → round → 0.06`, cuando el valor correcto
  es `0.434783 * 0.15 = 0.0652... → round → 0.07`. El `total` quedaba `0.49`
  en vez de `0.50`. Fix: el IVA se calcula sobre la base **sin redondear**
  (mismo criterio que ya usaba `HasItemTaxes::itemImpuestos()` para el
  detalle por ítem, comentario explícito en ese método); solo se redondea
  el resultado final (`base*`, `iva*`) para persistir/mostrar. Test
  regresión: `OrderTotalsCalculatorTest` — *"el iva se calcula sobre la
  base sin redondear para evitar doble redondeo"*.

- **XML deja de recalcular, usa lo ya persistido (2026-08-24):** hasta este
  fix, `HasItemTaxes::groupTaxes()` (usado por `InvoiceBuilder` y
  `CreditNoteBuilder` para armar `<totalConImpuestos>`) volvía a sumar
  `quantity*price` desde `$this->items` para sacar `baseImponible`/`valor`
  por tarifa — una **segunda fuente de cálculo**, independiente de
  `OrderTotalsCalculator`, que en teoría podía desincronizarse del total
  guardado en `Order` (dos caminos de cálculo distintos para el mismo
  número). Ahora `groupTaxes($items, $order)` solo usa los ítems para saber
  **qué** códigos de IVA/tarifas aparecen (agrupación), pero el `base` y el
  `valor` de cada grupo se leen directo de `$order->base{0,5,8,15}` /
  `$order->iva{,5,8,15}` — la misma fuente de verdad que ya calculó y
  persistió `OrderTotalsCalculator`. ICE queda igual (sin columna de base
  persistida, se sigue sumando desde ítems; su `valor` ya usaba
  `$order->ice` directo desde antes). Tests:
  `tests/Unit/Xml/ItemTaxesTest.php`.

- **Bloque `<detalles>` unificado (2026-08-24):** el `foreach` que arma
  `<detalle>` por ítem era casi idéntico entre `InvoiceBuilder` y
  `CreditNoteBuilder` (solo cambiaba el tag de código de producto y si
  incluye `codigoAuxiliar`). Se extrajo a
  `HasItemTaxes::renderDetalles($items, $decimal, $codeTag, $includeAuxCode)`,
  usado por ambos builders.

- **Tolerancia y logging:** se compara `sub_total`/`total` calculado contra
  lo enviado por el frontend con una tolerancia de `0.01`
  (`OrderTotalsCalculator::TOLERANCE`). Si `abs(diff) <= 0.01` es ruido de
  redondeo y no se registra; si supera `0.01` se hace `Log::warning()` con
  contexto (`order_id`, `field`, `submitted`, `calculated`, `diff`) pero
  **no se bloquea el guardado** — igual se persiste el valor recalculado.
- **Conexión:** `OrderStoreService::prepareOrderData()`
  (`app/Services/Order/OrderStoreService.php:59-63`, `orderId = null`) y
  `OrderUpdateService::updateOrder()`
  (`app/Services/Order/OrderUpdateService.php:20-25`, pasa `$order->id`).
  Ambos servicios se resuelven por el container, así que el calculador se
  inyecta por constructor. Tests en
  `tests/Unit/Services/Order/OrderTotalsCalculatorTest.php`.

- **Tarifa 12% (código IVA 2) descontinuada — 2026-08-23:** decisión de
  negocio confirmada por el usuario: el 12% ya no se usa para comprobantes
  nuevos (la tarifa vigente es 15%, código 4; `base12`/`iva` "de por sí
  quedan en 0"). Se sacó explícitamente de la lógica de cálculo en
  `OrderTotalsCalculator::calculate()`: cualquier ítem con `iva === 2` ya
  **no se acumula** a ningún bucket de base (constante
  `DEPRECATED_12_CODE`), y `base12`/`iva` del resultado quedan fijos en
  `0.0`, sin consultar la tarifa en `iva_taxes`. Los campos `base12` e `iva`
  siguen existiendo en el modelo/BD/exports únicamente para **visualizar**
  comprobantes históricos ya emitidos con esa tarifa (no se tocó
  `app/Models/Order/Order.php`, `app/Exports/OrderExport.php`, ni
  `HasItemTaxes::groupTaxes()` — ese trait sigue siendo genérico y seguiría
  renderizando bien el XML de un comprobante viejo si se re-consultara,
  aunque en la práctica esos comprobantes ya están `AUTORIZADO` y no vuelven
  a pasar por el recálculo). Riesgo aceptado explícitamente: si alguna vez
  se edita un comprobante viejo aún en estado editable con ítems al 12%, el
  total recalculado ya no incluiría esa porción de IVA — el usuario indicó
  que ese caso no se da en la práctica.
  Tests actualizados en `tests/Unit/Services/Order/OrderTotalsCalculatorTest.php`
  (7 casos, incluye uno específico para el código 12% ignorado).

Mismo problema (antes del fix) aplicaba al módulo Compras — ver
[compras.md](./compras.md) para el estado de esa parte (todavía pendiente).

## 4. Puntos de mejora (Ventas)

- `OrderLifecycleService::process()` no valida que `company->active_voucher`
  sea `false` de forma explícita para el llamador — retorna silenciosamente
  (línea 28), lo cual puede confundirse con "ya procesado".
