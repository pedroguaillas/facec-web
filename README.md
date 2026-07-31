# facec-web

Migración de **facec-api** (backend Lumen → Laravel, Sanctum + JSON) y **facec-front-next** (Next.js) a un único monolito **Laravel 13 + Inertia v3 + Vue 3**, corriendo sobre Laravel Sail.

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

La app queda en `http://localhost` (puerto 80, no 8000 — Sail sirve por Apache/nginx del contenedor, `php artisan serve` en 8000 no está expuesto al host).

Frontend (Vite):

```bash
./vendor/bin/sail composer dev
```

Esto levanta server + queue + logs + vite en paralelo (script `composer.json` → `dev`).

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
- Certificados de firma (`.p12`) y logos van en `storage/app/private/cert/` y `storage/app/logos/` respectivamente — no vienen en el dump SQL, hay que copiarlos aparte si se migran datos reales.
- phpMyAdmin disponible en `http://localhost:8080` (sin login, apunta directo al mysql de Sail) para inspeccionar el import.

## Servicios adicionales (compose.yaml)

| Servicio | Puerto host | Uso |
|---|---|---|
| `laravel.test` | 80, 5173 | App + Vite |
| `mysql` | 3306 | Base de datos |
| `redis` | 6379 | Cache/queue |
| `xml-signer` | — (interno) | Microservicio Go que firma XML (facturación electrónica SRI) |
| `phpmyadmin` | 8080 | UI de administración de MySQL |
