# Planes de suscripción

Controla qué funciones puede usar cada empresa según el plan contratado.

## 1. Planes

Definidos en `App\StaticClasses\PlanTypes`:

| Plan | Acceso | Vencimiento |
|---|---|---|
| `GRATUITO` | Igual que `FINANCIERO` (es un trial, no un nivel reducido) | Opcional al asignar; si no se manda, 1 mes |
| `FACTURADOR` | Solo facturación (ventas/compras/guías al contado) | **Obligatoria** al asignar |
| `FINANCIERO` | Facturación + cartera (crédito, anticipos, CxC, CxP, saldos) | **Obligatoria** al asignar |
| `CONTABILIDAD` | Reservado — no está en `PlanTypes::ALL`, no se puede asignar todavía | — |

`PlanTypes::FINANCIERO_LEVEL` = planes que habilitan cartera (`GRATUITO`, `FINANCIERO`).

**Todo plan vence.** Al vencer sin renovar, la empresa queda sin plan vigente y **no puede emitir ningún comprobante** (ver §4). Sigue pudiendo consultar, descargar PDF/XML y ver cartera.

## 2. Modelo de datos

Tabla `subscriptions` — **historial**, no un campo en `companies`:

- `company_id`, `plan`, `started_at` (date), `ended_at` (date, NOT NULL), `amount` (decimal 10,2, default 0 — monto pagado por el período; se serializa como número).
- Plan vigente = fila con `started_at <= hoy <= ended_at`. Ver `Company::currentSubscription()`.
- Cambiar de plan (`SubscriptionService::assignPlan`) cierra la fila vigente (`ended_at = hoy`) y abre una nueva, en una transacción.
- Empresas existentes antes de los planes: la migración `2026_10_08_120558_backfill_facturador_subscriptions_for_existing_companies` les asigna `FACTURADOR` **por 1 mes** con `amount` 0 desde el día en que corre (solo a las que no tienen ninguna suscripción; idempotente). Corre con el `migrate` manual post-deploy — el admin tiene ese mes para renovar cada empresa.

## 3. Endpoints

### Admin (`auth:sanctum` + `role:admin`)

- `POST /api/admin/companies` — **`plan` obligatorio** (`GRATUITO`/`FACTURADOR`/`FINANCIERO`); `plan_ends_at` (fecha futura) y `plan_amount` (numeric ≥ 0) obligatorios salvo para `GRATUITO`, que sin fecha es trial de 1 mes y sin monto guarda 0. La respuesta incluye `subscription`.
- `GET /api/admin/companies` (listado paginado) — cada empresa trae `last_subscription` = su última suscripción, **vigente o vencida** (`{id, company_id, plan, started_at, ended_at, ...}`, fechas Y-m-d), o `null` si nunca tuvo plan. El front determina si venció comparando `ended_at` con hoy. Eager loading vía `Company::lastSubscription()` (hasOne ofMany). `Company::subscription()` es la vigente (la usan gate/login). También trae `user: {id, user, email}` = usuario principal (primero en `company_users`, el creado con la empresa) o `null` — relación `Company::user()`.
  - **Actividad** (`CompanyService::activityStats`: 3 consultas agrupadas para todo el sistema —ventas y compras por sucursal, tokens por empresa—, cubiertas por `orders_activity_index`/`shops_activity_index`; `DB::table`, sin BranchScope; ~130 ms con 418k ventas): `last_activity_at` (ISO 8601 o null, máx. `personal_access_tokens.last_used_at` de sus usuarios; `company_users.level_id` siempre es la empresa), `orders_30d` / `shops_30d` (comprobantes con `date` ≥ hoy-30, sin `ANULADO` ni proformas), `first_voucher_at` (Y-m-d de la primera venta o compra no anulada, null = nunca emitió) y `activity_status` (`CompanyActivityStatuses`): `never` (sin comprobantes) → `inactive` (sin actividad o > 30 días) → `risk` (8–30 días, o ≤ 7 días sin comprobantes en 30 días) → `active`. Filtro `?activity=never|inactive|risk|active` (otro valor: 422): se resuelve a `whereIn`/`whereNotIn` de ids sobre `companies`, así la paginación es la normal.
- `GET /api/admin/dashboard` — tablero de todo el sistema (`AdminDashboardService`, cacheado 5 min en `admin.dashboard`, `generated_at` dice cuándo se calculó):
  - `vouchers`: comprobantes electrónicos (factura y N/C de `orders`, liquidación `shops.state`, retención `shops.state_retencion`, guía) en un `UNION ALL`. `month` (por fecha de emisión del mes en curso: `authorized`, `pending` = CREADO/FIRMADO/ENVIADO/RECIBIDA/DEVUELTA/EN_PROCESO, `rejected` = NO AUTORIZADO), `stuck` (pendientes creados hace más de 24 h y dentro de los últimos 30 días; incluye los CREADO de empresas sin firma), `by_day` (30 días, `{date, authorized, rejected}`, con ceros), `top_errors` (top 5 `extra_detail` de rechazos del mes, `{message, count}`), `stuck_companies` (top 10 `{id, ruc, company, pending, oldest_at}`).
  - `companies`: `total`, `new_month`, `new_prev_month`, `by_month` (6 meses `{month: Y-m, count}`), `activity` (`{active, risk, inactive, never}`, mismo cálculo que el listado). Sin eliminadas.
  - `subscriptions`: `by_plan` (vigentes por plan), `renewals` (última suscripción vence en ≤ 30 días: `{id, ruc, company, plan, ended_at, days_left}`, por fecha), `expired_recent` (última suscripción venció en los últimos 30 días, sin renovar), `revenue_month` (suma de `amount` de suscripciones no trial creadas en el mes).
  - `signatures`: `expired` (`sign_valid_to` pasada) y `expiring` (≤ 30 días: `{id, ruc, company, sign_valid_to, days_left}`).
- `GET /api/admin/companies/{company}/subscriptions` → `{ company: {id, ruc, company}, current, history }` (`history` ordenado del más reciente al más antiguo).
- `POST /api/admin/companies/{company}/subscription` — `{ plan, ends_at, amount }` (`ends_at` y `amount` opcionales solo para `GRATUITO`) → cambia/renueva el plan, 201 con la nueva suscripción.

### Registro público (prueba gratuita)

`POST /api/register` asigna `GRATUITO` por 1 mes a la empresa nueva — una prueba
por RUC. Detalle en `docs/auth.md` §2.1.

### Login

`POST /api/login` agrega a `permissions` (solo usuarios cliente):

```json
"plan": "FINANCIERO",          // null si no tiene plan vigente
"plan_ends_at": "2026-11-08",  // null si no tiene plan vigente
"financiero": true,            // plan ∈ FINANCIERO_LEVEL
"financiero_trial_available": false
```

Es una foto al momento del login; para refrescarla sin re-login el front usa `GET /api/subscription/current`. El backend igual valida en cada operación.

### Prueba del plan Financiero (cliente)

- `GET /api/subscription/current` → `{plan, plan_ends_at, financiero, financiero_trial_available, needs_finance_setup}`. `needs_finance_setup`: con financiero, la sucursal aún no tiene caja GENERAL.
- `POST /api/subscription/financiero-trial` → 201 `{succes, permissions:{plan, plan_ends_at, financiero, financiero_trial_available}, trial_ends_at, needs_finance_setup}`; 422 `{succes:false, message}` si ya la usó, si ya tiene FINANCIERO/GRATUITO o si no tiene plan vigente.
- Solo sobre un FACTURADOR vigente, una vez por empresa (`subscriptions.is_trial`). Crea FINANCIERO de hoy a hoy+30 **sin cerrar** el FACTURADOR: el plan vigente es el de `started_at` más reciente, así que al vencer la prueba la empresa vuelve sola al FACTURADOR con su misma fecha de fin (o queda sin plan si este venció mientras tanto). Los datos financieros (cajas, cartera) se conservan; con FACTURADOR quedan de solo lectura/abonos según §4.2.
- Si el admin asigna un plan durante la prueba, `assignPlan` cierra ambas (comportamiento normal de cambio de plan).

## 4. Gate

`App\Services\SubscriptionGate` — punto único de validación, dos niveles:

### 4.1 Plan vigente (emisión)

`ensureActiveSubscription($company)`, aplicado con el middleware `subscription.active` (`EnsureActiveSubscription`) en `routes/api.php` sobre:

- Crear: `orders.store`, `orders.lot.store`, `orders.convert-to-invoice`, `shops.store`, `referralguides.store`.
- Procesar ante el SRI: `orders.process`, `shops.process`, `retentions.process`, `referralguides.process`.

Lecturas, PDF, XML, edición y anulación **no** están bloqueadas. Los `ProcessVoucherJob` ya encolados antes del vencimiento terminan normalmente.

### 4.2 Plan financiero (cartera)

`ensureFinancieroAccess($company)` — se llama **solo en lo que crea deuda nueva**, desde los servicios:

- `OrderStoreService` / `ShopStoreService` — cuando `payment_type` es `CREDITO` o `ANTICIPO` (incluye convertir proforma → factura).
- CxC / CxP: anticipos (`*AdvanceService::register`), saldo inicial (`*LedgerService::registerOpeningBalance`).

**Abonos sobre cartera existente** (`*PaymentService::pay` — `orders/{order}/payments`, `shops/{shop}/payments`) **no están bloqueados** con ningún plan, ni vencido: la empresa siempre puede cobrar/pagar ventas y compras a crédito que ya tiene (decisión de negocio, 2026-10-08).

### 4.2.1 Contado sin caja/banco (FACTURADOR)

`SubscriptionGate::hasFinancieroAccess()` — con plan sin cartera (FACTURADOR),
la venta (`OrderStoreRequest`) y la compra (`ShopStoreRequest`) al contado
**no exigen** `source_type`/`source_id`, y `OrderStoreService`/`ShopStoreService`
**no generan** movimiento de tesorería aunque vengan. Lo decide el plan, no el
front (`without_source` se ignora). GRATUITO/FINANCIERO siguen exigiendo
caja/banco en contado (decisión de negocio, 2026-10-08).

### 4.3 Respuesta

Ambos lanzan `App\Exceptions\PlanRequiredException`, que se renderiza sola como 403:

```json
// Sin plan vigente (vencido o nunca asignado)
{ "succes": false, "message": "Tu plan está vencido. Renová tu suscripción…", "error": "subscription_expired", "required_plan": null }

// Plan vigente sin cartera
{ "succes": false, "message": "Esta función requiere el plan financiero…", "error": "plan_required", "required_plan": "FINANCIERO" }
```

No extiende `RuntimeException` a propósito: los controllers atrapan `RuntimeException` y la convierten en 422 genérico; el front necesita distinguir `error` para mostrar el aviso correcto (renovar vs. actualizar plan).

## 5. Salud de tu negocio (`GET /api/dashboard/health`)

Solo planes con financiero (`ensureFinancieroAccess`: FACTURADOR → 403
`plan_required`, vencido → 403 `subscription_expired`).
`App\Services\Dashboard\BusinessHealthService`:

- **cashflow** (30 días): `cash` = saldo de `bank_accounts` + sesiones de caja
  `ABIERTA`; `receivable`/`payable` = pendiente con vencimiento ≤ hoy+30
  (incluye vencido y saldos iniciales); `projected = cash + receivable - payable`;
  `weeks` = 4 tramos (0-6, 7-13, 14-20, 21-30 días), el 1º incluye vencido y
  saldos iniciales.
- **Pendiente por documento**: ventas = `total - paid - NC aplicadas`
  (ledger `NOTA_CREDITO`, que no actualiza `paid`), sin `ANULADO`; compras =
  `total - paid - retenciones`. Solo `CREDITO`/`ANTICIPO`. Saldos iniciales
  (`SALDO_INICIAL`) no tienen vencimiento.
- **aging** (CxC): `buckets`/`counts` `current`, `d1_30`, `d31_60`, `d61_90`,
  `d90_plus` y `no_due` (saldos iniciales); `to_collect` = top 5 clientes por
  monto vencido + saldo inicial (`days_overdue` null si solo tiene saldo inicial).
- **sri_reserve** (estimado): general = mensual; RIMPE emprendedor = semestral
  (ene-jun / jul-dic); Negocio Popular = todo null.
  `estimated = max(0, iva_sales - iva_purchases - iva_withheld) + iva_retained`;
  `rent_retained` aparte (formulario 103). `iva_sales` = facturas − N/C
  `AUTORIZADO`; `iva_purchases` = compras factura/liquidación/nota de débito;
  `iva_withheld` = `orders.iva_retention` (hoy sin uso → 0); `iva_retained` /
  `rent_retained` = `shop_retention_items` código 2 / 1 por `date_retention`.
  `due_date`: mes siguiente al período, día según 9no dígito del RUC (1→10 …
  9→26, 0→28), corrido al siguiente hábil por fin de semana o `holidays`.

## 5.1 Movimientos (`GET /api/movements/summary`)

Vista unificada de caja/banco. Resumen solo con financiero (mismo 403 que §5);
el listado `GET /api/movements` no está bloqueado.

- `movements.category` (`App\StaticClasses\MovementCategories`): automática al
  crear (`COBRO_VENTA`/`ANTICIPO_CLIENTE` → `VENTAS`, `PAGO_COMPRA`/
  `ANTICIPO_PROVEEDOR` → `COMPRAS`); internos y saldo inicial → null. `OTRO`
  la recibe en `POST movements/other` o después con
  `PATCH movements/{id}/category` (solo `OTRO`; la categoría debe corresponder
  al `type`, `MovementCategories::BY_TYPE`).
- `origin` (`origin_type` `order`|`shop` + `origin_id`): venta/compra de contado
  y abonos. Abonos anteriores a la migración quedan sin origen.
- Listado: filtros `date_from`, `date_to`, `direction` (`in`/`out`, excluye
  `MovementTransactionTypes::NON_OPERATING`), `transaction_type` (coma),
  `category` (`SIN_CLASIFICAR` = null); orden `date desc, id desc`.
- Catálogo fijo (`GET movements/categories` → `{category, type, group}`):
  grupo `NEGOCIO` (ventas, compras, sueldos, IESS, arriendo, servicios
  básicos, transporte, publicidad, comisiones bancarias, impuestos, otros) y
  `FINANCIAMIENTO` (aporte/retiro del dueño, préstamo recibido/pago). Sin
  categoría cuenta como negocio.
- Resumen (sin fechas = mes en curso), solo `COMPLETADO` sin internos ni saldo
  inicial: `income`/`expense`/`net` = **solo negocio** (lo que dejó el
  negocio); `financing` = aportes, retiros, préstamos y su `net` (no es
  ganancia ni pérdida); `cash_change = net + financing.net`; `internal` =
  depósitos, retiros, transferencias y apertura/cierre de caja; `pending`
  aparte; `by_category` con `group`; `accounts` = saldo actual (caja = sesión
  abierta, 0 si cerrada).

## 5.2 Comprobante de cobros/pagos por banco

Flujo en dos pasos, para no volver multipart los stores grandes:

1. `POST /api/payment-receipts` (multipart, campo `receipt`: jpg/png/webp, máx 5 MB) → `201 {id}`. Se guarda en el disco privado (`treasury/receipts`, mismo lugar que los comprobantes de depósito/retiro/transferencia/otro) como `PaymentReceipt` sin vincular.
2. `receipt_id` opcional en `orders/{id}/payments`, `shops/{id}/payments`, `customers|providers/{id}/advances` y en el store de venta/compra de contado. Solo con `source_type = bank_account` (si no, 422 `receipt_id`); debe ser de la empresa y no estar vinculado. `MovementService::create` lo vincula (`payment_receipts.movement_id`) y copia el archivo a `movements.receipt_path`.

`GET /api/movements/{movement}/receipt` devuelve el archivo con su Content-Type real (también los PDF de movimientos manuales); 404 si no tiene. `MovementResources` expone `atts.has_receipt`. Los comprobantes no vinculados se purgan a las 24 h (`model:prune` cada hora, `routes/console.php`; en prod lo corre el servicio `scheduler`).

`PATCH /api/movements/{movement}/receipt` (JSON) corrige después el comprobante (ej. se guardó sin él): `receipt_number` y/o `receipt_id` (del mismo flujo de 2 pasos). Clave ausente = sin cambios; `null`/`""` = se borra. Un `receipt_id` nuevo reemplaza `receipt_path`; el archivo anterior y su `PaymentReceipt` se borran (el archivo recién confirmada la transacción). Solo movimientos con `bank_account` como origen o destino; si no, 422 `Solo los movimientos con cuenta bancaria llevan comprobante.`. Responde `MovementResources` (`MovementService::updateReceipt`).

## 6. Puntos de mejora

- El plan en el front se refresca solo al re-loguear — si se necesita en vivo, agregar un endpoint `GET` del plan propio o incluirlo en algún endpoint que el front ya consulte periódicamente.
- No hay aviso/notificación de plan por vencer (job/email) — importante ahora que todos los planes vencen y bloquean emisión.
