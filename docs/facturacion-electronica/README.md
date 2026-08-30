# Facturación Electrónica (Backend)

Documentación del proceso backend de emisión de comprobantes electrónicos
al SRI (Ecuador). Cubre el flujo genérico compartido por **Ventas**
(`Order`), y aplicable en su diseño a **Compras** (`Shop`) y **Guía de
Remisión** (`ReferralGuide`), que reutilizan el mismo `VoucherLifecycleService`
y `SriSoapService`.

- **[Ventas](./ventas.md)** — `Order`, `InvoiceBuilder`/`CreditNoteBuilder`,
  `OrderTotalsCalculator`.
- **[Compras](./compras.md)** — `Shop`, `SettlementOnPurchaseBuilder`,
  retenciones (`RetentionTotalsCalculator`).

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

Ventas y Compras comparten esta máquina de estados vía
`VoucherLifecycleService`/`SriSoapService`, pero cada módulo la orquesta
distinto: Ventas tiene una capa dedicada `OrderLifecycleService` +
`OrderSriService` (ver flujo completo en [ventas.md](./ventas.md#1-flujo-completo-order--factura));
Compras arma y envía todo desde `ShopLcXmlService`, sin una capa
Lifecycle/Sri propia separada todavía (ver [compras.md](./compras.md)).

### Certificado y firma

La firma no ocurre en PHP: `VoucherLifecycleService::saveAndSign()` delega
a un microservicio Go (`go-signer`, ver `go-signer/main.go`) vía HTTP,
pasando `cert_path`, `password` y las rutas de entrada/salida
(`app/Services/XmlSignerService.php:11`). La URL se resuelve por
`config('services.xml_signer.url')` → env `XML_SIGNER_URL`
(`config/services.php:43`). Esto aplica igual a Ventas y Compras, es parte
de `VoucherLifecycleService`.

> Si `company->cert_dir` es `null`, `saveAndSign()` guarda el XML sin firmar
> y se detiene ahí (nunca llega a `SIGNED`) — es el comportamiento esperado
> para empresas sin certificado configurado aún, no es un bug.

## 2. Procesamiento asíncrono (colas)

Los 4 tipos de comprobante (Ventas, Compras, Guía de Remisión, Retención)
procesan vía cola en vez de bloquear el request HTTP: firmar (HTTP a
`go-signer`) + enviar + autorizar (2 llamadas SOAP al SRI) puede tardar
varios segundos y el SRI es notoriamente lento/inestable.

### 2.1 — `ProcessVoucherJob` + `VoucherJobRegistry`

Un solo Job genérico (`app/Jobs/ProcessVoucherJob.php`) reutilizado por los
4 tipos, en vez de un Job por tipo o por etapa. `app/StaticClasses/
VoucherJobRegistry.php` mapea un `voucherType` string a `[model, service,
state]`:

| `voucherType` | Modelo | Service | Campo de estado |
|---|---|---|---|
| `order` | `Order` | `OrderLifecycleService` | `state` |
| `shop` | `Shop` | `ShopLcXmlService` | `state` |
| `referral_guide` | `ReferralGuide` | `ReferralGuideLifecycleService` | `state` |
| `shop_retention` | `Shop` | `RetentionXmlService` | `state_retencion` |

Cada uno de los 4 services recibe `Company` como parámetro explícito en
`process(Model $model, Company $company)` — **no se resuelve vía
`Auth::user()->company`**, porque el Job corre sin usuario autenticado (fuera
del ciclo de request HTTP). El modelo se busca con
`$model::withoutGlobalScope('branch')->find($id)`: `App\BranchScope`
depende de `Auth::user()` para filtrar por sucursal, y sin usuario
autenticado no tiene de dónde resolver la compañía.

**Dispatch** (`ProcessVoucherJob::dispatch($voucherType, $modelId,
$companyId)->afterCommit()`) ocurre en:
- Los 4 endpoints `process()` (`OrderLifecycleController`,
  `ShopLifecycleController`, `RetentionController`,
  `ReferralGuideLifecycleController`) — responden `succes:true` de inmediato,
  **no** con el resultado final del SRI. El frontend debe hacer *polling*
  del estado (`GET` del recurso) en vez de leer el resultado del POST/GET de
  `process()`.
- Los 3 disparadores automáticos al crear el comprobante
  (`OrderStoreService::sendToSRI`, `ShopStoreService::sendVouchers`,
  `ReferralGuideStoreService::createReferralGuide`).
- El flujo de lote por Excel (`OrderLotService::store`, ver
  [ventas.md](../ventas.md#carga-por-lote-ordercontrollerstorelot--orderlotservicestore))
  — un job por cada fila del lote (hasta 2000), en cola `lots` (§2.5), en vez
  de firmar+enviar todas las órdenes seguidas dentro del mismo request.

`ShouldBeUnique` + `WithoutOverlapping` (clave `voucher:{type}:{id}`) evitan
que un doble-click encole el mismo comprobante dos veces.

### 2.2 — Reintento manual, no el backoff nativo de Laravel

Como §2.3 documenta, `SriSoapService::send()/authorize()` atrapan sus
propias excepciones y nunca las relanzan — el retry automático de Laravel
(basado en excepciones no capturadas) **nunca se dispara**. Por eso
`ProcessVoucherJob::handle()` llama a `process()` y después **revisa el
`state` del modelo** para decidir si reintentar:

```php
if ($this->attempts() < $this->tries && $this->stillPending($model->{$stateField})) {
    $this->release(self::BACKOFF[...]); // [30, 60, 120, 180, 300, 300, 600, 600]
}
```

`stillPending()` es el inverso de `VoucherStates::FINAL_STATES`
(`AUTHORIZED`, `CANCELED`, `PENDIENTE DE ANULAR`) — **incluye `RETURNED` y
`REJECTED`**, no solo los estados "en curso" (`SIGNED`/`SENDED`/`RECEIVED`/
`IN_PROCESS`). Esto no es opcional: `process()` trata `RETURNED`/`REJECTED`
como "reconstruir XML y reenviar" (ver el `match` de estado en cada
`*LifecycleService::process()`), así que si el Job no los considera
"pendientes" deja de reintentar comprobantes rechazados por el SRI después
de un solo intento — fue justamente el primer bug encontrado en producción
de este mecanismo.

Con 8 intentos agotados sin avanzar, el comprobante queda en su último
estado (visible en el listado) — el mismo botón "procesar" del frontend
sirve de reintento manual una vez expira `uniqueFor` (15 min).

### 2.3 — Reenvío automático tras `EN_PROCESO` repetido

Si `authorize()` consulta y el SRI devuelve `EN_PROCESO` **3 veces
seguidas**, se fuerza un reenvío del mismo XML firmado (no se reconstruye ni
se re-firma) en vez de seguir solo consultando. Contador por comprobante en
columnas `in_process_attempts`/`in_process_attempts_retention` (no puede
vivir en `attempts()` del Job, que cuenta *todos* los reintentos —
firma+envío incluidos — no específicamente cuántas veces seguidas dio
`EN_PROCESO`). Lógica centralizada en
`SriSoapService::saveAuthorizationResult()` (`MAX_IN_PROCESS_ATTEMPTS = 3`),
parametrizada vía `inProcessAttemptsField` para que cada uno de los 4
services pase su propio campo.

### 2.4 — Worker de colas: reiniciar tras cada cambio de código (dev)

`compose.yaml` (dev) monta el código como bind mount (`.:/var/www/html`) en
el servicio `queue`. `php artisan queue:work` es un **proceso de larga
duración**: PHP no relee una clase ya cargada en memoria aunque el archivo
cambie en disco. Editar un Job/Service y no reiniciar el worker hace que
siga corriendo la versión vieja — causó errores fantasma en logs varias
veces durante el desarrollo de este mecanismo. Reiniciar con:

```bash
docker restart facec-web-queue-1
```

En producción (`compose.prod.yaml`) el servicio `queue` usa la imagen
horneada (sin bind mount) — cada `deployment/deploy.sh` (que hace `build
app` + `up -d`) recrea el container `queue` automáticamente con el código
nuevo, no requiere este paso manual.

### 2.5 — Dos colas separadas: `default` vs `lots` (producción)

Un solo worker/cola procesa 100% serial: un lote grande de un cliente dejaba
en fila a los comprobantes de todos los demás hasta que terminaba.
`compose.prod.yaml` define dos workers dedicados, **cada uno a su propia
cola** (no dos workers compartiendo la misma):

- `queue` → `php artisan queue:work --queue=default --sleep=3` — comprobantes
  creados uno a uno (`OrderStoreService::sendToSRI`,
  `ShopStoreService::sendVouchers`, `ReferralGuideStoreService`, los 4
  endpoints `process()`).
- `queue2` → `php artisan queue:work --queue=lots --sleep=3` — solo
  `OrderLotService::store()`, que despacha con `->onQueue('lots')`.

Aislamiento total: un lote de hasta 2000 filas nunca compite por worker con
un comprobante normal, y viceversa. El driver `database` reserva cada job con
`SELECT ... FOR UPDATE`, así que agregar más throughput a cualquiera de las
dos colas es tan simple como copiar su bloque con otro nombre de servicio
(`queue3`, misma cola vía `--queue=...`) — no hace falta tocar código.
Trade-off: si una cola está ociosa, su worker no ayuda a la otra (sin
fallback cruzado); para eso habría que correr `--queue=default,lots` en
alguno de los dos, a costa de perder parte del aislamiento.

## 3. Errores/mejoras compartidos (no específicos de Ventas o Compras)

### 3.1 — Excepciones SOAP silenciadas

`OrderSriService::sendLot/authorizeLot` y `SriSoapService::send/authorize`
capturan `\Exception` y solo hacen `info('... error CODE: '.$e->getCode())`
(p.ej. `app/Services/SriSoapService.php:143,199`). No se loggea el mensaje
completo ni el stacktrace, y el comprobante queda en el mismo estado sin
ninguna señal visible de que algo falló — dificulta diagnosticar timeouts o
caídas del servicio SRI. Afecta a `SriSoapService` en general, por lo tanto
a Ventas y Compras por igual. Es también la razón por la que §2.2 tiene que
revisar el `state` en vez de confiar en excepciones para el retry del Job.

## 4. Puntos de mejora generales

- Reemplazar `info()` por `Log::error()` con contexto completo
  (`$e->getMessage()`, id del comprobante) en los catch de SOAP (§3.1).
- ~~Unificar el flujo con `Shop`/`ReferralGuide` en un solo servicio
  genérico parametrizado por tipo de comprobante~~ — la capa de *dispatch*
  ya está unificada (`ProcessVoucherJob` + `VoucherJobRegistry`, §2.1), pero
  cada `*LifecycleService`/`*XmlService` sigue siendo una implementación
  separada (mismo `match` de estado, mismo `saveAndSign()`/`send()`/
  `authorize()`, pero 4 clases). Unificarlas de verdad en un solo servicio
  parametrizado por tipo sigue pendiente.

Puntos de mejora específicos de cada módulo están en
[ventas.md](./ventas.md) y [compras.md](./compras.md).
