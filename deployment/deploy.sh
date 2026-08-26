#!/usr/bin/env bash
# deploy.sh — Despliegue y actualización de facec-web (Docker)
# Uso: bash deployment/deploy.sh
set -euo pipefail

APP_DIR="/var/www/facec-web"
COMPOSE_FILE="compose.prod.yaml"
ENV_FILE=".env.production"
COMPOSE="docker compose -f $COMPOSE_FILE --env-file $ENV_FILE"

echo "── Desplegando facec-web ──────────────"

cd "$APP_DIR"

# ── 1. Obtener cambios ───────────────────────────────────────────────────────
git pull origin main

# ── 2. Build de la imagen app (usa Dockerfile.prod) ──────────────────────────
$COMPOSE build app

# ── 3. Recrear solo lo que cambió (mysql/redis/go-signer quedan intactos) ────
$COMPOSE up -d

# ── 4. Migraciones (manual a propósito, ver docker/production/entrypoint.sh) ─
echo "Revisa si este deploy trae migraciones nuevas antes de correr:"
echo "  $COMPOSE exec app php artisan migrate --force"

echo "── Despliegue completado ✓ ──────────────"
