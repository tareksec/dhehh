# IMS v2 — brief for the AI agent that works on the VPS

> **মালিকের জন্য এক লাইনে:** এই পুরো ফাইলটা আপনার VPS-এর AI এজেন্টকে দিন। এতে লেখা আছে সে কী করবে, কী কখনো করবে না, আর কাজ শেষে আপনাকে কী জানাবে। ERP-র ভেতরের সুইচ (IMS চালু করা) আর `main`-এ আনা — এ দুটো আপনি নিজে করবেন।
>
> Written 2026-10-20. The step list in Bangla for the owner is `IMS-V2/GO-LIVE.md`; this file is the same server work, written for an agent.

---

## 0. What this is

The ERP (Laravel 12 + Filament 5, repo `bhd4232/Business-Dashboard`) runs on the owner's VPS as **one Coolify application**, built with Nixpacks (`nixpacks.toml`) behind Coolify's Traefik, with Cloudflare in front. Coolify deploys the `main` branch on every push.

**IMS v2** (the investor system) is now part of that same application. It does **not** need a second app, container or database. It only needs a **separate root domain** (call it `IMS_DOMAIN`, e.g. `example.com`) pointed at the same ERP app. When a Super Admin then switches IMS on inside the ERP:

- `https://IMS_DOMAIN/` → redirects to `/investor` (the investor portal),
- `https://IMS_DOMAIN/office` → the IMS office for staff,
- `www.IMS_DOMAIN` works the same (the app strips `www.`),
- the company's old investor-portal address redirects to `IMS_DOMAIN`.

The app finds IMS by the request's host name (`companies.ims_domain`, `App\Models\Company::forImsHost()`), so the only server work is: **get the domain's traffic to the ERP app over HTTPS, with the right host name, and check the things below.**

## 1. Rules — never break these

1. **Do not change code, push to git, or merge anything into `main`.** The owner merges `feature/ims-v2` into `main` themselves; that push is what deploys IMS v2.
2. **Back up the production database before the IMS v2 deploy** (section 3) and tell the owner where the backup is.
3. **Never run** `migrate:fresh`, `migrate:refresh`, `migrate:reset`, `db:wipe`, `db:seed`, `demo:refresh` or any seeder on the server. Migrations run by themselves after each deploy (Coolify post-deployment command `php artisan deploy:migrate`); do not run them by hand unless the owner asks.
4. **Do not remove or rename any existing domain** on the ERP app or any other app (ERP admin domain, storefront domains such as `offer.zamzamint.com`, `zamzamint.com`, `new.zamzamint.com`, reseller domains). Only **add** the IMS domain.
5. **Do not edit the app's environment variables** except where section 6 says to, and only after telling the owner. Never print secrets (`APP_KEY`, DB password, API keys) into chat or logs.
6. **Do not switch IMS on in the ERP.** That is a Super Admin action in the admin panel (section 7). You only prepare the server.
7. If something is unclear or a step fails, **stop and report** — do not guess and do not "fix" by deleting things.

## 2. Ask the owner for these first

| What | Why |
|---|---|
| `IMS_DOMAIN` — the root domain bought for IMS (not a subdomain) | everything below |
| Is `IMS_DOMAIN` the domain the **old IMS app** (`zamzaminv.app`) uses today? | if yes, section 5b |
| Access to Cloudflare for `IMS_DOMAIN` (or the owner does the DNS part) | section 4 |
| The ERP's Coolify application name / URL | sections 3, 5, 6 |
| The company's current investor-portal address (e.g. `investor.zamzamint.com`) | section 8 check |

## 3. Before the owner merges to `main`

1. **Database backup.** Use Coolify's backup for the ERP database (Databases → the ERP DB → Backups → *Backup now*), or `mysqldump --single-transaction` from the DB container to a file outside the app container. Confirm the file is not empty. IMS v2 adds 5 migrations (new tables, and new columns on `companies`, investments, shipments); they only add, never drop.
2. **Free disk space**: at least ~2 GB free (`df -h`) — the next build installs Tesseract OCR packages.
3. Tell the owner: "backup done at …, ready for merge". Wait for the owner to merge.

## 4. DNS on Cloudflare (can be done before or after the merge)

1. Find the VPS public IP (the same IP the ERP domain's A record points to).
2. In the Cloudflare zone of `IMS_DOMAIN` add:
   - `A  @    <VPS IP>`
   - `A  www  <VPS IP>`
3. **Start with the proxy OFF (grey cloud, "DNS only")** so Coolify/Let's Encrypt can issue the certificate without interference.
4. SSL/TLS mode for the zone: **Full (strict)**.
5. Check: `dig +short IMS_DOMAIN` and `dig +short www.IMS_DOMAIN` return the VPS IP.

## 5. Coolify — add the domain to the ERP app

### 5a. Normal case
1. Open the ERP application → **Configuration → General → Domains**.
2. The field is a comma-separated list. **Keep everything already there** and append:
   `,https://IMS_DOMAIN,https://www.IMS_DOMAIN`
3. Save, then **Redeploy** (Traefik picks up the new routes and requests the certificates).
4. When the deploy is healthy: `curl -sI https://IMS_DOMAIN` must answer over HTTPS with a valid certificate (any status is fine at this point — IMS is still off, so a 404 or the ERP's own page is expected).
5. Now turn the Cloudflare proxy **ON** (orange cloud) for both records, keep SSL mode **Full (strict)**, and run the same `curl` again — still a valid answer, no redirect loop, no 52x error.

### 5b. If `IMS_DOMAIN` is the old IMS app's domain (`zamzaminv.app`)
1. In Coolify, open the **old IMS application** and **remove** `IMS_DOMAIN` / `www.IMS_DOMAIN` from its Domains. Save and redeploy it (or stop it, only if the owner says the old IMS is no longer needed — IMS v2 does not read from it any more).
2. Then do 5a on the ERP app. Two apps must never hold the same domain.
3. Do **not** delete the old app or its database volume; only the owner decides that.

## 6. Checks on the ERP app after the IMS v2 deploy

Run these in the ERP container (Coolify → the app → Terminal) and report each result:

1. **Deploy log**: the post-deployment `php artisan deploy:migrate` finished without errors. `php artisan migrate:status | grep 2026_10_19_1` shows the five IMS v2 migrations as *Ran*.
2. **OCR (optional but wanted)**: `tesseract --list-langs` lists `ben` and `eng`. If `tesseract` is missing, the build did not use the new `nixpacks.toml` — redeploy once **with build cache cleared**. If it still fails, report it; IMS works without it (staff check NID pictures by eye).
3. **Scheduler**: `php artisan schedule:list` shows `ims:profit-proposals`, `ims:maturity-reminders`, `ims:owner-digest`, `ims:monthly-statements`, `ims:shipment-delays`. Confirm something runs `php artisan schedule:run` every minute (Coolify Scheduled Task, cron, or a scheduler process) — the ERP already needed this; just confirm it is alive (`php artisan schedule:list` "Next due" times move, or the scheduled task's run history in Coolify).
4. **Queue**: report `QUEUE_CONNECTION`. If it is not `sync`, a `php artisan queue:work` process must be running (IMS sends SMS/e-mail through queued jobs, like the rest of the ERP; `docs/deployment.md` → queue section).
5. **Environment** — read only, report the values (not secrets):
   - `TRUSTED_PROXIES` must be `*` (or include Traefik + Cloudflare). Without it the app thinks requests are `http://` and IMS links/redirects break.
   - `SESSION_DOMAIN` must be **empty / `null`**. If it is set to the ERP's domain, logins on `IMS_DOMAIN` fail (the cookie is refused). If it is set, tell the owner before changing it to `null`, then redeploy.
   - `APP_URL` stays the ERP's own address — do **not** change it to `IMS_DOMAIN`.
   - `IMS_TESSERACT_PATH` is not needed unless `which tesseract` is not `tesseract` on the PATH.

## 7. Owner / Super Admin switches IMS on (not the agent)

In the ERP admin: **Investments → Investor Portal Settings → IMS v2 on its own domain** → domain `IMS_DOMAIN` (without `www`) → **IMS v2 switched on** → Save. (Then the rest of `IMS-V2/GO-LIVE.md` steps 4–6.)

## 8. After the switch — verify from outside

```bash
curl -sI https://IMS_DOMAIN/                 # 302 → /investor
curl -sI https://IMS_DOMAIN/investor/login   # 200
curl -sI https://IMS_DOMAIN/office/login     # 200
curl -sI https://www.IMS_DOMAIN/             # 302 → /investor (same as above)
curl -sI https://IMS_DOMAIN/admin            # 302 → /investor (the ERP admin is not served here)
curl -sI https://OLD_PORTAL_HOST/investor    # 302 → https://IMS_DOMAIN/investor
```

Also open `https://IMS_DOMAIN/investor/login` in a browser and confirm the padlock, the green IMS sign-in page, and that the ERP admin still opens on its own domain. Check `storage/logs/laravel.log` for new errors after these requests.

## 9. Undo

- IMS problem: the Super Admin switches **IMS v2 switched on** off — everything goes back to how it was, no data is deleted.
- Domain problem: remove `IMS_DOMAIN` entries from the ERP app's Domains and redeploy; the rest of the ERP is not affected.
- Bad deploy: the owner reverts on `main` (Coolify redeploys). Restore the DB backup only if the owner asks.

## 10. Report back to the owner

Send one short message in simple Bangla with:
- backup: done / where,
- DNS: records added, proxy on/off, SSL mode,
- Coolify: domains added (list), certificate OK,
- checks 6.1–6.5 each: OK / problem,
- section 8 results (status codes),
- anything you did not do and why.
