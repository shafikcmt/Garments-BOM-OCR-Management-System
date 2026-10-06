# Auto Deploy — Garments OCR (live server)

Same pattern as `company-website`, `ksa-embassy-app` and `mosla-ghor`:
**push to `master` = deploy to live.** A GitHub Actions self-hosted runner on the
office server runs `deploy.sh` (GitHub's own runners cannot reach the office server).

```text
Laptop / Claude session ──git push origin master──▶ GitHub ──▶ runner on server ──▶ deploy.sh
```

Because every push to master goes live, **only push to master when the work is ready
for live** (for example 3b, 3c and 3d together, with the deploy-day checklist in
`docs/10_DEPLOY_NOTES.md` ready). Unfinished work stays on the laptop or on a separate branch.

## What deploy.sh does (in order)

1. Checks `.env`, `docker-compose.yml`, DB container, and that nobody edited tracked files on the server.
2. **Takes a PostgreSQL backup** to `~/garments_ocr_backups/deploy/` and verifies it is complete.
   If the backup fails, the deploy stops and nothing changes.
3. Maintenance mode on (users see "back soon", nobody saves half-way).
4. Pulls `master`, rebuilds containers, composer install, npm build.
5. `php artisan migrate --force` (never `migrate:fresh`, `db:wipe` or seeders).
6. Caches, queue restart, maintenance mode off, health check on port 3002.

If a migration fails, the site **stays** in maintenance mode and the log prints the
restore command and the backup file name.

## One-time server setup

All commands as user `hapl` on the server.

### 1. Check for an old runner (do this FIRST)

GitHub → repo **Garments-BOM-OCR-Management-System** → Settings → Actions → Runners.
If an old runner is listed for this repo, remove it, so a push cannot start the old script.

### 2. Make sure hapl can use docker and git

```bash
groups                      # must include: docker
cd ~/hapl_ocr_3002/garments-ocr
git fetch origin            # must work without asking for a password
git status --untracked-files=no   # must be clean
```

### 3. Install the runner (own folder, like the others)

GitHub → Settings → Actions → Runners → **New self-hosted runner** → Linux x64.
Copy the download commands GitHub shows, but run them in a new folder:

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

## Every deploy

```bash
git push origin master
```

Watch it in GitHub → Actions. To re-run without a new commit:
Actions → "Deploy Garments OCR Live" → **Run workflow**.

## Restore a backup (only with the developer)

```bash
ls -lt ~/garments_ocr_backups/deploy/ | head
cd ~/hapl_ocr_3002/garments-ocr
docker compose exec -T app php artisan down
gunzip -c ~/garments_ocr_backups/deploy/<file>.sql.gz | docker exec -i garments_ocr_db_3002 psql -U garments_ocr -d garments_ocr
```

Restoring into a non-empty database needs care (drop/recreate the database first).
Test on a copy before doing it on live.
