# Facturación Electrónica (Backend)

Documentación del proceso backend de emisión de comprobantes electrónicos
al SRI (Ecuador). Cubre el flujo genérico compartido por **Ventas**
(`Order`), y aplicable en su diseño a **Compras** (`Shop`) y **Guía de
Remisión** (`ReferralGuide`), que reutilizan el mismo `VoucherLifecycleService`
y `SriSoapService`.

Para lógica de formulario/UI ver `facec-front-next/docs/ventas.md` y
`facec-front-next/docs/compras.md`.

## 1. Máquina de estados (`App\StaticClasses\VoucherStates`)

| Constante | Valor | Significado |
|---|---|---|
| `SAVED` | `CREADO` | Comprobante guardado en BD, XML sin firmar aún |
| `SIGNED` | `FIRMADO` | XML firmado con el certificado de la empresa |
| `SENDED` | `ENVIADO` | Enviado al SRI (recepción), esperando `RECIBIDA`/`DEVUELTA` |
| `RECEIVED` | `RECIBIDA` | SRI aceptó el XML para su validación (aún no autorizado) |
| `RETURNED` | `DEVUELTA` | SRI rechazó el XML en la validación de recepción (errores de esquema/consistencia) |
| `IN_PROCESS` | `EN_PROCESO` | SRI todavía procesando la autorización |
| `AUTHORIZED` | `AUTORIZADO` | Comprobante autorizado, válido fiscalmente |
| `REJECTED` | `NO AUTORIZADO` | SRI rechazó en la fase de autorización |
| `CANCELED` | `ANULADO` | Comprobante anulado |

El estado avanza siempre a través de `OrderLifecycleService::process()`
(`app/Services/Order/OrderLifecycleService.php:24`), que actúa como
máquina de estados: según `order->state` decide si construir+firmar, enviar,
o consultar autorización.

## 2. Flujo completo (Order / Factura)

```
1. Frontend guarda el Order (OrderStoreService / OrderUpdateService)
   → sub_total, total, base0-15, iva5/8/iva/iva15, ice, discount
     se persisten TAL CUAL los envía el frontend (ver §4, bug conocido).

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
                        (ESTE ES EL ERROR DEL CASO DE HOY, ver §4)

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

### Certificado y firma

La firma no ocurre en PHP: `VoucherLifecycleService::saveAndSign()` delega
a un microservicio Go (`go-signer`, ver `go-signer/main.go`) vía HTTP,
pasando `cert_path`, `password` y las rutas de entrada/salida
(`app/Services/XmlSignerService.php:11`). La URL se resuelve por
`config('services.xml_signer.url')` → env `XML_SIGNER_URL`
(`config/services.php:43`).

> Si `company->cert_dir` es `null`, `saveAndSign()` guarda el XML sin firmar
> y se detiene ahí (nunca llega a `SIGNED`) — es el comportamiento esperado
> para empresas sin certificado configurado aún, no es un bug.

## 3. Construcción del XML (`InvoiceBuilder`)

`app/Xml/InvoiceBuilder.php` arma el XML de factura tomando los valores
**ya calculados** desde el modelo `Order` y sus `OrderItem` — no recalcula
nada, solo formatea:

| Campo XML | Origen |
|---|---|
| `totalSinImpuestos` | `$order->sub_total` (línea 67) |
| `totalDescuento` | `$order->discount` (línea 68) |
| `totalImpuesto[].baseImponible` | agrupado desde `$this->items` vía `HasItemTaxes::groupTaxes()` |
| `descuentoAdicional` (solo tax code 2 / IVA) | `$order->discount - Σ(item.discount)` (línea 82-85) |
| `importeTotal` | `$order->total` (línea 105) |
| `pago.total` | `$order->total` (línea 111) |
| `detalle.precioTotalSinImpuesto` (por línea) | `quantity * price + ice - discount` recalculado por línea (línea 120-121) — este sí se calcula aquí, a diferencia de los totales generales |

## 4. Errores detectados

### 4.1 — `ERROR EN DIFERENCIAS` al enviar Factura (caso reportado hoy)

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

**Causa raíz — el backend nunca recalcula los totales de dinero:**

- `app/Services/Order/OrderUpdateService.php:16-19`:
  ```php
  $order->update([
      ...collect($data)->except(['id', 'products', 'send', 'aditionals', 'serie'])->toArray(),
      'state' => VoucherStates::SAVED,
  ]);
  ```
  Persiste `sub_total`, `total`, `base0..15`, `iva5/8/iva/iva15`, `discount`,
  `ice` **tal cual los envía el frontend**, sin recalcularlos desde
  `orderitems`. Lo mismo en `OrderStoreService::prepareOrderData()`
  (línea 50-58).
- `app/Xml/InvoiceBuilder.php:67,105` toma esos campos de BD sin validar
  que `sub_total`/`total` cuadren entre sí ni contra la suma real de items.

**Escenario de falla concreto:** se guarda la factura una primera vez y
todo cuadra (frontend calculó bien en ese momento). Al **editar** la
factura, un bug conocido de recálculo de totales en el frontend
(`facec-front-next/docs/ventas.md`, sección `## Errores detectados` —
p.ej. doble descuento en `Totals.tsx`, o `recalculate` que no resta el
descuento global) deja `order.total` desincronizado de `order.sub_total`
y de la suma real de los items. El backend guarda esos valores
inconsistentes sin chequearlos, arma el XML, lo firma (gasta la clave de
acceso) y lo envía — SRI lo rechaza porque `totalSinImpuestos` (39.79) más
los impuestos calculados (48.79) no coinciden con el `importeTotal` (69.0)
que se mandó.

> Consecuencia adicional: como la firma y el envío ya se ejecutaron con
> datos inválidos, se "quema" una clave de acceso y hay que corregir y
> reintentar — no es solo un error cosmético, cuesta un ciclo completo.

**Estado: IMPLEMENTADO (opción a — recálculo server-side como fuente de
verdad).** `app/Services/Order/OrderTotalsCalculator::calculate()` recalcula
`no_iva`, `base0..base15`, `iva5/8/iva/iva15`, `ice`, `sub_total` y `total`
desde el array de `products` del payload antes de persistir, y sus valores
sobrescriben lo que envía el frontend.

- **Fórmula (idéntica a `HasItemTaxes::groupTaxes()` para no desincronizar
  el XML):** por ítem `itemBase = round(quantity * price, 2) - discount + ice`;
  se acumula en el bucket de base según el código `iva` del ítem
  (`6→no_iva`, `0→base0`, `5→base5`, `8→base8`, `4→base15`).
  Los IVAs se derivan de cada base por su tarifa
  (`ivaX = round(baseX * tarifa / 100, 2)`), con las tarifas traídas de la
  tabla `iva_taxes` (`IvaTax`), no hardcodeadas. Finalmente
  `sub_total = Σ bases`, `total = sub_total + ice + iva_total - discount`
  (donde `discount` es el descuento global de la orden).
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

Mismo problema (antes de este fix) aplicaba al módulo Compras (`Shop`) — ver
`facec-front-next/docs/compras.md` (`shop.iva` 12%/15% inconsistente,
totales no recalculados al cambiar `voucher_type`). **Nota:** Compras no
tiene todavía un calculador equivalente a `OrderTotalsCalculator` para
`sub_total`/`total`/`base*` — el fix de recálculo server-side en Compras
implementado hasta ahora cubre solo `ShopRetentionItem.value` (§4.3), no los
totales de la compra en sí. La depreciación del 12% para Compras, por lo
tanto, no tiene código backend equivalente que tocar hoy: no hay ningún
bucket de 12% activo en `RetentionTotalsCalculator` (los porcentajes de
retención son tarifas de retención en la fuente, no tarifas de IVA, no están
relacionados). Si en el futuro se construye un calculador de totales para
`Shop`, debe replicar la misma exclusión del código IVA 2.

### 4.2 — Excepciones SOAP silenciadas

`OrderSriService::sendLot/authorizeLot` y `SriSoapService::send/authorize`
capturan `\Exception` y solo hacen `info('... error CODE: '.$e->getCode())`
(p.ej. `app/Services/SriSoapService.php:143,199`). No se loggea el mensaje
completo ni el stacktrace, y el comprobante queda en el mismo estado sin
ninguna señal visible de que algo falló — dificulta diagnosticar timeouts o
caídas del servicio SRI.

### 4.3 — Retenciones de Compras: `valorRetenido` confiado al frontend

**Mismo patrón que §4.1 aplicado al Comprobante de Retención de Compras
(`Shop`).** Cada línea de retención (`ShopRetentionItem`: `code`, `tax_code`,
`base`, `porcentage`, `value`) guardaba el `value` (monto retenido) **tal cual
lo enviaba el frontend**, sin recalcular que `value == base * porcentage / 100`.
`app/Xml/RetentionBuilder.php` usa `$item->value` directo en `<valorRetenido>`,
así que un valor corrupto del frontend viajaba firmado al SRI.

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

## 5. Puntos de mejora

- Recalcular/validar totales monetarios server-side antes de firmar y
  enviar (§4.1) — es un documento fiscal, no debería depender 100% de
  aritmética hecha en el cliente.
- Reemplazar `info()` por `Log::error()` con contexto completo
  (`$e->getMessage()`, `$order->id`) en los catch de SOAP.
- `OrderLifecycleService::process()` no valida que `company->active_voucher`
  sea `false` de forma explícita para el llamador — retorna silenciosamente
  (línea 28), lo cual puede confundirse con "ya procesado".
- Unificar el flujo con `Shop`/`ReferralGuide` en un solo servicio genérico
  parametrizado por tipo de comprobante, ya que `VoucherLifecycleService`
  y `SriSoapService` ya están diseñados para ser reutilizables (parámetros
  `xmlField`, `stateField`, etc.) pero cada builder de XML sigue viviendo
  por separado.
