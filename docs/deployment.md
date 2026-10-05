# Deployment Guide

This guide describes the production deployment flow for Business Dashboard.

> A ready-to-edit production template lives at [`.env.production.example`](../.env.production.example).
> Copy it to `.env` on the server rather than `.env.example` (which targets local dev).

## Production Hardening (must-do)

These three settings are safe for local development but **must be changed for
production**, or the app is either insecure or unreliable under load:

1. **`APP_ENV=production` and `APP_DEBUG=false`.** The dev template ships
   `APP_ENV=local` / `APP_DEBUG=true`; leaving those on in production leaks
   full stack traces and configuration to any visitor.
2. **Do not run on SQLite (`DB_CONNECTION=sqlite`).** SQLite serializes all
   writes to a single writer. The storefront (checkout, cart), admin panel,
   courier webhooks, and scheduled jobs write concurrently and will hit
   `database is locked` errors. Use MySQL 8+ or PostgreSQL.
3. **Do not use the `sync` queue driver.** With `sync`, queued jobs run inside
   the web request — courier webhook processing, the external courier fraud
   check (which logs into merchant panels), and the CRM Inbox's AI auto-reply
   agent (up to 6 sequential LLM calls) would all block checkout/webhook
   responses, and retry/backoff never fires. Set `QUEUE_CONNECTION=redis`
   (recommended — see "Redis on Coolify" under Queue Worker below) or
   `QUEUE_CONNECTION=database` if a Redis service isn't available yet, and run
   `php artisan queue:work` as a supervised process either way.

## Server Requirements

- PHP 8.4 or newer
- Composer
- Node.js 20
- MySQL or MariaDB for production
- Nginx or Apache pointed to `public/`
- Cron enabled for Laravel scheduler
- Queue worker enabled if `QUEUE_CONNECTION` is not `sync`

## Required Environment Values

Set these values in production:

```env
APP_NAME="Business Dashboard"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.com
ASSET_URL=https://your-domain.com
TRUSTED_PROXIES=*
APP_VERSION=2.52.0
APP_RELEASE_TYPE=minor
APP_RELEASE_DATE=2026-10-05

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=business_dashboard
DB_USERNAME=...
DB_PASSWORD=...

ADMIN_NAME="Super Admin"
ADMIN_EMAIL=admin@example.com
ADMIN_PASSWORD=

QUEUE_CONNECTION=sync
SESSION_DRIVER=file
CACHE_STORE=file

# Native Android app-update push (enable only after Firebase is provisioned).
FIREBASE_PUSH_ENABLED=false
FIREBASE_PROJECT_ID=
FIREBASE_CREDENTIALS=/run/secrets/firebase-service-account.json
# Use this instead of FIREBASE_CREDENTIALS when the host cannot mount files:
FIREBASE_CREDENTIALS_JSON_BASE64=
```

Never commit `.env` or production credentials. `ADMIN_PASSWORD` must be at least 12 characters and include uppercase and lowercase letters, numbers, and symbols.

`APP_URL` and `ASSET_URL` must both use `https://` in production. Coolify/Traefik terminates TLS at the reverse proxy, so `TRUSTED_PROXIES=*` allows Laravel to honor its forwarded HTTPS scheme. Restrict this value to known proxy addresses if the application container is also directly exposed to untrusted traffic.

For small single-server or SQLite installs, keep `SESSION_DRIVER=file`, `CACHE_STORE=file`, and `QUEUE_CONNECTION=sync`. For higher traffic MySQL deployments, use Redis where available (`SESSION_DRIVER=redis` with `SESSION_CONNECTION=session` — see "Redis on Coolify" below), or database queue/cache/sessions with a dedicated queue worker.

## File Uploads and Cloudflare R2

The application intentionally separates browser upload staging from permanent
R2 storage:

1. For supported admin image fields, FilePond first resizes large JPEG and PNG
   sources in the browser (1600px for product/slide/page media, 800px for
   logos/category tiles) before the network upload begins. It preserves aspect
   ratio, never upscales, and deliberately leaves SVG, GIF, and WebP sources
   on the safe server-only path so vectors and animations are not altered.
2. Livewire then stages the resulting file on its local `livewire-tmp` disk.
3. When the form is saved, the application validates and performs the final
   server-side WebP/metadata optimization, then writes public media to
   `r2_public` only when R2 is enabled.

Image fields accept a source image up to 12 MB so a camera photo can be reduced
before it crosses the network. This is an input ceiling, not a promise that an
untouched original is permanently stored: final server optimization remains
mandatory.

Keep these production values in place:

```env
FILESYSTEM_DISK=local
LIVEWIRE_TEMPORARY_UPLOAD_DISK=livewire-tmp
```

Do **not** set either variable to `r2`, `r2_public`, or `r2_private`. That
would change Livewire into a browser-to-R2 presigned upload flow, which needs
separate R2 CORS rules and is not this application's storage design.

For Nixpacks/Coolify, the committed `nginx.template.conf` raises the default
Nginx request limit from 1 MB to 16 MB and applies PHP-FPM upload limits of
12 MB per file / 16 MB per POST. This covers browser-side image source uploads
and the 10 MB voucher attachment field. The persistent volume must cover all of
`/app/storage` (not only `storage/app/public`), and both `storage/` and
`bootstrap/cache/` must be writable by the running PHP user.

A successful **Test public bucket** result validates a server-side R2
write/read/public-domain probe, but deliberately does not enable R2. In Cloud
Storage, then turn on **Enable R2 for new uploads** and click **Save settings**.
The status must read **R2 uploads active** before newly saved public media uses
the R2 bucket.

If FilePond says `The data.image.… failed to upload` immediately after choosing
a file, R2 has not been reached yet. Inspect the response for
`POST /livewire/upload-file`:

- `413` — proxy/Nginx/PHP upload limit; redeploy this Nginx template.
- `401` — signed URL, `APP_URL`, or trusted-proxy host/scheme mismatch.
- `419` — session/cookie/CSRF configuration issue.
- `500` — inspect Laravel logs and confirm temporary-storage permissions.

Safe post-deploy diagnosis (does not print credentials):

```bash
php artisan tinker --execute='$s=app(\App\Services\StorageSettingsService::class); dump(["r2_enabled"=>$s->enabled(),"public_configured"=>$s->isPublicConfigured(),"permanent_public_disk"=>app(\App\Services\CompanyStorageService::class)->publicDiskName(),"livewire_temp_disk"=>config("livewire.temporary_file_upload.disk"),"php_upload_max"=>ini_get("upload_max_filesize"),"php_post_max"=>ini_get("post_max_size"),"gd_loaded"=>extension_loaded("gd")]);'
```

## First Deploy

```bash
composer install --no-dev --optimize-autoloader
npm ci
npm run build
php artisan key:generate
php artisan migrate --force
php artisan storage:link
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

If this is a brand-new installation with no real business data yet, create the first admin user with the guarded seeder or the admin command:

```bash
php artisan db:seed --force
```

Create or reset the admin user later with:

```bash
php artisan admin:ensure-super --email=admin@example.com --password="..."
```

## GitHub Actions Deploy

The included workflow deploys pushes to `main`. Configure these repository secrets:

```txt
SERVER_HOST
SERVER_USER
SERVER_SSH_KEY
SERVER_PORT
DEPLOY_PATH
```

The deploy workflow runs tests before SSH deploy. On the server it:

1. Pulls latest code.
2. Installs PHP and Node dependencies.
3. Builds frontend assets.
4. Enables maintenance mode.
5. Creates a database backup.
6. Runs migrations.
7. Caches config, routes, and views.
8. Restarts queues.
9. Disables maintenance mode.

## Manual Update Deploy

```bash
cd /path/to/project
php artisan down --retry=60
php artisan backup:database
git pull origin main
composer install --no-dev --prefer-dist --no-interaction --no-progress
npm ci
npm run build
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart
php artisan up
```

Do not run `php artisan db:seed --force` during routine updates after real customers, purchases, products, or users exist.

## User-Controlled Admin App Upgrade

`npm run build` now writes `public/build/deployment.json` after Vite finishes. Do not skip or reorder that step: the file gives every release a deterministic identity from the source tree, built assets, and Git/platform commit when available.

After `php artisan migrate --force`, existing users retain their last acknowledged deployment. When the new build is stable:

- the open admin app continues without an automatic full reload, and pending same-origin Filament SPA navigation is held on the already-loaded screen;
- **Upgrade App** appears above **Sign out** in the avatar menu;
- the Filament bell receives one persistent app-update notification per user/build;
- Release Notes shows the acknowledged installed version separately from the newer available version;
- the user can save unfinished work and choose when to reload.

Receiving an in-app notification or Android push, opening Release Notes, opening or dismissing the upgrade modal, and tapping **Not now** never acknowledge the deployment. Only the authenticated **Upgrade App** confirmation posts the exact deployment ID, records the installed version, clears cached app files, and performs the full reload.

The alert is inserted synchronously and therefore does not depend on the queue worker. The scheduler remains a recovery path, and this command can be run immediately after deployment:

```bash
php artisan release:notify-deploy
```

Verify the identity and cache policy:

```bash
curl -i https://your-domain.com/health/version
```

Expect a non-empty `deployment_id`, `built_at`, `"ready": true`, and `Cache-Control: no-store`. A `ready: false` response prevents upgrade prompts and normally means deployment metadata is missing, the actual Vite manifest hash differs, or runtime commit metadata and built files do not belong to the same release; rebuild before exposing that instance.

During a rolling replacement, keep build clocks synchronized. Server and browser
use `built_at` to reject responses from older nodes, and the upgrade POST must
match the exact deployment ID the user confirmed before it can acknowledge or
clear cached files.

This feature holds only the already-loaded frontend shell until consent. The
deployed PHP backend is still the active backend. A full page refresh, session
expiry/sign-in, Android process restart, WebView eviction, direct deep link, or
reopening the app can therefore load the current server release before the
button is clicked. A single Coolify container replacement cannot guarantee
continued use of the complete old application until approval.

True old-backend retention requires immutable old/new blue/green releases,
per-installation or per-user sticky routing that survives app restarts, shared
sessions/database/object storage, backward-compatible migrations, and an
explicit release promotion/retirement process. Do not describe the current
single-container guard as whole-application version pinning.

If a deploy fails while maintenance mode is enabled:

```bash
php artisan up
```

## Firebase Android App-Update Push

Native pushes require two independent Firebase configurations: the Android client configuration compiled into the APK and a server-side service account used by Laravel to call FCM HTTP v1. Never commit either credential, and never expose a service-account value through a `VITE_*` environment variable.

### 1. Register the Android app

1. Create or select the production Firebase project.
2. Add an Android app with the exact package name `com.zamzamint.erp`.
3. Download its `google-services.json`.
4. Keep that file outside the repository; `android/app/google-services.json` is ignored intentionally.
5. Enable the Firebase Cloud Messaging API (HTTP v1) for the same Google Cloud/Firebase project.

The GitHub Android build expects `google-services.json` as one base64-encoded repository secret named:

```txt
FIREBASE_ANDROID_GOOGLE_SERVICES_JSON_BASE64
```

Create the value on PowerShell:

```powershell
[Convert]::ToBase64String([IO.File]::ReadAllBytes("C:\secure\google-services.json"))
```

Or on Linux:

```bash
base64 -w 0 /secure/google-services.json
```

The workflow decodes that secret to `android/app/google-services.json` immediately before `npx cap sync android`; the JSON remains untracked and must not be added to a commit.

### 2. Configure Laravel/Coolify credentials

Create a Google service account in the same project, grant only the Firebase
Cloud Messaging API Admin role (`roles/firebasecloudmessaging.admin`), and then
generate its JSON key. Prefer mounting that JSON into the app container as a
read-only Coolify secret file:

```env
FIREBASE_PUSH_ENABLED=true
FIREBASE_PROJECT_ID=your-firebase-project-id
FIREBASE_CREDENTIALS=/run/secrets/firebase-service-account.json
FIREBASE_CREDENTIALS_JSON_BASE64=
```

If the Coolify environment cannot mount a secret file, store the base64-encoded service-account JSON as the masked secret `FIREBASE_CREDENTIALS_JSON_BASE64`, leave `FIREBASE_CREDENTIALS` blank, and keep the same project ID:

```env
FIREBASE_PUSH_ENABLED=true
FIREBASE_PROJECT_ID=your-firebase-project-id
FIREBASE_CREDENTIALS=
FIREBASE_CREDENTIALS_JSON_BASE64=BASE64_ENCODED_SERVICE_ACCOUNT_JSON
```

Do not configure both credential sources; the file path takes precedence when
both are present. Keep `FIREBASE_PUSH_ENABLED=false` until the Android Firebase
app, APK build secret, project ID, and one Laravel credential source all belong
to the same Firebase project.

### 3. Deploy, register devices, and notify

After the new container is healthy:

```bash
php artisan migrate --force
php artisan config:cache
php artisan release:notify-deploy
```

The command synchronizes Filament database notifications first and then sends one FCM update alert per active device/deployment. Failed transient deliveries remain eligible for the scheduled retry; provider-confirmed unregistered or mismatched tokens are disabled.

Use the same three commands as the Coolify post-deploy hook, or let the
five-minute scheduler perform notification catch-up after the migration. Never
run `release:notify-deploy` against a container whose `/health/version` response
has `"ready": false`.

Keep Laravel's scheduler running because `release:notify-deploy` is scheduled every five minutes as the catch-up/retry path:

```cron
* * * * * cd /path/to/project && php artisan schedule:run >> /dev/null 2>&1
```

The migration creates the native device and delivery ledgers plus the user's acknowledged release metadata. A signed-in native Android app registers its FCM token through authenticated `POST /admin/push-devices` after notification permission is granted.

The first FCM-enabled APK cannot send a push back in time to an installation that has never registered. Each device must install/open that updated APK, sign in, and grant notification permission once; only then can later deployments reach it while the app is backgrounded or closed. Receiving or tapping the push reveals the pending upgrade after server verification but does not acknowledge or reload the app.

The current GitHub workflow builds a debug APK for validation. A production
release still needs the normal signed APK/AAB build and distribution process.
If `FIREBASE_ANDROID_GOOGLE_SERVICES_JSON_BASE64` is absent, the Android build
continues without applying Google Services, so that artifact cannot register an
FCM token.

### 4. Verify without exposing credentials

Confirm that Laravel can parse the configured service account:

```bash
php artisan tinker --execute='dump(app(\App\Services\FirebaseHttpV1Sender::class)->isConfigured());'
```

The result must be `true`. Then open the signed Android app, sign in, grant
notification permission, and confirm at least one active registration without
printing the encrypted token:

```bash
php artisan tinker --execute='dump(\App\Models\PushDevice::query()->where("is_active", true)->count());'
```

Run the deployment notifier and inspect delivery state:

```bash
php artisan release:notify-deploy
php artisan tinker --execute='dump(\App\Models\AppUpdatePushDelivery::query()->selectRaw("status, count(*) as total")->groupBy("status")->pluck("total", "status")->all());'
```

Expected behavior:

- the Android notification is visible when the app is backgrounded;
- tapping it opens/reuses the app and reveals the verified update prompt;
- the user's `acknowledged_app_deployment_id` and
  `acknowledged_app_version` do not change until **Upgrade App** is confirmed;
- repeating `release:notify-deploy` does not send another successful push for
  the same device/deployment pair.

### 5. Roll back safely

To stop native sending immediately, set `FIREBASE_PUSH_ENABLED=false` and run
`php artisan config:cache`. This does not delete registered devices or delivery
history.

For an application rollback, redeploy the previous immutable image/source
artifact, clear/rebuild Laravel caches, restart queue workers, and verify
`/health/version` before reopening traffic. Keep the two app-update migrations
in place: they are additive, and the previous application ignores their extra
tables/columns. Do not run `php artisan migrate:rollback` blindly on production;
it drops the push ledgers and acknowledged release metadata and may roll back a
different migration when batches contain other changes.

If a release includes an incompatible or destructive migration, restore the
matching pre-deploy database backup under a separately reviewed rollback plan.
Rolling the single container back still does not create per-user old/new
version retention; that requires the blue/green routing architecture described
above.

## Live Data Safety

Application updates should keep live data when they use forward migrations and the existing production database. Data loss risk comes from destructive commands, destructive migrations, unsafe seeders, or pointing the app to the wrong database/storage.

Never run these commands against live production data:

```bash
php artisan migrate:fresh
php artisan migrate:refresh
php artisan migrate:reset
php artisan db:wipe
```

Before every production update:

1. Confirm the current code version and the target `APP_VERSION`.
2. Create a database backup with `php artisan backup:database`.
3. Run only reviewed migrations with `php artisan migrate --force`.
4. Verify admin login, dashboard totals, recent orders, purchases, payments, and stock after deploy.
5. Update `CHANGELOG.md` and GitHub release notes for the deployed version.

## Scheduler

Add this cron entry:

```cron
* * * * * cd /path/to/project && php artisan schedule:run >> /dev/null 2>&1
```

## Queue Worker

If `QUEUE_CONNECTION=sync`, no queue worker is required — but see the
Production Hardening warning above; `sync` should not be used in production.

```bash
php artisan queue:work --sleep=1 --tries=3 --max-time=3600
```

Use Supervisor or your hosting panel to keep the worker alive.

### Redis on Coolify (recommended queue + cache + session backend)

The app ships with `predis/predis` (a pure-PHP Redis client — no server
extension to compile, so it works on any Nixpacks build) and
`config/queue.php` / `config/cache.php` / `config/database.php` already have
ready-to-use `redis` connection blocks. Several things speed up directly: the
CRM Inbox's `AiAutoReplyJob` (moves the AI agent's LLM calls off the
request/webhook thread and onto a real background worker), `AiReplyService`'s
FAQ/delivery-charge lookups (cached 5 minutes per company via
`CACHE_STORE=redis`, instead of re-queried on every AI tool round), the
storefront home/category/product-listing queries (`App\Support\
StorefrontListingCache`, 10 minutes per company, invalidated immediately when
a Product/Category/StorefrontSetting is saved — see those models' `booted()`
hooks), and — with `SESSION_DRIVER=redis` — every logged-in request no longer
reads/writes a session row in MySQL, which matters once thousands of
customers are browsing concurrently.

1. **Add a Redis resource in Coolify** — same "one-click resource" flow
   already used for the MySQL database (Coolify project → Resources → New
   Resource → Redis). Note the internal host/port and the generated password
   Coolify shows you.
2. **Set these in the app's Coolify environment variables** (not committed —
   `.env.production.example` documents them as a template only):
   ```env
   QUEUE_CONNECTION=redis
   CACHE_STORE=redis
   SESSION_DRIVER=redis
   SESSION_CONNECTION=session
   REDIS_CLIENT=predis
   REDIS_HOST=<the Redis resource's internal hostname from Coolify>
   REDIS_PASSWORD=<the generated password>
   REDIS_PORT=6379
   ```
   `SESSION_CONNECTION=session` puts sessions on their own Redis database
   (`config/database.php` `redis.session`, `REDIS_SESSION_DB` defaults to
   `2`) separate from the `cache` one (`REDIS_CACHE_DB`, default `1`) and the
   queue's `default` one (`REDIS_DB`, default `0`) — so a `php artisan
   cache:clear` can never sign out every active customer/admin session along
   with it.
3. **Run a supervised queue worker as a second persistent Coolify service**
   (New Resource → a second app pointed at the same repo/image, or a
   Coolify "Docker Compose" service) whose start command is:
   ```bash
   php artisan queue:work --sleep=1 --tries=3 --max-time=3600
   ```
   Coolify restarts a crashed/redeployed service automatically, which is
   what "supervised" means here — a separate systemd/Supervisor config isn't
   needed on a Coolify-managed container.
4. Redeploy the main app so the new env values take effect, then confirm
   with `php artisan queue:work --once` (from a one-off Coolify command
   execution) that a queued job actually completes.

If a Redis resource isn't available yet, `QUEUE_CONNECTION=database` (needs
only the existing `jobs` table + a running worker, no extra service) and
`SESSION_DRIVER=database` still work — the AI auto-reply pipeline just won't
get the FAQ/delivery-charge caching speed-up (that one is Redis-only; the
storefront listing cache above works on any `CACHE_STORE`), and sessions add
one extra database read/write per request instead of hitting Redis.

## Reseller Portal Domain

Each company that uses the reseller module (**Company Management → Reseller module**) gets its own reseller dashboard at `https://reseller.<company-domain>/portal` (for example `reseller.zamzamint.com`), or at a fully custom domain set in **Company Management → Reseller portal domain**. The portal has its own login, separate from the shop and the admin panel (`SESSION_DOMAIN` stays empty, so the sessions never mix). No new environment variables.

For each company:

1. **DNS:** add an `A` (or `CNAME`) record `reseller` pointing to the same server as the company's main domain. For a separate reseller domain, point that whole domain at the server.
2. **Coolify:** add `https://reseller.<domain>` (or the custom domain) to the application's **Domains** list so Traefik issues its SSL certificate. A wildcard `*.domain` certificate needs a DNS challenge, so listing each host explicitly is simpler.
3. **Admin:** only for a custom domain, fill **Company Management → Reseller portal domain**. The `reseller.<domain>` form needs nothing.
4. **Build assets:** the portal has its own Filament theme (`resources/css/filament/reseller/theme.css`), so `npm run build` must run on deploy, as for the admin theme.

Behaviour to expect: on the portal host every non-portal path redirects to the portal, and `/portal` returns 404 on every other host. Resellers who open the old **My Account → Your Store** page are redirected to the portal (they sign in again there — it is a separate login on a different host). Applications now go through the portal's own **Become a reseller** form. Store links (`{company-domain}/store/{slug}`) do not change.

### Reseller store subdomains (`name.<company-domain>`)

Optional, per company. Needs two things on the server before **Company Management → Reseller subdomains enabled** is switched on:

1. **Wildcard DNS:** an `A` record `*.<company-domain>` pointing at the same server. Explicit records (`reseller`, `www`) keep working because they are more specific.
2. **Wildcard SSL:** a `*.<company-domain>` certificate. Let's Encrypt only issues wildcards through a DNS challenge, so Traefik/Coolify needs your DNS provider's API token for that domain. Without it, subdomains open with a certificate warning.

No environment variables, no per-reseller server work: once both are in place a reseller's chosen subdomain is live the moment they save it. Custom domains of their own (e.g. `myshop.com`) are a separate, later step.

### Reseller custom domains (`myshop.com`)

Optional, per company (**Reseller custom domains enabled**). The app side is finished: it verifies DNS, resolves the domain to the reseller's store, and answers `GET /internal/tls-ask?domain=<host>` with `200` only for a verified reseller domain (otherwise `404`). What the server needs is a proxy that gets an SSL certificate **on demand** and asks that endpoint first. Caddy does this:

```
{
    on_demand_tls {
        ask http://<app-internal-address>/internal/tls-ask
    }
}

https:// {
    tls {
        on_demand
    }
    reverse_proxy <app-internal-address>
}
```

It needs no account (Let's Encrypt certificates are free and automatic). It has to receive the traffic for reseller domains on ports 80/443, which Coolify's own Traefik already uses, so how to place it (in front of Traefik, or on its own IP for custom domains) depends on the live server and has to be decided there. Until then, leave **Reseller custom domains enabled** off. The DNS check compares the reseller's domain IPs with the company domain's IPs, so the company domain must resolve to this server.

## Production Checklist

- `APP_ENV=production`
- `APP_DEBUG=false`
- Strong `ADMIN_PASSWORD` with uppercase and lowercase letters, numbers, and symbols
- Company profile, currency, timezone, and logo configured
- HTTPS enabled
- `.env` not committed
- Scheduler running
- Queue worker running
- Backups tested
- File permissions set for `storage/` and `bootstrap/cache/`
- Admin users reviewed
- GitHub deploy secrets configured
- Reseller portal DNS record and Coolify domain added (if the reseller module is used)
