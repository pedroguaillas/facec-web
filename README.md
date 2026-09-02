# facec-web

Migración de **facec-api** (backend Lumen → Laravel) a **Laravel 13 + Sanctum**, API JSON pura, corriendo sobre Laravel Sail. Consumida por `facec-front-next` (Next.js) u otro frontend separado.

Auth vía token Bearer: `POST /api/login` (`{user, password}`) devuelve `{token, user, permissions}`; usar `Authorization: Bearer <token>` en el resto de los endpoints. `POST /api/logout` revoca el token actual.

## Requisitos

- Docker Desktop
- PHP no es necesario en el host (todo corre dentro de Sail)

## Instalación

```bash
git clone <este-repo>
cd facec-web
cp .env.example .env

./vendor/bin/sail up -d
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate --seed
```

La API queda en `http://localhost/api` (puerto 80, no 8000 — Sail sirve por Apache/nginx del contenedor, `php artisan serve` en 8000 no está expuesto al host).

## Importar base de datos existente al Docker (Sail)

Esto es lo más importante si venís migrando datos reales desde `facec-api`.

### 1. Levantar Sail

```bash
./vendor/bin/sail up -d
```

### 2. Dar privilegio SUPER al usuario de la app (necesario para importar dumps con triggers/rutinas)

```bash
./vendor/bin/sail exec mysql mysql -u root -ppassword -e "GRANT SUPER ON *.* TO 'sail'@'%'; FLUSH PRIVILEGES;"
```

> Alternativa más acotada (solo lo necesario para que las migraciones con `CREATE TRIGGER` corran, sin dar SUPER completo):
> ```bash
> ./vendor/bin/sail exec mysql mysql -u root -ppassword -e "SET GLOBAL log_bin_trust_function_creators = 1;"
> ```
> Esta variable no persiste si el contenedor se recrea; el `GRANT SUPER` de arriba sí persiste (queda en el volumen `sail-mysql`).

### 3. Importar el dump

```bash
./vendor/bin/sail mysql < /ruta/al/dump.sql
```

(usa las credenciales de `.env`: `DB_DATABASE=laravel`, `DB_USERNAME=sail`, `DB_PASSWORD=password` — no hace falta pasarlas a mano, `sail mysql` ya las toma del entorno del contenedor).

Si el dump es de un esquema viejo (Lumen/API anterior), corré las migraciones nuevas después para traer las tablas que no existían ahí (`sessions`, `cache`, `jobs`, `personal_access_tokens`, etc.):

```bash
./vendor/bin/sail artisan migrate
```

### 4. Revertir el privilegio SUPER (opcional, por seguridad)

```bash
./vendor/bin/sail exec mysql mysql -u root -ppassword -e "REVOKE SUPER ON *.* FROM 'sail'@'%'; FLUSH PRIVILEGES;"
```

### Notas

- El volumen `sail-mysql` persiste entre reinicios de contenedor. Para partir de cero: `./vendor/bin/sail down -v` (⚠️ borra los datos) y volver a levantar.
- Certificados de firma (`.p12`) van en `storage/app/private/cert/` y logos en `storage/app/public/logos/` (con `php artisan storage:link` corrido) — no vienen en el dump SQL, hay que copiarlos aparte si se migran datos reales.
- phpMyAdmin disponible en `http://localhost:8080` (sin login, apunta directo al mysql de Sail) para inspeccionar el import.

## Servicios adicionales (compose.yaml)

| Servicio | Puerto host | Uso |
|---|---|---|
| `laravel.test` | 80 | API |
| `mysql` | 3306 | Base de datos |
| `redis` | 6379 | Cache/queue |
| `xml-signer` | — (interno) | Microservicio Go que firma XML (facturación electrónica SRI) |
| `phpmyadmin` | 8080 | UI de administración de MySQL |

## Producción (VPS)

Acceso: `ssh facec-do` (alias en `~/.ssh/config`, user `root`, key `~/.ssh/facec-do`).

Todo comando de producción usa `compose.prod.yaml` + `.env.production` (nunca el `.env` de dev):

```bash
docker compose -f compose.prod.yaml --env-file .env.production ps
docker compose -f compose.prod.yaml --env-file .env.production up -d --build
```

### Ver logs

```bash
# Logs de Laravel (storage/logs/laravel.log) — NO persiste en volume, se pierde si se recrea el contenedor
docker compose -f compose.prod.yaml --env-file .env.production exec app tail -f storage/logs/laravel.log

# Logs de docker (stdout del contenedor)
docker compose -f compose.prod.yaml --env-file .env.production logs -f app
```

### Facturas atascadas en estado `CREADO`

El procesamiento corre por cola (`ProcessVoucherJob`, ver `docs/facturacion-electronica/README.md` §2) con reintento automático (hasta 8 intentos, backoff de 30s a 10min) — un comprobante recién creado puede tardar unos minutos en avanzar solo. Si sigue en `CREADO` después de eso:

- **Container `queue` caído o no corre** (primer chequeo): `docker compose -f compose.prod.yaml --env-file .env.production ps` — sin él, los jobs se quedan encolados sin procesar. `docker compose -f compose.prod.yaml --env-file .env.production logs -f queue`.
- **Certificado `.p12` con encoding BER en vez de DER estricto** — el firmador Go (`go-signer`) exige DER estricto; error en logs: `"Failed to decode certificate: pkcs12: error reading P12 data: asn1: syntax error: indefinite length found (not DER)"`. Fix (reencodear con openssl, requiere el password guardado en `companies.pass_cert`):

  ```bash
  docker compose -f compose.prod.yaml --env-file .env.production exec app \
    cat storage/app/private/cert/<cert_dir> > /tmp/cert.p12

  openssl pkcs12 -in /tmp/cert.p12 -out /tmp/cert.pem -nodes -legacy   # OpenSSL 3.x necesita -legacy para leer p12 viejos
  openssl pkcs12 -export -in /tmp/cert.pem -out /tmp/cert_fixed.p12 -name "cert"

  docker compose -f compose.prod.yaml --env-file .env.production exec -T app \
    tee storage/app/private/cert/<cert_dir> < /tmp/cert_fixed.p12 > /dev/null

  rm /tmp/cert.p12 /tmp/cert.pem /tmp/cert_fixed.p12   # cert.pem tiene la clave privada sin cifrar, borrar siempre
  ```

  Verificar `cert_dir` y `pass_cert` de la company:
  ```bash
  docker compose -f compose.prod.yaml --env-file .env.production exec app php artisan tinker --execute='dump(App\Models\Company::whereNotNull("cert_dir")->get(["id","cert_dir"])->toArray());'
  ```

- **Certificado `.p12` con más de una llave privada** (distinto del caso BER/DER de arriba) — error en logs: `"pkcs12: expected exactly one key bag"`. `go-pkcs12` (librería de `go-signer`) exige exactamente una key bag; algunos certificados (p. ej. los del **Banco Central del Ecuador**, que separan `Signing Key`/`Decryption Key` en el mismo archivo) traen varias. No lo soluciona el reencode de arriba (duplica las llaves igual). Usar en vez del procedimiento manual:

  ```bash
  # 1. Diagnóstico — lista cada bag (llave/cert) con su localKeyID y friendlyName, no modifica nada
  docker compose -f compose.prod.yaml --env-file .env.production exec app \
    php artisan cert:inspect <company_id>

  # 2. Con el localKeyID de la llave de FIRMA (friendlyName suele decir "Signing Key"),
  #    genera un .p12 limpio como <cert_dir>.fixed.p12 sin tocar el original todavía
  docker compose -f compose.prod.yaml --env-file .env.production exec app \
    php artisan cert:extract-signing-key <company_id> --key-id=<localKeyID>

  # 3. Recién con --apply reemplaza el .p12 real — hace backup automático (<cert_dir>.bak-<timestamp>.p12) antes
  docker compose -f compose.prod.yaml --env-file .env.production exec app \
    php artisan cert:extract-signing-key <company_id> --key-id=<localKeyID> --apply
  ```

  `pass_cert` se reutiliza tal cual está en la company (no hay que reescribirlo ni arriesgarse a que la password del `.p12` reexportado deje de coincidir con la guardada en BD).

  <details>
  <summary>Procedimiento manual (sin los comandos artisan) — solo si hace falta reproducirlo a mano</summary>

  ```bash
  # 1. Traer el .p12 a la máquina local
  docker compose -f compose.prod.yaml --env-file .env.production exec app \
    cat storage/app/private/cert/<cert_dir> > /tmp/cert.p12

  # 2. Ver cuántos bags trae y sus localKeyID/friendlyName (pide el pass_cert)
  openssl pkcs12 -info -in /tmp/cert.p12 -noout -legacy

  # 3. Volcar todo con atributos a texto plano
  openssl pkcs12 -in /tmp/cert.p12 -nodes -legacy 2>/dev/null > /tmp/full_info.pem

  # 4. Partir en un archivo por cada bag
  awk -v RS="Bag Attributes" 'NR>1{print "Bag Attributes" $0 > ("/tmp/bag_" NR-1 ".txt")}' /tmp/full_info.pem

  # 5. Ubicar el bloque de la Signing Key y el de la Verification Certificate (mismo localKeyID entre los dos)
  grep -l "Signing Key" /tmp/bag_*.txt
  grep -l "Verification Certificate" /tmp/bag_*.txt

  # 6. Extraer el PEM (BEGIN...END) de cada bloque encontrado — reemplazar N y M por los números reales
  sed -n '/-----BEGIN/,/-----END/p' /tmp/bag_N.txt > /tmp/signing_key.pem
  sed -n '/-----BEGIN/,/-----END/p' /tmp/bag_M.txt > /tmp/verification_cert.pem
  cat /tmp/signing_key.pem /tmp/verification_cert.pem > /tmp/signing_pair.pem

  # 7. Reexportar como p12 limpio, password explícita (la de companies.pass_cert)
  openssl pkcs12 -export -in /tmp/signing_pair.pem -out /tmp/cert_fixed.p12 -name "cert" \
    -passin pass:<PASS_CERT> -passout pass:<PASS_CERT>

  # 8. Verificar: debe mostrar EXACTAMENTE un Shrouded Keybag
  openssl pkcs12 -info -in /tmp/cert_fixed.p12 -noout -legacy

  # 9. Backup del cert actual en el contenedor antes de pisarlo
  docker compose -f compose.prod.yaml --env-file .env.production exec app \
    cp storage/app/private/cert/<cert_dir> storage/app/private/cert/<cert_dir>.bak-$(date +%Y%m%d-%H%M%S)

  # 10. Recién ahí, subir el .p12 limpio
  docker compose -f compose.prod.yaml --env-file .env.production exec -T app \
    tee storage/app/private/cert/<cert_dir> < /tmp/cert_fixed.p12 > /dev/null
  ```

  No borrar `/tmp/cert.p12` ni `/tmp/cert_fixed.p12` hasta confirmar en logs que `go-signer` firma bien con el nuevo archivo (ver "Ver logs" arriba) — este fue justo el paso que faltó la vez que se rompió la firma sin dejar respaldo.
  </details>

- **Falta variable de entorno en `.env.production`** — p. ej. `SRI_CATASTRO_URL` (usada por `App\Services\SriResolveNameService` para buscar RUC en el SRI; si falta, `Http::get(null, ...)` explota con `TypeError`). Revisar que `.env.production` tenga todas las keys de `.env.production.example`.

### Notas sobre `tinker --execute`

`--execute` es una flag del comando (`php artisan tinker --execute='...'`), no algo que se pega dentro del shell interactivo. Y a diferencia del REPL, no auto-imprime el valor de retorno — envolver en `dump()` o `echo`.
