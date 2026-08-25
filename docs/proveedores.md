# Proveedores (Backend)

Documentación del módulo **Proveedores** (modelo `Provider`). Un proveedor
representa a la persona/empresa a la que la sucursal le compra. Es el maestro
que alimenta el módulo de **Compras** (`Shop`) y, de forma indirecta y
manual, los **Reembolsos** (`Repayment`) que se declaran dentro de una factura
de venta.

Los proveedores están **scopeados por sucursal** (`branch_id`) vía el global
scope `branch` (`app/BranchScope.php:13`), igual que clientes y transportistas.

## 1. Overview

### Campos (`app/Models/Provider.php:13`, migración `database/migrations/2021_09_13_122317_create_providers_table.php`)

| Campo | Tipo BD | Notas |
|---|---|---|
| `id` | bigint | PK |
| `branch_id` | bigint FK → `branches` | Sucursal dueña; parte del índice único |
| `state` | int (default 1) | `1` activo / `2` inactivo (comentario migración:19) |
| `type_identification` | string(10) | `cédula` / `ruc` / `pasaporte` |
| `identication` | string(13) | Número de identificación. **Nota: la columna está mal escrita** (falta la `f`), ver §5 |
| `name` | string(300) | Razón social / nombre |
| `address` | string, nullable | Dirección |
| `phone` | string, nullable | Teléfono |
| `email` | string, nullable | Correo |
| `accounting` | boolean (default false) | Bandera de contabilidad |
| `discount` | int, nullable | Descuento (comentario migración:27 «Invoice») |
| `deleted_at` | timestamp, nullable | Soft delete, añadido en `2026_08_05_155704_add_deleted_at_to_customers_providers_carriers_tables.php` |

- Índice único: `['branch_id', 'identication']` con nombre `provider_unique`
  (migración:32) — un mismo número no puede repetirse dentro de la sucursal,
  pero sí entre sucursales distintas.
- `use SoftDeletes` (`Provider.php:11`).
- Relación: `Provider::shops()` → `hasMany(Shop::class)` (`Provider.php:26`).

### Tipos de identificación soportados

`cédula`, `ruc`, `pasaporte` (validado con `in:` en los Requests). El SRI
distingue el tipo por longitud: RUC = 13 dígitos, cédula = 10. El lookup contra
el SRI (`resolve`) **solo** se dispara cuando la identificación tiene 13
caracteres (RUC), ver §2.

## 2. Endpoints principales

Todas bajo el grupo autenticado de `routes/api.php` (Sanctum). El binding
`{provider}` respeta el global scope de sucursal.

| Método | Ruta | Acción | Controller |
|---|---|---|---|
| GET | `providers` | Listado paginado con búsqueda | `ProviderController@index` (`:18`) |
| GET | `providers/create` | Devuelve `{succes:true}` (placeholder) | `ProviderController@create` (`:40`) |
| GET | `providers/resolve/{identification}` | Lookup por identificación (BD + SRI) | `ProviderController@resolve` (`:45`) |
| POST | `providers` | Crear proveedor | `ProviderController@store` (`:71`) |
| GET | `providers/{provider}` | Datos para edición | `ProviderController@edit` (`:80`) |
| PUT | `providers/{provider}` | Actualizar | `ProviderController@update` (`:85`) |
| DELETE | `providers/{provider}` | Eliminar (soft o force, ver §3) | `ProviderController@destroy` (`:92`) |
| GET | `providers/lookup?q=` | Autocompletado ligero | `ProviderLookupController@index` (`:12`) |

### `index` — listado (`ProviderController.php:18`)

- Parámetros: `search` (busca en `identication` y `name` con `LIKE`),
  `paginate` (default 15).
- Escapa `%` y `_` del término antes del `LIKE` (`:25`) para evitar comodines
  inyectados.
- Selecciona `id, type_identification, identication, name, address, phone,
  email` y ordena por `created_at` desc.
- Devuelve `ProviderResources::collection` — **que solo expone
  `id`, `identication`, `name`, `address`** (`ProviderResources.php:17`),
  descartando `type_identification`, `phone` y `email` que sí se
  seleccionaron. Ver §5.

### `resolve` — lookup con enriquecimiento SRI (`ProviderController.php:45`)

Flujo:

1. Busca un `Provider` por `identication` **en la sucursal actual**. Si existe,
   devuelve el modelo completo tal cual.
2. Si no existe en la sucursal, busca el mismo número en **cualquier** sucursal
   (`withoutGlobalScope('branch')`, `:50`) para reutilizar datos ya cargados.
3. Si la identificación tiene 13 caracteres (RUC), consulta el SRI vía
   `SriResolveNameService::searchByIdentificationSRI` (`:55`) para traer el
   `name` oficial.
4. Devuelve un **array mezclado** de: atributos del modelo de otra sucursal +
   datos del SRI + `['branch_id' => 0]` (`:61`).

El servicio SRI (`app/Services/SriResolveNameService.php:10`) llama a
`config('services.sri.url')` con timeout de 5s y 2 reintentos; hace `abort(404)`
si el RUC no existe y `abort($status)` si el servicio externo falla.

> La respuesta de `resolve` tiene **forma inconsistente**: rama 1 devuelve un
> objeto `Provider` (con `accounting`, `discount`, `state`, `branch_id` real),
> rama 2/3 devuelve un array plano con `branch_id: 0` y solo los campos que
> existieran. El front debe tolerar ambas formas. Ver §5.

### `lookup` — autocompletado (`ProviderLookupController.php:12`)

- Parámetro `q`; si viene vacío devuelve `[]`.
- Busca en `name` o `identication` con `LIKE`, limita a 20 resultados y
  devuelve solo `id, name, identication`.
- **No escapa** los comodines `%`/`_` del término (a diferencia de `index`),
  ver §5.

## 3. Validaciones y reglas de negocio

`ProviderStoreRequest` (`app/Http/Requests/Provider/ProviderStoreRequest.php:26`)
y `ProviderUpdateRequest` (`:29`) comparten reglas:

| Campo | Regla |
|---|---|
| `type_identification` | `required\|string\|in:cédula,ruc,pasaporte` |
| `identication` | `required\|string` + `UniqueBranchScoped` |
| `name` | `required\|min:3\|max:250` |
| `address` | `required\|min:3\|max:250` |
| `phone` | `nullable` |
| `email` | `nullable\|email` |

- **Unicidad por sucursal**: la regla `UniqueBranchScoped`
  (`app/Rules/UniqueBranchScoped.php`) verifica que `identication` no exista ya
  en la tabla `providers` para la sucursal del usuario. En update recibe el
  `$providerId` (obtenido de `$this->route('provider')->id`,
  `ProviderUpdateRequest.php:27`) para ignorarse a sí mismo.
- La regla resuelve la sucursal como **la primera branch de la company por
  `created_at`** (`UniqueBranchScoped.php:48`). Si no hay company o branch,
  **deja pasar** la validación (`:45`, `:53`) — devuelve `true`.
- `store` crea el proveedor sobre esa **misma primera branch**
  (`ProviderController.php:73`): `Auth::user()->company->branches()
  ->orderBy('created_at')->first()`.

### Reglas de eliminación (`ProviderController.php:92`)

```php
$isUsed = $provider->shops()->exists();
$isUsed ? $provider->delete() : $provider->forceDelete();
```

- Si el proveedor **tiene compras asociadas** → soft delete (conserva
  histórico e integridad referencial).
- Si **no tiene compras** → force delete (borrado físico).
- Errores se capturan y devuelven `422` con `succes:false` y el mensaje.

## 4. Relación con Compras y Reembolsos

### Compras (`Shop`)

Relación directa por FK: `Shop.provider_id` (`app/Models/Shop/Shop.php:24`,
fillable) ↔ `Provider::shops()` (`Provider.php:26`). Es la relación que impide
el borrado físico de un proveedor ya usado (§3). No existe un método
`Shop::provider()` inverso declarado; solo el `provider_id` en el fillable.

### Reembolsos (`Repayment`) — relación **denormalizada, sin FK**

Los reembolsos declarados en una factura de venta llevan datos del proveedor,
pero **no se enlazan al modelo `Provider`**. El modelo `Repayment`
(`app/Models/Order/Repayment/Repayment.php:9`) guarda esos datos como campos
propios copiados a mano:

| Campo `Repayment` | Uso en el XML SRI |
|---|---|
| `type_id_prov` | `<tipoIdentificacionProveedorReembolso>` |
| `identification` | `<identificacionProveedorReembolso>` |
| `cod_country` | `<codPaisPagoProveedorReembolso>` |
| `type_prov` | `<tipoProveedorReembolso>` |
| `type_document` | `<codDocReembolso>` |
| `sequential` | estab/ptoEmi/secuencial del doc reembolsado |
| `date` | `<fechaEmisionDocReembolso>` |
| `authorization` | `<numeroautorizacionDocReemb>` |

El bloque `<reembolsoDetalle>` se arma en
`app/Xml/InvoiceBuilder.php:148` (`buildRepayments`), leyendo directamente de
la tabla `repayments` (`loadRepayments`, `:137`) con un `join` a
`repayment_taxes`. **No hay ningún `provider_id` en `repayments`**: el emisor
teclea la identificación del proveedor del reembolso por su cuenta, sin
reutilizar el maestro `Provider`. Ver §5.

## 5. Puntos de mejora

Hallazgos concretos con evidencia:

1. **Typo estructural `identication`** (falta la `f`) — está en la columna de
   BD (`migración:21`), en `$fillable` (`Provider.php:18`), en las reglas
   (`ProviderStoreRequest.php:28`), en los `where`/`select` de los controllers
   (`ProviderController.php:28,32,47` y `ProviderLookupController.php:23,26`) y
   en el índice único. Corregirlo obliga a migración + refactor coordinado,
   pero es deuda que se propaga a cada consumidor.

2. **`accounting` y `discount` nunca se guardan vía API.** Ambos están en
   `$fillable` (`Provider.php:22-23`) pero **no** aparecen en las reglas de
   `ProviderStoreRequest`/`ProviderUpdateRequest`. Como `store`/`update` usan
   `$request->validated()` (`ProviderController.php:75,87`), esos campos se
   descartan siempre: solo tomarían su default de BD (`accounting=false`,
   `discount=null`) y no pueden actualizarse.

3. **Respuesta de `resolve` con forma inconsistente**
   (`ProviderController.php:47-68`): devuelve un modelo `Provider` cuando existe
   en la sucursal, o un array plano con `branch_id:0` en otro caso. El front
   debe manejar dos contratos distintos para el mismo endpoint.

4. **`ProviderResources` descarta campos ya seleccionados.** `index` selecciona
   `type_identification`, `phone`, `email` (`ProviderController.php:32`) pero el
   Resource solo expone `identication`, `name`, `address`
   (`ProviderResources.php:17`). O sobra el select, o falta exponerlos.

5. **`lookup` no escapa comodines `LIKE`.** `ProviderLookupController.php:23`
   interpola `%{$term}%` sin escapar `%`/`_`, mientras que `index` sí lo hace
   (`ProviderController.php:25`). Inconsistencia; un `_` o `%` en `q` altera la
   búsqueda (no es SQLi por el binding, pero sí resultados inesperados).

6. **Selección de sucursal siempre «la primera».** Tanto `store`
   (`ProviderController.php:73`) como `UniqueBranchScoped`
   (`UniqueBranchScoped.php:48`) asumen la primera branch por `created_at`.
   En una company multi-sucursal el proveedor siempre cae en la sucursal más
   antigua y la unicidad se valida contra ella, ignorando la sucursal real del
   usuario.

7. **`UniqueBranchScoped` falla abierto.** Si no hay company o branch, la regla
   devuelve `true` (`UniqueBranchScoped.php:45,53`), permitiendo duplicados en
   ese borde en lugar de rechazar.

8. **Reembolsos desconectados del maestro `Provider`** (§4). `repayments` no
   tiene `provider_id`; los datos del proveedor se reingresan manualmente. Un
   FK opcional a `Provider` permitiría autocompletar `type_id_prov` e
   `identification` y evitar tipeos que rompen el XML del SRI.

9. **Respuestas con typo `succes`** (falta la `s`) en `create`
   (`ProviderController.php:42`) y `destroy` (`:100,106`). Contrato de API con
   clave mal escrita; el front depende de esa llave.

10. **`UniqueBranchScoped` implementa la interfaz `Rule` deprecada**
    (`UniqueBranchScoped.php:6`, `Illuminate\Contracts\Validation\Rule`) en vez
    de `ValidationRule` (Laravel 13). Funciona, pero es API legada.

### Duda / ambigüedad abierta

- No existe validación de longitud coherente entre `type_identification` y
  `identication` (p.ej. `ruc` ⇒ 13, `cédula` ⇒ 10). No pude confirmar si esa
  validación vive en el front (`facec-front-next`) o simplemente no se hace.
- `create` y `edit` parecen placeholders orientados a un front SPA; no me quedó
  claro si `edit` (`:80`, devuelve `{provider: ...}`) sigue en uso dado que
  `resolve` cubre buena parte del prellenado.
