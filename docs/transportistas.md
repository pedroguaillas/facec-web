# Transportistas (`Carrier`)

Módulo de mantenimiento de **transportistas**: los sujetos (persona o
empresa) que trasladan la mercadería en una **Guía de Remisión**
(`ReferralGuide`, comprobante SRI tipo `06`). Un transportista aporta al XML
de la guía su razón social, identificación (RUC/cédula) y **placa** del
vehículo.

A diferencia de Ventas/Compras, `Carrier` **no es un comprobante
electrónico**: no tiene estados SRI, ni XML, ni firma propios. Es un catálogo
que alimenta el nodo `<infoGuiaRemision>` de la guía de remisión (ver §4).

## 1. Overview

Modelo: `app/Models/Carrier.php`. Tabla: `carriers`
(`database/migrations/2021_11_25_190255_create_carriers_table.php`).
Extiende `BaseModel`, por lo que hereda el **global scope `branch`**
(`app/BranchScope.php:13`): toda consulta se filtra automáticamente por la
primera sucursal de la empresa del usuario autenticado. Usa `SoftDeletes`
(`Carrier.php:10`, columna `deleted_at` añadida en
`2026_08_05_155704_add_deleted_at_to_customers_providers_carriers_tables.php`).

| Campo | Tipo (migración) | Descripción |
|---|---|---|
| `id` | bigint PK | — |
| `branch_id` | bigint FK → `branches` | Sucursal propietaria (asignada en `store`, no por request) |
| `type_identification` | string(10) | Tipo de identificación (cédula/RUC/pasaporte/exterior). Ver §5 — **no se usa en el XML** |
| `identication` | string(13) | Identificación (RUC/cédula). Nótese el typo del nombre de columna (`identication`) |
| `name` | string(300) | Razón social / nombre comercial del transportista |
| `email` | string, nullable | Correo (opcional) |
| `license_plate` | string(20) | Placa del vehículo |

Restricción de unicidad compuesta: `unique(['branch_id', 'identication'])`
con nombre `carrier_unique` (migración creadora). No hay `$casts` definidos.

Relación: `Carrier hasMany ReferralGuide` (`Carrier.php:17`), inversa de
`ReferralGuide.carrier_id` (`app/Models/ReferralGuide/ReferralGuide.php:10`).
`Branch hasMany Carrier` (`app/Models/Branch.php:43`).

## 2. Endpoints

Todas bajo el grupo autenticado de `routes/api.php:97-103` (Sanctum):

| Método | Ruta | Acción | Descripción |
|---|---|---|---|
| GET | `carriers` | `index` | Listado paginado con búsqueda por `identication`/`name` |
| GET | `carriers/create` | `create` | Devuelve `{succes: true}` — placeholder para el formulario |
| GET | `carriers/resolve/{identification}` | `resolve` | Autocompletado: busca local, si no existe consulta SRI |
| POST | `carriers` | `store` | Crea el transportista en la sucursal del usuario |
| GET | `carriers/{carrier}` | `edit` | Devuelve el modelo crudo para editar |
| PUT | `carriers/{carrier}` | `update` | Actualiza |
| DELETE | `carriers/{carrier}` | `destroy` | Borra (soft o force según uso — ver §3) |

**`index`** (`CarrierController.php:18`): parámetros `search` (LIKE sobre
`identication` y `name`, con escape de `%`/`_` en la línea 24) y `paginate`
(default 15). Ordena por `latest()` y usa el `CarrierResources`.

**`resolve`** (`CarrierController.php:43`): si existe un transportista con esa
identificación en la sucursal, lo devuelve. Si no, busca fuera del scope de
sucursal (`withoutGlobalScope('branch')`, línea 48) y, si la identificación
tiene 13 dígitos, consulta el SRI vía `SriResolveNameService`
(`searchByIdentificationSRI`). Fusiona atributos del modelo + datos SRI +
`branch_id => 0` (líneas 59-63) y devuelve un **array plano** (no un Resource).

**`store`** (`CarrierController.php:69`): resuelve la sucursal como
`Auth::user()->company->branches()->orderBy('created_at')->first()` y crea vía
`$branch->carriers()->create(...)`.

## 3. Validaciones y reglas de negocio

`CarrierStoreRequest` (`app/Http/Requests/Carrier/CarrierStoreRequest.php`) y
`CarrierUpdateRequest` comparten reglas casi idénticas:

| Campo | Reglas | Ref |
|---|---|---|
| `type_identification` | `required, string` | `CarrierStoreRequest.php:19` |
| `identication` | `required, string`, `UniqueBranchScoped('carriers','identication')` | `CarrierStoreRequest.php:20` |
| `name` | `required, string, max:300` | `CarrierStoreRequest.php:21` |
| `email` | `nullable, email, max:300` | `CarrierStoreRequest.php:22` |
| `license_plate` | `required, string, max:20` | `CarrierStoreRequest.php:23` |

- **Autorización**: ambos requests solo exigen `Auth::check()`
  (`CarrierStoreRequest.php:12`), sin política ni verificación de que el
  transportista pertenezca a la sucursal del usuario. En `update`/`destroy`
  esa protección la aporta indirectamente el global scope al resolver el
  route-model-binding.
- **Unicidad por sucursal** (`app/Rules/UniqueBranchScoped.php`): la regla
  determina la sucursal del usuario autenticado (líneas 48-50) y comprueba
  que `(identication, branch_id)` no exista. En update se pasa el `id` a
  ignorar (`CarrierUpdateRequest.php:18,22`). Refuerza el índice único de BD.
- **Borrado** (`CarrierController.php:90`): si el transportista tiene guías
  asociadas (`referralGuides()->exists()`) se hace **soft delete** para
  preservar la integridad histórica; si no tiene ninguna, `forceDelete()`
  (borrado físico). Errores se capturan y devuelven `422` con el mensaje.

## 4. Relación con Guías de Remisión

El transportista es un dato **denormalizado dentro del XML de la guía**. El
flujo:

1. Al crear/editar una guía, `carrier_id` es obligatorio y debe existir:
   `'carrier_id' => ['required', 'integer', 'exists:carriers,id']`
   (`app/Http/Requests/ReferralGuide/ReferralGuideStoreRequest.php:19`).
2. Al armar el XML/listar, la guía se une a `carriers` con alias:
   `->join('carriers AS ca', 'ca.id', 'carrier_id')` y selecciona
   `ca.identication AS ca_identication`, `ca.name AS ca_name`,
   `ca.license_plate`
   (`app/Services/ReferralGuide/ReferralGuideLifecycleService.php:54-55`;
   también `ReferralGuideController.php:96-97`).
3. `ReferralGuideBuilder` los vuelca al nodo `<infoGuiaRemision>`
   (`app/Xml/ReferralGuideBuilder.php`):
   - `<razonSocialTransportista>` ← `ca_name` (línea 41, reemplaza `&`→`Y`).
   - `<tipoIdentificacionTransportista>` ← **derivado por longitud**:
     `04` (RUC) si `strlen(ca_identication) === 13`, si no `05` (cédula)
     (línea 42).
   - `<rucTransportista>` ← `ca_identication` (línea 43).
   - `<placa>` ← `license_plate` (línea 47).

## 5. Puntos de mejora

Hallazgos concretos con evidencia:

1. **`CarrierResources` expone un campo inexistente `address`.** El Resource
   devuelve `'address' => $this->address` (`app/Http/Resources/CarrierResources.php:22`),
   pero la tabla `carriers` no tiene columna `address` (migración creadora)
   ni está en `$fillable` (`Carrier.php:12-15`). Siempre serializa `null`;
   es un campo muerto o un rezago de otra entidad.

2. **`store` no protege contra empresa/sucursal ausente.**
   `Auth::user()->company->branches()->orderBy('created_at')->first()`
   (`CarrierController.php:71`) produce un fatal error si el usuario no tiene
   `company`, y `$branch->carriers()` fallaría si `first()` devuelve `null`.
   Además duplica la lógica de "primera sucursal" que ya viven en
   `BranchScope.php:19-20` y `UniqueBranchScoped.php:48-50` — candidato a
   extraer a un helper único.

3. **`type_identification` se almacena pero nunca se usa en el XML.** El
   `ReferralGuideBuilder` deriva `<tipoIdentificacionTransportista>` de la
   longitud de la identificación (`ReferralGuideBuilder.php:42`), ignorando
   por completo el `type_identification` capturado y validado. El campo es
   redundante para efectos SRI; conviene decidir si es fuente de verdad (y
   usarlo en el XML) o eliminarlo del formulario.

4. **Validación laxa de `type_identification` e `identication`.**
   `type_identification` solo valida `string` sin `in:[...]` pese a que la BD
   lo limita a 10 chars y semánticamente es un catálogo cerrado
   (cédula/RUC/pasaporte/exterior). `identication` no tiene `max:13` acorde a
   la columna. (`CarrierStoreRequest.php:19-20`).

5. **`UniqueBranchScoped` ignora soft-deletes.** Usa
   `\DB::table($this->table)` (`UniqueBranchScoped.php:57`), que no filtra
   `deleted_at`. Un transportista con guías (soft-deleted en `destroy`)
   bloquea recrear otro con la misma `identication` en la sucursal, sin
   mensaje que lo explique. Verificar si es el comportamiento deseado.

6. **Typos en las claves de respuesta JSON.** `create` y `destroy` devuelven
   `'succes'` en lugar de `'success'`
   (`CarrierController.php:40,98,104`), contrato inconsistente para el
   frontend.

7. **Formas de respuesta inconsistentes.** `index` usa `CarrierResources`,
   pero `store`, `edit`, `update` devuelven el modelo crudo
   (`response()->json($carrier)`) y `resolve` un array fusionado. El
   consumidor recibe estructuras distintas (`{id, atts:{...}}` vs. modelo
   plano) para la misma entidad.
