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

## 2. Errores/mejoras compartidos (no específicos de Ventas o Compras)

### 2.1 — Excepciones SOAP silenciadas

`OrderSriService::sendLot/authorizeLot` y `SriSoapService::send/authorize`
capturan `\Exception` y solo hacen `info('... error CODE: '.$e->getCode())`
(p.ej. `app/Services/SriSoapService.php:143,199`). No se loggea el mensaje
completo ni el stacktrace, y el comprobante queda en el mismo estado sin
ninguna señal visible de que algo falló — dificulta diagnosticar timeouts o
caídas del servicio SRI. Afecta a `SriSoapService` en general, por lo tanto
a Ventas y Compras por igual.

## 3. Puntos de mejora generales

- Reemplazar `info()` por `Log::error()` con contexto completo
  (`$e->getMessage()`, id del comprobante) en los catch de SOAP (§2.1).
- Unificar el flujo con `Shop`/`ReferralGuide` en un solo servicio genérico
  parametrizado por tipo de comprobante, ya que `VoucherLifecycleService`
  y `SriSoapService` ya están diseñados para ser reutilizables (parámetros
  `xmlField`, `stateField`, etc.) pero cada builder de XML sigue viviendo
  por separado.

Puntos de mejora específicos de cada módulo están en
[ventas.md](./ventas.md) y [compras.md](./compras.md).
