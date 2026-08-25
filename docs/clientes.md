# Clientes (Backend)

Documentación del módulo **Clientes** (`App\Models\Customer`): destinatarios
de los comprobantes de **Ventas** (`Order`) y **Guías de Remisión**
(`ReferralGuide`). Cubre modelo, endpoints, validación, integración con el
catastro del SRI y relación con ventas.

## 1. Overview

Un `Customer` es el cliente/receptor de una factura. Está siempre asociado a
una sucursal (`branch_id`) y aislado por sucursal mediante un *global scope*
(ver §4). El modelo usa `SoftDeletes` (`app/Models/Customer.php:12`).

### Campos (tabla `customers`)

Fuente: migración `database/migrations/2021_08_30_173305_create_customers_table.php`
+ `2026_08_05_155704_add_deleted_at_to_customers_providers_carriers_tables.php`.

| Campo | Tipo | Notas |
|---|---|---|
| `id` | bigIncrements | PK |
| `branch_id` | unsignedBigInteger | FK → `branches`; base del aislamiento multi-sucursal |
| `state` | integer (default 1) | 1 = activo, 2 = inactivo |
| `type_identification` | string(10) | Tipo de identificación (ver abajo) |
| `identication` | string(13) | Número de identificación (nombre de columna con typo, ver §5) |
| `name` | string(300) | Razón social / nombre comercial |
| `address` | string, nullable | Dirección |
| `phone` | string, nullable | Teléfono |
| `email` | string, nullable | Correo |
| `accounting` | boolean (default false) | Marca contable |
| `discount` | integer, nullable | Descuento por defecto para facturas |
| `deleted_at` | timestamp | Soft delete |

`$fillable` en `app/Models/Customer.php:14-27` incluye además
`rent_retention` e `iva_retention`, que **no existen como columnas** en la
tabla `customers` (ver §5).

### Tipos de identificación soportados

Los valores aceptados dependen de la fuente:

| Valor | Origen | Longitud esperada |
|---|---|---|
| `cédula` | Validación store/update (`CustomerStoreRequest.php:27`) | 10 dígitos |
| `ruc` | Validación store/update | 13 dígitos |
| `pasaporte` | Validación store/update | libre |
| `cf` | Filtro de exportación (`CustomerExport.php:21`) — "consumidor final" | — |

El comentario de la migración menciona "cedula/ruc/pasaporte/Consumidor
final" (`create_customers_table.php:21`). Existe una **inconsistencia** entre
los valores que acepta la validación, el filtro del export y el comentario de
la migración (ver §5).

## 2. Endpoints principales

Rutas en `routes/api.php:47,66-72` (grupo autenticado con Sanctum).

| Método | Ruta | Acción | Controlador |
|---|---|---|---|
| GET | `/customers` | Listado paginado + búsqueda | `CustomerController@index` |
| GET | `/customers/create` | Stub, devuelve `{succes:true}` | `CustomerController@create` |
| GET | `/customers/resolve/{identification}` | Resolver datos por identificación (local + SRI) | `CustomerController@resolve` |
| POST | `/customers` | Crear cliente | `CustomerController@store` |
| GET | `/customers/{customer}` | Datos para editar | `CustomerController@edit` |
| PUT | `/customers/{customer}` | Actualizar | `CustomerController@update` |
| DELETE | `/customers/{customer}` | Eliminar (soft/force) | `CustomerController@destroy` |
| GET | `/customers/lookup?q=` | Autocompletar (name/identication) | `CustomerLookupController@index` |

### `index` — listado y búsqueda

`CustomerController@index` (`app/Http/Controllers/Customer/CustomerController.php:18-38`):
busca por `identication` o `name` con `LIKE %term%`, **escapando** `%` y `_`
(`CustomerController.php:25`), pagina (`paginate`, default 15) y ordena por
`created_at` desc. Devuelve `CustomerResources` (solo `id` + `atts.{identication,name,address}`,
ver `app/Http/Resources/CustomerResources.php:15-25`).

### `resolve` — autocompletar por identificación (local + SRI)

`CustomerController@resolve` (`CustomerController.php:45-69`) es el "lookup"
principal para el formulario de factura:

1. Busca el cliente en la sucursal actual (`Customer::where('identication', …)->first()`).
2. Si no existe, busca la última coincidencia **en cualquier sucursal**
   (`withoutGlobalScope('branch')`, `CustomerController.php:50-53`).
3. Si la identificación tiene **13 caracteres** (RUC), consulta el catastro
   del SRI vía `SriResolveNameService::searchByIdentificationSRI`
   (`CustomerController.php:55-57`).
4. Fusiona atributos del modelo + datos del SRI + `branch_id => 0`
   (`CustomerController.php:61-65`).

`SriResolveNameService` (`app/Services/SriResolveNameService.php:10-35`) hace
un `Http::get` a `config('services.sri.url')` → env `SRI_CATASTRO_URL`
(`config/services.php:38-40`) con `timeout(5)` y `retry(2, 200)`, enviando
`tipoIdentificacion` = `R` (RUC, 13) o `C` (cédula). Aborta con 404 si el
SRI no devuelve `nombreCompleto`. Retorna solo `['name' => …]`.

### `lookup` — autocompletar simple

`CustomerLookupController@index` (`app/Http/Controllers/Customer/CustomerLookupController.php:12-29`):
búsqueda por `name`/`identication` con `LIKE %q%`, límite 20, devuelve
`id, name, email, identication`. **No escapa** los comodines `%`/`_` (ver §5).

## 3. Validaciones y reglas de negocio

`CustomerStoreRequest` (`app/Http/Requests/Customer/CustomerStoreRequest.php:24-38`)
y `CustomerUpdateRequest` (`.../CustomerUpdateRequest.php:29-40`) comparten
reglas casi idénticas:

| Campo | Regla |
|---|---|
| `type_identification` | `required\|string\|in:cédula,ruc,pasaporte` |
| `identication` | `required\|string` + `UniqueBranchScoped` |
| `name` | `required\|min:3\|max:250` |
| `address` | `required\|min:3\|max:250` |
| `phone` | `nullable` |
| `email` | `nullable\|email` |

### Unicidad por sucursal

`UniqueBranchScoped` (`app/Rules/UniqueBranchScoped.php`) valida que
`identication` no esté repetida **dentro de la sucursal** del usuario
autenticado. En update se pasa el `$customerId` de la ruta para ignorarlo
(`CustomerUpdateRequest.php:27,34`). Esto se refuerza a nivel de BD con el
índice único compuesto `unique(['branch_id','identication'], 'customer_unique')`
(`create_customers_table.php:33`).

### Ausencia de validación de identificación ecuatoriana

**No hay** validación de longitud por tipo (10 para cédula, 13 para RUC) ni
de **dígito verificador** (algoritmo módulo 10/11 del SRI). `identication`
solo se valida como `required|string`. Un `ruc` de 5 caracteres o una cédula
inválida pasan la validación (ver §5).

### `store` / `update`

- `store` (`CustomerController.php:71-78`) asocia el cliente a la **primera
  sucursal** de la company (`orderBy('created_at')->first()`) y crea con
  `$request->validated()`. Campos como `state`, `accounting`, `discount` no
  se envían y toman el default de BD.
- `update` (`CustomerController.php:85-90`) hace `update($request->validated())`.

### `destroy` — borrado inteligente

`CustomerController@destroy` (`CustomerController.php:92-110`): si el cliente
tiene `orders` o `referralGuides`, hace **soft delete**; si no está en uso,
**force delete** definitivo. Envuelto en try/catch que devuelve 422 ante
error (ej. violación de FK).

## 4. Relación con Ventas

- `Customer hasMany Order` (`app/Models/Customer.php:29-32`) y
  `hasMany ReferralGuide` (`.../Customer.php:34-37`).
- Una venta referencia al cliente por `customer_id` en `orders`
  (`app/Models/Order/Order.php:26`).
- **Aislamiento multi-sucursal**: `Customer extends BaseModel`
  (`app/Models/Customer.php:10`), que aplica el trait `BranchScope`
  (`app/Models/BaseModel.php`). El *global scope* `branch`
  (`app/BranchScope.php`) filtra todas las consultas por el `branch_id` de la
  primera sucursal de la company autenticada. Por eso `edit`/`update`/`destroy`
  con *route-model binding* devuelven 404 si el cliente es de otra sucursal —
  la protección multi-tenant recae íntegramente en este scope, no en Policies.

## 5. Puntos de mejora

Hallazgos concretos con evidencia:

1. **`rent_retention` / `iva_retention` en `$fillable` sin columna**
   (`app/Models/Customer.php:25-26`). La tabla `customers` no define esas
   columnas (solo existen en `orders`/`shops`). Cualquier intento de
   asignarlas fallaría o se ignora silenciosamente — probablemente copiado de
   otro modelo por error.

2. **Sin validación de identificación ecuatoriana**
   (`CustomerStoreRequest.php:28-32`). Falta longitud por tipo y dígito
   verificador del SRI. Riesgo de clientes con RUC/cédula inválidos que luego
   rompen la emisión del comprobante.

3. **Valores de `type_identification` inconsistentes**. La validación acepta
   `cédula,ruc,pasaporte` (`CustomerStoreRequest.php:27`), pero el export
   filtra `type_identification <> 'cf'` (`app/Exports/CustomerExport.php:21`) y
   la migración comenta "Consumidor final" (`create_customers_table.php:21`).
   No hay una fuente única (enum) de los valores válidos.

4. **`type_identification` guarda tildes** (`cédula`) en una columna
   `string(10)` (`create_customers_table.php:21`). `cédula` en UTF-8 puede
   acercarse al límite; además complica comparaciones. Conviene un enum de
   valores ASCII (`cedula`).

5. **Sin casts en el modelo** (`app/Models/Customer.php`). `accounting`
   (boolean) y `discount` (int) se devuelven sin `$casts`, dependiendo del
   driver. Definir `protected $casts` haría el tipado explícito.

6. **`resolve` con forma de respuesta inconsistente**
   (`CustomerController.php:45-69`): devuelve un modelo `Customer` (con todos
   sus campos) cuando existe localmente, o un **array fusionado** con
   `branch_id => 0` cuando no. El consumidor debe manejar dos formas
   distintas del mismo endpoint.

7. **`resolve` no consulta el SRI para cédulas** (`CustomerController.php:55`):
   solo dispara la búsqueda si `strlen === 13` (RUC), aunque
   `SriResolveNameService` sí soporta `tipoIdentificacion = 'C'`
   (`SriResolveNameService.php:20-21`). Las cédulas nunca autocompletan el
   nombre desde el catastro.

8. **`CustomerLookupController` no escapa comodines LIKE**
   (`CustomerLookupController.php:23-24`): usa `"%{$term}%"` sin escapar
   `%`/`_`, a diferencia de `index` que sí lo hace (`CustomerController.php:25`).
   No es inyección SQL (usa *bindings*), pero permite comodines inesperados en
   la búsqueda.

9. **`CustomerExport` es código muerto**: no está referenciado por ninguna
   ruta ni controlador (búsqueda en `app/` y `routes/` sin resultados). O se
   cablea a un endpoint de exportación o se elimina.

10. **Typos en nombres/respuestas**: la columna se llama `identication`
    (`create_customers_table.php:22`) en vez de `identification`, y varias
    respuestas usan `'succes'` en lugar de `'success'`
    (`CustomerController.php:42,101,107`). Renombrarlos requiere migración +
    cambios coordinados con el frontend.

11. **Requests sin autorización real**: `authorize()` retorna `true`
    (`CustomerStoreRequest.php:16`, `CustomerUpdateRequest.php:16`). El
    aislamiento depende únicamente del *global scope* `branch`; no hay
    Policies. Es funcional para multi-tenant simple, pero no cubre roles ni
    permisos por acción.
