# Deployment & CI/CD

Context for how the Momento backend is deployed to production (VPS).

## Target environment

- VPS host: `72.61.112.137` (configurable per script).
- App path: `/var/www/momento.sardarit.cloud`.
- PHP **8.3** (FPM: `php8.3-fpm`), web server **nginx**.
- Database: MySQL. Secrets come from DB `secret_keys` (via `SecretManager`),
  plus `.env` for framework config. `.env` is **never** committed or archived.

## Manual deploy — `deploy.sh`

Run locally. High level:
1. Test SSH connection to VPS (`ssh` with `BatchMode`, `StrictHostKeyChecking=no`).
2. `composer install --no-dev --optimize-autoloader` + `npm ci` (asset build
   currently commented out: `npm run build` is skipped).
3. Create `deploy.tar.gz` excluding `.git`, `node_modules`, `.env`, and
   `storage/{logs,framework/*}` caches.
4. `scp` archive to `/tmp/` on the VPS.
5. Over SSH: extract into `APP_PATH`, copy `.env.example` → `.env` if missing,
   `php artisan key:generate --force`, `php artisan migrate --force`,
   `config:cache` / `route:cache` / `view:cache`, `storage:link`,
   `chmod -R 775 storage bootstrap/cache`, reload `php8.3-fpm` + `nginx`.
6. Remove local archive.

> Note: `deploy.sh` uses SSH key `~/.ssh/id_rsa` and `REMOTE_USER=root`. The
> GitHub Actions path differs slightly (uses `secrets.VPS_SSH_PRIVATE_KEY`).

## CI/CD — `.github/workflows/deploy.yml`

Triggers on `push` to `main`. Steps:
1. Checkout (`actions/checkout@v4`).
2. Setup PHP 8.3 (`shivammathur/setup-php`, extensions `mbstring, bcmath,
   pdo_mysql`, composer v2).
3. Setup Node 22 (`actions/setup-node`).
4. `composer install --no-dev --optimize-autoloader`, `npm ci`, `npm run build`.
5. Archive `deploy.tar.gz` (excludes `.git`, `node_modules`, `.env`).
6. Upload to VPS via `scp` using `secrets.VPS_SSH_PRIVATE_KEY`, `VPS_USER`,
   `VPS_HOST`.
7. `appleboy/ssh-action` deploys: extract, write `.env` from `secrets.APP_ENV_FILE`
   if missing, `key:generate`, `migrate --force`, caches, `storage:link`,
   `chmod 775`, reload `php8.3-fpm` + `nginx`.

Required GitHub secrets: `VPS_SSH_PRIVATE_KEY`, `VPS_USER`, `VPS_HOST`,
`APP_ENV_FILE`.

## Env handling

- `.env.example` is committed and documents all keys (APP, DB, mail, JWT, TGC).
- Real `.env` lives only on the server (or in `secrets.APP_ENV_FILE`).
- TGC credentials (`TGC_USERNAME`, `TGC_PASSWORD`, `TGC_DESIGNER_ID`,
  `TGC_API_KEY`) are read at runtime via `SecretManager` from the `secret_keys`
  table, not from `.env` directly in code.

## Operational notes

- Queue: `QUEUE_CONNECTION=database` in production — a worker
  (`php artisan queue:listen`) must run for TGC publish jobs
  (`PublishDeckJob`, `PublishTradingJob`).
- Cache/config are warmed on deploy (`config:cache`, `route:cache`,
  `view:cache`); clear them after config changes (`php artisan config:clear`).
- After deploying, verify the Stripe and TGC webhook endpoints are reachable
  (they are public POST routes: `/api/webhook/stripe`,
  `/api/webhooks/tgc/receipt-shipped`).
