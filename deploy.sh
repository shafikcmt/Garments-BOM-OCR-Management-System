#!/bin/bash

set -e

PROJECT_DIR="/home/hapl/hapl_ocr_3002/garments-ocr"
BRANCH="master"

DB_CONTAINER="garments_ocr_db_3002"
DB_NAME="garments_ocr"
DB_USER="garments_ocr"

cd "$PROJECT_DIR"

echo "===================================="
echo " Garments OCR Deploy Started"
echo " Branch: $BRANCH"
echo "===================================="

echo "Checking important files..."
if [ ! -f ".env" ]; then
  echo "ERROR: .env file missing. Deployment stopped."
  exit 1
fi

if [ ! -f "docker-compose.yml" ]; then
  echo "ERROR: docker-compose.yml missing. Deployment stopped."
  exit 1
fi

echo "Pulling latest code..."
git fetch origin "$BRANCH"
git reset --hard "origin/$BRANCH"

echo "Starting / rebuilding Docker containers..."
docker compose up -d --build

echo "Waiting for containers..."
sleep 5

echo "Installing PHP dependencies..."
docker compose exec -T app composer install --no-dev --optimize-autoloader --no-interaction

echo "Installing frontend dependencies..."
if [ -f "package-lock.json" ]; then
  docker compose exec -T app npm ci
else
  docker compose exec -T app npm install
fi

echo "Building frontend..."
docker compose exec -T app npm run build

echo "Checking APP_KEY..."
APP_KEY_VALUE=$(grep '^APP_KEY=' .env | cut -d '=' -f2-)
if [ -z "$APP_KEY_VALUE" ]; then
  echo "APP_KEY missing. Generating..."
  docker compose exec -T app php artisan key:generate --force
fi

echo "Clearing cache before migration..."
docker compose exec -T app php artisan optimize:clear
docker compose exec -T app php artisan config:clear
docker compose exec -T app php artisan cache:clear
docker compose exec -T app php artisan route:clear
docker compose exec -T app php artisan view:clear

echo "Creating database backup before migration..."
mkdir -p storage/app/backups/db
BACKUP_FILE="storage/app/backups/db/garments_ocr_before_deploy_$(date +%Y%m%d_%H%M%S).sql"

if docker ps --format '{{.Names}}' | grep -q "^${DB_CONTAINER}$"; then
  docker exec "$DB_CONTAINER" pg_dump -U "$DB_USER" "$DB_NAME" > "$BACKUP_FILE"
  echo "Backup created: $BACKUP_FILE"
else
  echo "WARNING: DB container not found. Backup skipped."
fi

echo "Running migrations..."
docker compose exec -T app php artisan migrate --force

echo "Creating storage link..."
docker compose exec -T app php artisan storage:link || true

echo "Clearing and caching Laravel..."
docker compose exec -T app php artisan optimize:clear
docker compose exec -T app php artisan config:cache
docker compose exec -T app php artisan route:cache
docker compose exec -T app php artisan view:cache

echo "Fixing permissions..."
sudo chown -R hapl:www-data storage bootstrap/cache public/build 2>/dev/null || true
sudo chmod -R 775 storage bootstrap/cache 2>/dev/null || true

echo "Keeping latest 7 DB backups only..."
ls -1t storage/app/backups/db/*.sql 2>/dev/null | tail -n +8 | xargs -r rm -f

echo "Restarting containers..."
docker compose restart

echo "Checking live site..."
sleep 3
curl -I http://127.0.0.1:3002 || true

echo "===================================="
echo " Garments OCR Deploy Completed"
echo "===================================="
