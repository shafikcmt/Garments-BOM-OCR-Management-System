#!/bin/bash
# Garments OCR live deploy.
# Run by the GitHub Actions self-hosted runner (.github/workflows/deploy.yml)
# or by hand on the server:  bash deploy.sh
#
# Data safety rules built into this script:
#   - A verified PostgreSQL backup is taken BEFORE anything changes.
#     If the backup fails or looks incomplete, the deploy stops. Nothing is touched.
#   - Only "php artisan migrate --force" runs. Never migrate:fresh, db:wipe or seed.
#   - The site is put in maintenance mode while code and database change,
#     so nobody saves data half-way through a deploy.
#   - If a migration fails, the site stays in maintenance mode and the
#     restore command is printed. Nothing is rolled back automatically.

set -Eeuo pipefail

PROJECT_DIR="/home/hapl/hapl_ocr_3002/garments-ocr"
BRANCH="${DEPLOY_BRANCH:-production}"
APP_PORT="3002"

DB_CONTAINER="garments_ocr_db_3002"
DB_NAME="garments_ocr"
DB_USER="garments_ocr"

# Backups live OUTSIDE the project folder so a git reset or a rebuild never touches them.
BACKUP_DIR="/home/hapl/garments_ocr_backups/deploy"
KEEP_BACKUPS=30

# Run from a temp copy: git reset below replaces this file while it is running.
if [ -z "${DEPLOY_SH_COPIED:-}" ]; then
  TMP_COPY="/tmp/garments_ocr_deploy_running.sh"
  cp "$0" "$TMP_COPY"
  chmod +x "$TMP_COPY"
  export DEPLOY_SH_COPIED=1
  exec "$TMP_COPY" "$@"
fi

cd "$PROJECT_DIR"

STAGE="pre-check"
BACKUP_FILE=""

on_error() {
  echo ""
  echo "===================================================="
  echo " DEPLOY FAILED at stage: $STAGE"
  echo "===================================================="
  case "$STAGE" in
    pre-check|backup)
      [ -n "$BACKUP_FILE" ] && rm -f "$BACKUP_FILE"   # never keep a broken backup file
      echo "Nothing was changed. The live site is running the old version."
      ;;
    migrate)
      echo "A migration failed. The site is LEFT IN MAINTENANCE MODE on purpose."
      echo "Check the error above, then either fix and deploy again, or restore:"
      echo "  gunzip -c $BACKUP_FILE | docker exec -i $DB_CONTAINER psql -U $DB_USER -d $DB_NAME"
      echo "  (restore only after checking with the developer)"
      echo "To bring the site back without restoring:"
      echo "  docker compose exec -T app php artisan up"
      ;;
    *)
      echo "Database migration did not start or already finished. Bringing the site back up."
      docker compose exec -T app php artisan up || true
      echo "Backup taken before this deploy: $BACKUP_FILE"
      ;;
  esac
}
trap on_error ERR

echo "===================================="
echo " Garments OCR Deploy Started"
echo " Branch: $BRANCH"
echo " Time:   $(date '+%Y-%m-%d %H:%M:%S')"
echo "===================================="

# ---------------------------------------------------------------- pre-check
echo "Checking important files..."
[ -f ".env" ] || { echo "ERROR: .env file missing. Deployment stopped."; exit 1; }
[ -f "docker-compose.yml" ] || { echo "ERROR: docker-compose.yml missing. Deployment stopped."; exit 1; }

echo "Checking database container..."
if ! docker ps --format '{{.Names}}' | grep -q "^${DB_CONTAINER}$"; then
  echo "ERROR: DB container $DB_CONTAINER is not running. No backup possible, deployment stopped."
  exit 1
fi

echo "Checking for edited tracked files on the server..."
# Untracked files (Dockerfile, backups, old scripts) are ignored here and are never deleted.
if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
  git status --short --untracked-files=no
  echo "ERROR: Files tracked by git were edited directly on the server."
  echo "A deploy would overwrite them, so it stopped. Move those edits into the repo first,"
  echo "or save them with: git stash"
  exit 1
fi

echo "Fetching $BRANCH..."
git fetch origin "$BRANCH"
echo "Current: $(git log --oneline -1 HEAD)"
echo "New:     $(git log --oneline -1 "origin/$BRANCH")"

# ---------------------------------------------------------------- backup
STAGE="backup"
echo "Creating database backup BEFORE any change..."
mkdir -p "$BACKUP_DIR"
BACKUP_FILE="$BACKUP_DIR/garments_ocr_before_deploy_$(date +%Y%m%d_%H%M%S).sql.gz"
docker exec "$DB_CONTAINER" pg_dump -U "$DB_USER" "$DB_NAME" | gzip > "$BACKUP_FILE"

# A complete pg_dump always ends with this line. No line = incomplete backup = stop.
if ! gunzip -c "$BACKUP_FILE" | tail -n 5 | grep -q "PostgreSQL database dump complete"; then
  echo "ERROR: Backup looks incomplete. Deployment stopped. Nothing was changed."
  rm -f "$BACKUP_FILE"
  exit 1
fi
echo "Backup OK: $BACKUP_FILE ($(du -h "$BACKUP_FILE" | cut -f1))"

echo "Keeping latest $KEEP_BACKUPS deploy backups..."
ls -1t "$BACKUP_DIR"/garments_ocr_before_deploy_*.sql.gz 2>/dev/null | tail -n +$((KEEP_BACKUPS + 1)) | xargs -r rm -f

# ---------------------------------------------------------------- update code
STAGE="maintenance"
echo "Putting site in maintenance mode..."
docker compose exec -T app php artisan down --retry=60 || true

STAGE="code"
echo "Updating code to origin/$BRANCH..."
git checkout -B "$BRANCH" "origin/$BRANCH"
git reset --hard "origin/$BRANCH"   # tracked files only; .env, storage, untracked files untouched

echo "Starting / rebuilding Docker containers..."
docker compose up -d --build
sleep 5
docker compose exec -T app php artisan down --retry=60 || true

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
  echo "ERROR: APP_KEY is empty on live. Not generating a new one (it would log everyone out"
  echo "and break encrypted values). Set it by hand, then deploy again."
  exit 1
fi

echo "Clearing cache before migration..."
docker compose exec -T app php artisan optimize:clear

# ---------------------------------------------------------------- migrate
echo "Pending migrations:"
docker compose exec -T app php artisan migrate:status | grep -i "pending" || echo "  (none)"

STAGE="migrate"
echo "Running migrations..."
docker compose exec -T app php artisan migrate --force
STAGE="post-migrate"

echo "Creating storage link..."
docker compose exec -T app php artisan storage:link || true

echo "Caching Laravel..."
docker compose exec -T app php artisan optimize:clear
docker compose exec -T app php artisan config:cache
docker compose exec -T app php artisan route:cache
docker compose exec -T app php artisan view:cache

echo "Fixing permissions..."
if sudo -n true 2>/dev/null; then
  sudo chown -R hapl:www-data storage bootstrap/cache public/build 2>/dev/null || true
  sudo chmod -R 775 storage bootstrap/cache 2>/dev/null || true
else
  echo "Passwordless sudo not available. Skipping permission fix."
fi

echo "Restarting queue workers and containers..."
docker compose exec -T app php artisan queue:restart || true
docker compose restart
sleep 5

echo "Bringing site back up..."
docker compose exec -T app php artisan up

# ---------------------------------------------------------------- health check
STAGE="health-check"
echo "Checking live site..."
for i in $(seq 1 20); do
  if curl -fsS -o /dev/null "http://127.0.0.1:${APP_PORT}/login"; then
    trap - ERR
    echo "===================================="
    echo " Garments OCR Deploy Completed"
    echo " Live at: $(git log --oneline -1 HEAD)"
    echo " Backup:  $BACKUP_FILE"
    echo "===================================="
    docker image prune -f >/dev/null 2>&1 || true
    exit 0
  fi
  sleep 3
done

echo "ERROR: Site did not answer on port ${APP_PORT}. Recent logs:"
docker compose logs --tail=80 app || true
exit 1
