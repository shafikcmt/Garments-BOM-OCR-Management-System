# Auto Deploy — Garments OCR (live server)

Same pattern as `company-website`: a GitHub Actions **self-hosted runner** on the
office server runs `deploy.sh` (GitHub's own runners cannot reach the office server).

One difference, on purpose: **live deploys from the `production` branch, not `master`.**
Daily work (and Claude sessions) push to `master` freely. Live changes only when you
deliberately move `production` forward. This keeps half-finished work (for example
3b without 3c) off the live database.

```text
Laptop / Claude session ──push──▶ master            (no deploy)
                                     │
            git push origin master:production
                                     ▼
                                production ──▶ runner on server ──▶ deploy.sh
```

## What deploy.sh does (in order)

1. Checks `.env`, `docker-compose.yml`, DB container, and that nobody edited tracked files on the server.
2. **Takes a PostgreSQL backup** to `~/garments_ocr_backups/deploy/` and verifies it is complete.
   If the backup fails, the deploy stops and nothing changes.
3. Maintenance mode on (users see "back soon", nobody saves half-way).
4. Pulls `production`, rebuilds containers, composer install, npm build.
5. `php artisan migrate --force` (never `migrate:fresh`, `db:wipe` or seeders).
6. Caches, queue restart, maintenance mode off, health check on port 3002.

If a migration fails, the site **stays** in maintenance mode and the log prints the
restore command and the backup file name.

## One-time server setup

All commands as user `hapl` on the server.

### 1. Check for an old runner

GitHub → repo **Garments-BOM-OCR-Management-System** → Settings → Actions → Runners.
If an old runner is listed for this repo, remove it there (and its folder on the server)
so only the new one below is used.

### 2. Make sure hapl can use docker and git

```bash
groups                      # must include: docker
cd ~/hapl_ocr_3002/garments-ocr
git fetch origin            # must work without asking for a password
git status --untracked-files=no   # must be clean
```

### 3. Install the runner (own folder, like the others)

GitHub → Settings → Actions → Runners → **New self-hosted runner** → Linux x64.
Copy the download + config commands GitHub shows, but run them in a new folder:

```bash
mkdir ~/actions-runner-garments-ocr && cd ~/actions-runner-garments-ocr
# curl -o actions-runner-linux-x64-....tar.gz -L https://github.com/actions/runner/releases/...   (from GitHub page)
# tar xzf ./actions-runner-linux-x64-....tar.gz
./config.sh --url https://github.com/shafikcmt/Garments-BOM-OCR-Management-System \
  --token <TOKEN_FROM_GITHUB_PAGE> \
  --name omni-garments-ocr --labels garments-ocr-deploy --unattended
sudo ./svc.sh install hapl
sudo ./svc.sh start
sudo ./svc.sh status
```

The runner should now show **Idle** on the GitHub Runners page.

### 4. Create the production branch (first time only, from the laptop)

Only after the deploy-day checklist in `docs/10_DEPLOY_NOTES.md` is ready:

```bash
git push origin master:production
```

This starts the first auto deploy. Watch it in GitHub → Actions.

## Every deploy after that

```bash
git push origin master                 # save work (no deploy)
git push origin master:production      # deploy to live
```

Or GitHub → Actions → "Deploy Garments OCR Live" → **Run workflow** (re-deploys `production`).

## Restore a backup (only with the developer)

```bash
ls -lt ~/garments_ocr_backups/deploy/ | head
cd ~/hapl_ocr_3002/garments-ocr
docker compose exec -T app php artisan down
gunzip -c ~/garments_ocr_backups/deploy/<file>.sql.gz | docker exec -i garments_ocr_db_3002 psql -U garments_ocr -d garments_ocr
```

Restoring into a non-empty database needs care (drop/recreate the database first).
Test on a copy before doing it on live.
