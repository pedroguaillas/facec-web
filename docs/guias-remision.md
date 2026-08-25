# Facturación Electrónica — Guía de Remisión (`ReferralGuide`)

Ver máquina de estados, firma (`go-signer`) y temas compartidos en
[facturacion-electronica/README.md](./facturacion-electronica/README.md).

## 1. Overview

Una **guía de remisión** (comprobante SRI tipo `06`) ampara el traslado
físico de mercadería entre dos puntos: sustenta *quién transporta*, *desde
dónde*, *hacia dónde* y *qué* se mueve. A diferencia de Ventas (`Order`) o
Compras (`Shop`), **no tiene totales monetarios**: sus ítems solo llevan
`quantity` (`ReferralGuideItem`), sin precio, IVA ni descuento. Por eso todo
el problema de "totales recalculados server-side" documentado en
[ventas.md](./facturacion-electronica/ventas.md#3-errores-detectados) y
compras **no aplica aquí** (no hay ningún número de dinero que cuadrar contra
el SRI).

Modelos involucrados:

| Modelo | Archivo | Rol |
|---|---|---|
| `ReferralGuide` | `app/Models/ReferralGuide/ReferralGuide.php` | Cabecera; extiende `BaseModel` → scope de sucursal automático |
| `ReferralGuideItem` | `app/Models/ReferralGuide/ReferralGuideItem.php` | Ítem (`product_id`, `quantity`); extiende `Model` plano, **sin** `BranchScope` |
| `Carrier` | `app/Models/Carrier.php` | Transportista (`identication`, `name`, `license_plate`, `email`) |
| `Customer` | (destinatario de la mercadería) | Se emite el XML contra su `identication`/`name` |

`ReferralGuide` extiende `BaseModel` (`app/Models/BaseModel.php:8`), que usa
el trait `BranchScope` (`app/BranchScope.php`): todas las consultas se filtran
por el `branch_id` de la **primera** sucursal de la empresa autenticada. Esto
evita fuga entre empresas, pero es un scope a una sola sucursal, no por
sucursal (comportamiento compartido con todos los `BaseModel`, no específico
de este módulo).

## 2. Flujo completo (creación → autorización)

El módulo **reutiliza los servicios genéricos** `VoucherLifecycleService`
(firma) y `SriSoapService` (envío/autorización), igual que Ventas. La
diferencia con Ventas: aquí una sola clase `ReferralGuideLifecycleService`
orquesta firma **y** SOAP (Ventas separa `OrderLifecycleService` +
`OrderSriService`).

```
1. store  (ReferralGuideStoreService::createReferralGuide)
     - Valida payload (ReferralGuideStoreRequest)
     - serie = substr(serie,0,8) + LPAD(emisionPoint->referralguide, 9)
       (el secuencial lo pone el backend desde emision_points, NO el frontend)
     - Crea ReferralGuide + ReferralGuideItem[] en transacción
     - Incrementa emisionPoint->referralguide
     - Si data['send'] === true → process() inmediato

2. ReferralGuideLifecycleService::process($referralguide)
     - if (! company->active_voucher) return;   (silencioso)
     - estado null/CREADO/DEVUELTA/NO AUTORIZADO:
         · caso especial DEVUELTA + extra_detail == 'CLAVE ACCESO REGISTRADA.'
           → salta directo a authorize() (no reconstruye XML)
         · buildXml()  → ReferralGuideBuilder (tipo 06)
         · VoucherLifecycleService::saveAndSign()
             - guarda XML sin firmar, state = CREADO, authorization = claveAcceso
             - go-signer (XML_SIGNER_URL) → si firma OK: state = FIRMADO
         · onSigned → send()
     - estado FIRMADO           → send()
     - estado ENVIADO/RECIBIDA/EN_PROCESO → authorize()

3. send()  → SriSoapService::send()   (validarComprobante)
     - RECIBIDA → onReceived → authorize()
     - DEVUELTA → state = DEVUELTA, extra_detail = mensaje SRI

4. authorize()  → SriSoapService::authorize()   (autorizacionComprobante)
     - AUTORIZADO   → mueve XML a .../AUTORIZADO/, guarda authorization + fecha
     - NO AUTORIZADO→ state = NO AUTORIZADO, guarda mensaje
     - en proceso   → EN_PROCESO
     - onAuthorized: fn () => null   (NO envía correo — ver §7)
```

Referencias: `ReferralGuideLifecycleService.php:20-49` (máquina de estados),
`:38-43` (firma), `:66-72` (send), `:74-80` (authorize),
`ReferralGuideStoreService.php:24-45`.

La máquina de estados es la misma `VoucherStates` que Order/Shop
(`ReferralGuideLifecycleService.php:9,30`), con idénticos valores
(`CREADO`…`AUTORIZADO`) — ver tabla en el README.

## 3. Construcción del XML (`ReferralGuideBuilder`)

`app/Xml/ReferralGuideBuilder.php` extiende `BaseVoucherBuilder` (comparte
`infoTributaria()` y la generación de clave de acceso). Solo formatea; toma
los valores tal cual del modelo y del transportista. La clave de acceso usa
`date_start` (`:20-23`) y el `serieRaw()` es `guide->serie` (`:25-28`).

El `$guide` que recibe el builder viene de un `join` con `customers` y
`carriers` (`ReferralGuideLifecycleService::buildXml`, `:51-64`), por eso
tiene los alias `ca_identication`, `ca_name`, `license_plate` (transportista)
e `identication`, `name` (destinatario/cliente).

| Campo XML | Origen | Línea |
|---|---|---|
| `<estab>`/`<ptoEmi>`/`<secuencial>` | `guide->serie` (desglosado en `BaseVoucherBuilder::infoTributaria`) | base:60-62 |
| `<codDoc>` | constante `'06'` | `voucherTypeCode()` :15-18 |
| `<dirPartida>` | `guide->address_from` | :40 |
| `<razonSocialTransportista>` | `guide->ca_name` (con `&`→`Y`) | :41 |
| `<tipoIdentificacionTransportista>` | `04` si `strlen(ca_identication)==13`, si no `05` | :42 |
| `<rucTransportista>` | `guide->ca_identication` | :43 |
| `<obligadoContabilidad>` | `company->accounting ? 'SI' : 'NO'` | :44 |
| `<fechaIniTransporte>` | `guide->date_start` (`d/m/Y`) | :45 |
| `<fechaFinTransporte>` | `guide->date_end` (`d/m/Y`) | :46 |
| `<placa>` | `guide->license_plate` (del `Carrier`) | :47 |
| `<identificacionDestinatario>` | `guide->identication` (cliente) | :52 |
| `<razonSocialDestinatario>` | `guide->name` (con `&`→`Y`) | :53 |
| `<dirDestinatario>` | `guide->address_to` | :54 |
| `<motivoTraslado>` | `guide->reason_transfer` (opcional) | :55 |
| `<codEstabDestino>` | `guide->branch_destiny` (opcional) | :56 |
| `<ruta>` | `guide->route` (opcional) | :57 |
| `<codDocSustento>` / `<numDocSustento>` | `'01'` + `guide->serie_invoice` (si hay factura sustento) | :58-59 |
| `<numAutDocSustento>` | `guide->authorization_invoice` | :60 |
| `<fechaEmisionDocSustento>` | `guide->date_invoice` (`d/m/Y`) | :61 |
| `<detalle>` (por ítem) | `codigoInterno`=`item->code`, `descripcion`=`item->name`, `cantidad`=`item->quantity` | :64-70 |

## 4. Validaciones y reglas de negocio

- `ReferralGuideStoreRequest` (`app/Http/Requests/ReferralGuide/ReferralGuideStoreRequest.php`):
  `customer_id`, `carrier_id`, `point_id` deben existir; `serie` `max:17`;
  `address_from`/`address_to`/`reason_transfer` requeridos `max:300`;
  `date_end` `after_or_equal:date_start` (`:25`); `products.*.quantity`
  `numeric min:0`. `authorize()` solo exige `Auth::check()` (`:10-13`).
- `ReferralGuideUpdateRequest`: idéntico salvo que **no** pide `point_id` ni
  `serie` (el secuencial ya está asignado y no se regenera en update).
- **Secuencial server-side:** el número de la serie no se confía del
  frontend — se toma de `emisionPoint->referralguide` y se rearma
  (`ReferralGuideStoreService::prepareData`, `:47-55`), incrementándose en la
  misma transacción (`:34-35`).
- **Bloqueo por estado en update:** `ReferralGuideUpdateService::updateReferralGuide`
  (`:13-26`) retorna sin hacer nada si el estado está en
  `[ENVIADO, RECIBIDA, EN_PROCESO, AUTORIZADO, ANULADO]` — no se puede editar
  un comprobante ya en curso o autorizado.
- **Reemplazo total de ítems en update:** borra todos los
  `referralguidetems()` y los recrea (`:31-42`); no hay diff incremental.
- **`process()` no arranca sin `active_voucher`:** retorna silenciosamente
  (`ReferralGuideLifecycleService.php:24-26`).

## 5. Endpoints principales

Definidos en `routes/api.php:88-95` (grupo autenticado).

| Método | Ruta | Acción | Controlador |
|---|---|---|---|
| GET | `referralguides` | `index` (paginado, join carrier/customer) | `ReferralGuideController` |
| GET | `referralguides/create` | `create` (puntos de emisión) | `ReferralGuideController` |
| POST | `referralguides` | `store` | `ReferralGuideController` |
| GET | `referralguides/{referralguide}` | `show` | `ReferralGuideController` |
| PUT | `referralguides/{referralguide}` | `update` | `ReferralGuideController` |
| GET | `referralguides/{id}/pdf` | `pdf` (dompdf, `vouchers.referralguide`) | `ReferralGuideController` |
| GET | `referralguides/{referralguide}/process` | `process` (firma/envío/autorización) | `ReferralGuideLifecycleController` |
| GET | `referralguides/{referralguide}/xml` | `download` (descarga XML) | `ReferralGuideLifecycleController` |

`process` captura `\Throwable` y responde `422` con `$e->getMessage()`
(`ReferralGuideLifecycleController.php:16-22`). `download` aborta `404` si el
XML no existe en storage (`:25-32`).

## 6. Relación con Ventas y Transportistas

- **Con Ventas (`Order`):** la conexión es **débil y textual**, no una FK.
  `Order` tiene una columna `guia` de tipo `string(17)` nullable
  (`database/migrations/2022_02_02_153201_add_columns_to_orders.php:17`,
  `Order::$fillable` incluye `'guia'`). `InvoiceBuilder` la emite como
  `<guiaRemision>{$order->guia}</guiaRemision>` si no es null
  (`app/Xml/InvoiceBuilder.php:63`). Es solo el **número/serie** de la guía
  tecleado en la factura para referenciarla en el SRI; **no** existe
  `referral_guide_id` en `orders` ni relación Eloquent entre ambos modelos.
  El módulo de guías y el de facturas no se conocen a nivel de datos.
- **Con Transportistas (`Carrier`):** FK real `referral_guides.carrier_id`
  (`Carrier::referralGuides()` hasMany). El transportista aporta al XML
  `ca_name` → `razonSocialTransportista`, `ca_identication` →
  `rucTransportista` (+ tipo de identificación), y `license_plate` →
  `placa` (§3). El `carrier->email` existe pero hoy no se usa (§7).
- **Sustento en factura:** los campos `serie_invoice`,
  `authorization_invoice`, `date_invoice` permiten declarar la factura que
  sustenta el traslado (`<codDocSustento>01</codDocSustento>` fijo → factura),
  pero se ingresan manualmente, no se enlazan a un `Order` concreto.

## 7. Puntos de mejora

Hallazgos concretos con evidencia:

1. **XML sin escapar → riesgo de `DEVUELTA` y clave de acceso quemada.**
   `ReferralGuideBuilder` interpola campos de texto libre crudos. Los nombres
   solo reemplazan `&`→`Y` (`:41,:53`), pero `address_from` (`:40`),
   `address_to` (`:54`), `reason_transfer` (`:55`), `route` (`:57`) y
   `item->name`/`item->code` (`:66,:67`) **no escapan nada**. Cualquier `&`,
   `<` o `>` en una dirección, motivo o descripción produce XML mal formado;
   se firma y se envía igual, el SRI lo rechaza (`DEVUELTA`) y se consume un
   ciclo de clave de acceso. Es el mismo tipo de fragilidad de datos
   confiados directo del frontend que en Ventas/Compras, aquí en su variante
   de *sanitización* (no de totales). Recomendado: `htmlspecialchars(...,
   ENT_XML1, 'UTF-8')` en todos los campos, en `BaseVoucherBuilder`.

2. **No hay guarda de fecha antes de enviar (comprobante vencido).** En
   Ventas, `OrderSriService` verifica `!$dateOrder->isToday()` y resetea la
   fecha para no mandar un comprobante con fecha vencida
   (`app/Services/Order/OrderSriService.php:26-29`).
   `ReferralGuideLifecycleService::send()` (`:66-72`) llama a
   `SriSoapService::send()` directo, sin ninguna guarda equivalente, y la
   clave de acceso se genera con `date_start` (`ReferralGuideBuilder.php:20-23`).
   Una guía con `date_start` pasado (perfectamente válido según
   `ReferralGuideStoreRequest`, que no exige que sea hoy) se firma y envía y
   puede ser rechazada por fecha. Falta el mismo chequeo.

3. **Nunca se notifica por correo al autorizar.** `authorize()` pasa
   `onAuthorized: fn () => null` (`ReferralGuideLifecycleService.php:78`),
   mientras que Ventas dispara email en `AUTORIZADO`. Existe la columna
   `referral_guides.send_mail`
   (`database/migrations/2022_10_04_000334_add_columns_all_electronic.php:23-24`,
   default `false`) y el transportista/cliente tienen `email`, pero
   `send_mail` jamás se pone en `true` ni se envía nada. O se implementa el
   correo o el campo `send_mail` es código muerto.

4. **`customs_doc` es campo muerto en el XML.** Se valida
   (`ReferralGuideStoreRequest.php:27`), es `fillable`
   (`ReferralGuide.php:13`) y persiste, pero `ReferralGuideBuilder` nunca lo
   emite (no hay `docAduaneroUnico` en el XML). Se guarda dato que no llega
   al SRI.

5. **Excepciones SOAP silenciadas (heredado).** Al reutilizar
   `SriSoapService`, este módulo hereda el problema §2.1 del README: los
   `catch (\Exception)` en `SriSoapService::send/authorize` solo hacen
   `info('... error CODE')` sin mensaje ni stacktrace, dejando la guía en el
   mismo estado sin señal de la falla. Aplica igual a guías de remisión.

Lo que **no** encontré (verificado explícitamente): no hay problema de
totales monetarios confiados del frontend porque una guía de remisión no
tiene totales — los ítems solo cargan `quantity`, sin precio ni impuestos.
El secuencial de la serie sí se calcula server-side (§4). El scope
multi-empresa está cubierto por `BranchScope` (no hay fuga entre empresas en
`index`/`show`), aunque limitado a la primera sucursal.
