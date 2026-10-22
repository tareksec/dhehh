# Login protection (LoginGate)

A password hash (bcrypt) is slow on purpose, so it is also the most expensive thing an attacker can make the
server do. Picture 50,000 guesses a minute from 30,000 addresses: each address makes fewer than two tries
(a per-IP limit never fires), each account gets about four (a lockout never fires), and every guess still
costs a full hash — the CPU burns and real users wait. `App\Services\LoginGate` therefore judges a sign-in
**before** the hash is computed.

## Where it runs

| Sign-in | Code |
|---|---|
| Storefront customers (Blade), reseller portal, Next.js API | `CustomerAccountService::attemptLogin()` |
| Investor portal (password) | `InvestorPortalAccountService::attemptPasswordLogin()` |
| Admin (staff) panel | `App\Filament\Auth\AdminLogin` (Filament's login with the gate in front) |

One-time-code sign-ins, password resets and registration already have their own attempt limits and are not
part of the gate.

## What it decides

Every request is answered `ALLOW`, `THROTTLE`, `CHALLENGE` or `DENY`, checked in this order:

1. **Device waits (`THROTTLE`)** — this device (IP + browser) already got `LOGIN_DEVICE_FAILURES_PER_MINUTE`
   (5) passwords wrong for this account this minute. Applies to trusted devices too.
2. **Trusted device → allowed.** A device the account has signed in from (see *Device trust*) skips every
   check below: somebody else's guessing never locks the owner out.
3. **Bot (`DENY`)** — the client's fingerprint (browser + language + network block, /24 for IPv4, /48 for
   IPv6) has `LOGIN_FINGERPRINT_FAILURES` (30) wrong passwords inside 10 minutes and **not one** successful
   sign-in inside that window. It is told "login failed" and no hash is computed. A network with even one success (an office
   behind one address) is never denied.
4. **Unknown device while under attack (`CHALLENGE`)** — the account got `LOGIN_ACCOUNT_FAILURES` (20) wrong
   passwords from anywhere inside `LOGIN_ACCOUNT_WINDOW_MINUTES` (15), or the whole surface got
   `LOGIN_GLOBAL_FAILURES_PER_MINUTE` (300) in a minute. With the CAPTCHA set up (see below) the visitor solves
   it and goes on; without one this is a wait with a `Retry-After`, and the API marks it with `challenge: true`.
5. **Hash budget (`THROTTLE`)** — more than `LOGIN_BCRYPT_PER_MINUTE` (600) password hashes for untrusted
   devices on this surface in a minute. Trusted devices are never counted.

Otherwise the normal password check runs. A wrong password counts against the device, the account, the
fingerprint and the surface; a success clears the device and account counters and marks the fingerprint as
having succeeded.

Other details that matter:

- The phone is normalised first (`+880…`, `880…`, `01…` are one account) and the counters include the
  company, so the same number in two companies never shares a counter.
- An identifier with no account still costs one (dummy) hash and counts as a failure, so timing and
  lockouts cannot tell "no such account" from "wrong password".
- The gate fails open: if it breaks (the cache is down) the error goes to Settings → Deploy Error Logs
  (`module:login-gate`) and the sign-in carries on to the normal password check. Isolation is off in
  `local` / `testing`, so tests see the real error.
- `LOGIN_PROTECTION_ENABLED=false` switches the whole gate off.

## Device trust

After a password sign-in the browser gets an httpOnly cookie `lg_td_<surface>` (the Next.js API answers with
`data.device_token` for the website's own cookie instead). The token holds the account, the surface, the
company and a time, signed with the app key **and the account's current password hash**: changing the
password ends every trusted device, and a token never works for another account, surface or company. It
lasts `LOGIN_TRUSTED_DEVICE_DAYS` (90). Nothing is stored in the database.

## Production notes

- Every counter lives in the cache. Use `CACHE_STORE=redis` in production so all workers share them
  (`.env.example` already says so); with the `file` store they only work on one server.
- Tuning is by environment variable (see `config/login_protection.php`). Lower the numbers if an attack gets
  through, raise them if real users are being stopped.
- Real TLS fingerprints (JA3/JA4) can only be read by the proxy in front of PHP. The gate's fingerprint is
  the browser string plus the network block; a Cloudflare or WAF rule in front of Coolify is the place for
  the TLS one.

## The CAPTCHA step (Cloudflare Turnstile)

A normal sign-in never sees it. It appears only when the gate answers `CHALLENGE`: an unknown device while the
account or the whole site is under attack.

1. In Cloudflare: **dash.cloudflare.com → Turnstile → Add widget.** Add every domain people sign in on (the
   admin app, the shops, the reseller and investor portals), pick the **Managed** mode, and copy the two keys.
2. In the admin app (super admin): **Settings → Login Security.** Paste the site key and the secret key and
   switch the check on. The secret key is stored encrypted and never shown again; leaving it blank on a later
   save keeps it.
3. From then on a challenged sign-in shows the widget (storefront login form, admin / reseller / investor
   sign-in pages) or answers `429 captcha_required` with the public site key (Next.js API, see
   `docs/api/storefront-api.md` §6). The visitor solves it and signs in again; the token is checked with
   Cloudflare once, server-side.

What it does **not** do:

- It never lifts a **device wait** (`THROTTLE`: a device guessing at one account, or the hash budget) or a
  **bot denial** (`DENY`): a solved CAPTCHA only answers a `CHALLENGE`. A person wrongly denied can wait out
  the 10-minute window or, where the sign-in offers one, use a one-time code.
- It does not skip the password: a solved CAPTCHA only lets the normal password check run.
- Cloudflare being unreachable, a rejected token, an off or half-filled setting: the challenge stays a plain wait.
  The CAPTCHA can only ever *add* a way through for a person, never open the gate for a bot.

## Not done yet

- **The Next.js website** must keep `data.device_token` in an httpOnly cookie and send it back as
  `device_token`, and show the Turnstile widget on `429 captcha_required` (website repository; the ERP side
  works without both).
- Device trust is issued after password sign-ins only, not after a one-time-code sign-in.
- The CAPTCHA is only used for `CHALLENGE`; registration, one-time-code and password-reset forms keep their own
  attempt limits.
