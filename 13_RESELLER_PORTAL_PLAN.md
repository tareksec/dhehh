# 13 — Reseller Portal (separate reseller dashboard) + Reseller Store Links (Module Plan)

> **বাংলা সারসংক্ষেপ:** রিসেলারদের জন্য একটা আলাদা ঠিকানায় (যেমন `reseller.zamzamint.com`, অথবা কোম্পানি চাইলে সম্পূর্ণ নতুন একটা ডোমেন) একটা পূর্ণাঙ্গ ড্যাশবোর্ড তৈরি হবে। ওই ঠিকানায় শুধু রিসেলার ড্যাশবোর্ডই খুলবে, দোকানের পেজ বা অ্যাডমিন প্যানেল খুলবে না। ড্যাশবোর্ডে থাকবে: সারসংক্ষেপ ও গ্রাফ, অর্ডার, প্রোডাক্ট ও দাম, শেয়ার করার লিংক, আয়, উইথড্র, স্টোর সেটিংস, প্রোফাইল। নতুন রিসেলার আবেদনও ওখানেই করবেন। সাথে রিসেলারের স্টোরের লিংকের সমস্যাটা ঠিক হবে: কাস্টমার রিসেলারের স্টোরে ঢুকলে ওই স্টোরের ভেতরেই থাকবেন, মূল দোকানে চলে যাবেন না। কাজ ৬টা ধাপে ভাগ করা। প্রতিটা ধাপ আলাদাভাবে লাইভ করা যায়। শুরুর আগে §২-এর ৫টা প্রশ্নের উত্তর দরকার।

Status: **planned — waiting for owner answers in §2.1** (2026-09-28).

Owner request (2026-09-28): "রিসেলার ড্যাশবোর্ড আর স্টোরের লিংক ঠিক করা শুরু কর। রিসেলারদের জন্য আলাদা একটা সাবডোমিন ড্যাশবোর্ড তৈরি করা লাগবে। সাবডোমেনটা এরকম হবে reseller.domain.com অথবা নতুন একটা ডোমেন যেটা শুধু রিসেলারদের ড্যাশবোর্ড টাই শো করবে। একটা পূর্ণাঙ্গ ড্যাশবোর্ড, এর জন্য আগে একটা প্ল্যান তৈরি কর।"

---

## 1. Where we are today (verified from code, 2026-09-28)

| Area | Today |
|---|---|
| Reseller UI | One page inside the storefront customer account: `/account/reseller` ("Your Store", `ResellerStoreController` + `storefront/account/reseller.blade.php`). It has the balance cards, the withdraw form, the last 20 commissions and last 20 requests, the store slug, and product pick/price. |
| Auth | Resellers are `Customer` rows (`reseller_status = approved`) that log in with the storefront `customer` guard (phone or email + password). There is no separate reseller account. |
| Application | `/reseller` on the company storefront (`ResellerController`) creates a pending `Customer`. It creates no password or login, so an approved reseller must separately register a storefront account with the same phone. |
| Reseller store | `/store/{slug}/…` on the company domain (`ResolveResellerFromSlug`). Only products, category, product page, cart and checkout have reseller routes. |
| **Store link bug** | Every storefront view links to main-store routes (`storefront.products.show`, `storefront.cart.add`, … in 13 Blade files, ~34 call sites, plus controller redirects). A customer who clicks anything in a reseller's store leaves it and sees the company's full catalog. The 2026-09-28 commission release keeps the reseller's **price and commission** through the session (`ResellerPricing`), but the customer is no longer inside the reseller's curated store. |
| Domains | Each company has one `companies.domain`. All company domains point at the same app. `ResolveCompanyFromDomain` picks the company from the request host. The admin panel is Filament at `/admin` (`app.zamzamint.com/admin`). `SESSION_DOMAIN` is empty, so each host has its own login cookie. Hosting is Coolify/Traefik (TLS at the proxy). |
| Admin side | Resellers cluster: All Resellers, Commissions, Withdrawal requests; Storefront Settings → Reseller Settings. This stays as it is. |

## 2. Decisions

### 2.1 Questions for the owner (needed before Phase 1)

1. **ঠিকানা (domain):** প্রতিটা কোম্পানির জন্য ডিফল্ট ঠিকানা হবে `reseller.<কোম্পানির ডোমেন>` (যেমন `reseller.zamzamint.com`)। সাথে Company সেটিংসে একটা ঘর থাকবে, সেখানে চাইলে সম্পূর্ণ নতুন ডোমেন বসানো যাবে (যেমন `zamzamreseller.com`)। এটা ঠিক আছে? *(সুপারিশ: হ্যাঁ, দুটোই।)*
2. ~~**রিসেলার কি নিজের কাস্টমারের তথ্য দেখবেন?**~~ **উত্তর (2026-09-28): হ্যাঁ, পুরো তথ্য** — নাম, ফোন নম্বর (পুরোটা, মাস্ক করা ছাড়া) এবং ঠিকানা অর্ডার তালিকা ও বিস্তারিত পেজে দেখানো হবে।
3. ~~**রিসেলারের স্টোরে কার নাম দেখাবে?**~~ **উত্তর (2026-09-28): শুধু রিসেলারের নিজের নাম/ব্র্যান্ড** — কোম্পানির নাম/লোগো হেডারে থাকবে না; রিসেলারের ব্যবসার নাম (`business_name`, বা সেট থাকলে `reseller_store_name`) প্রধান হিসেবে দেখানো হবে।
4. **আবেদন আর অ্যাকাউন্ট:** নতুন রিসেলার portal-এ গিয়ে আবেদন করবেন এবং সেখানেই পাসওয়ার্ড দিয়ে অ্যাকাউন্ট খুলবেন। মূল দোকানের ফুটারের "Become a reseller" লিংক portal-এ নিয়ে যাবে। অনুমোদনের আগে লগইন করলে "আপনার আবেদন যাচাই হচ্ছে" দেখাবে। এটা ঠিক আছে? *(সুপারিশ: হ্যাঁ। একই ফোন নম্বর আর পাসওয়ার্ড দিয়ে তিনি দোকানে কাস্টমার হিসেবেও লগইন করতে পারবেন, কারণ অ্যাকাউন্ট একটাই।)*
5. **পুরনো পেজ:** দোকানের "My Account → Your Store" পেজটা সরিয়ে দিয়ে সেখানে "রিসেলার ড্যাশবোর্ডে যান" লিংক রাখা হবে। এটা ঠিক আছে? *(সুপারিশ: হ্যাঁ।)*

### 2.2 Technical decisions (my call; open to pushback)

| Decision | Choice | Why |
|---|---|---|
| Portal UI | A **second Filament panel** (`reseller`), with its own theme colour and the company logo | Filament already gives the admin its tables (search, filters, pagination), stat widgets, charts, forms and mobile layout. Building those again in Blade would take several times longer, and the admin team already knows Filament. |
| Portal auth | The same `Customer` record and `customer` guard (`->authGuard('customer')`). `Customer` implements `FilamentUser`; `canAccessPanel()` = approved reseller **and** belongs to the host's company **and** the company has `reseller_module_enabled` | One account per person, no password duplication; reuses the phone/email login and the SMS password reset that already exist |
| Host → company | New `companies.reseller_portal_domain` (nullable, unique, admin-managed). A request whose host equals that column, or equals `reseller.` + `companies.domain` when the column is empty, is a portal request for that company | Dynamic per-company hosts can't be hard-coded in `Route::domain()`; resolving from the DB matches how `ResolveCompanyFromDomain` already works |
| "Only the dashboard" on that host | New `RestrictResellerPortalHost` middleware at the top of the `web` group. On a portal host, anything that is not a portal, Livewire, Filament-asset or health route redirects to the portal home. On any other host, the portal path returns 404 | Meets "the domain shows only the reseller dashboard". The portal can't be reached from the main shop or the admin host. |
| Portal path | Panel path `/` on the portal host (`/`, `/orders`, `/products`, …). The middleware keeps it off every other host | Clean URLs such as `reseller.zamzamint.com/orders` |
| Reseller store URLs | Stay at `{company-domain}/store/{slug}` (store links resellers have already shared keep working) | Changing the public store URL would break links already posted on Facebook or WhatsApp |
| Store-link fix | One `StorefrontUrl` helper replaces the `isset($previewSlug) ? … : …` route pairs in storefront views. It knows three modes: preview, reseller store, main store | One place decides every storefront link, so this bug class can't come back view by view |
| i18n | Every string through `__()` with keys in `lang/en.json` and `lang/bn.json`; Filament labels through `__()` | CLAUDE.md rule |

## 3. The portal: pages

Left sidebar, Bangla/English, mobile friendly. The pages:

| # | Page | Content |
|---|---|---|
| 1 | **ড্যাশবোর্ড (Overview)** | Stat cards: available to withdraw, waiting for delivery, this month's orders, this month's sales, this month's commission. A 6-month commission chart. The last 5 orders. A store-link card with **Copy** / **WhatsApp** / **Facebook** buttons. A banner if the store has no slug yet. |
| 2 | **অর্ডার (Orders)** | Every order credited to this reseller: number, date, customer (as §2.1 Q2 decides), items, sale amount, commission, status (Pending / Confirmed / Shipping / Delivered / Cancelled / Returned). Filters for status and date; search by order number. A detail view with items, timeline (from `order_status_transitions`) and commission. Read-only: resellers never change orders. |
| 3 | **প্রোডাক্ট ও দাম (Products)** | The company catalog with **In my store** on/off, **Your cost**, **Suggested price**, **Your price** (edited inline), and commission per unit. Filters: in my store / not in store / category / no wholesale rate. Bulk actions: add to store, remove, "set price = suggested + X%". Per product: **Copy link** and **Share on WhatsApp** (links to the product inside the reseller store), plus **Download photos** (the product's own images, for posting). |
| 4 | **আয় (Earnings)** | Full commission history (paginated) with status and date filters, and monthly totals. |
| 5 | **উইথড্র (Withdrawals)** | Balance cards, the request form (minimum and available balance enforced, as today), full history with status and transaction ID. **Saved payout accounts** (bKash/Nagad/Rocket/bank numbers saved once and picked from a list). |
| 6 | **স্টোর সেটিংস (My Store)** | Store URL slug, and the shop name shown on the store (§2.1 Q3). |
| 7 | **প্রোফাইল (Profile)** | Name, phone (read-only), email, password change. |
| — | **আবেদন (Apply)** — outside the login | Name, phone, business name, note, password. Creates the pending reseller **with a login** (§2.1 Q4). After login, a pending or rejected applicant sees only a status page (with the rejection reason, if any). |

Not in this plan (can follow later): notifications (SMS/WhatsApp when approved, when commission becomes payable, when paid; needs your message texts), a reseller-wise sales report on the admin side, reseller tiers or levels.

## 4. The store-link fix

Goal: a customer who opens `{domain}/store/{slug}` stays inside that reseller's store for everything, and only sees the reseller's products.

1. **Complete the reseller route set.** Today only products, category, product page, cart and checkout exist. Add: store home (`/store/{slug}` → the reseller's products), search, checkout success/payment-return, order tracking, and the product quick-add form targets.
2. **`App\Support\StorefrontUrl`** — `StorefrontUrl::route('products.show', $slug)` returns the preview, reseller-store or main-store URL for the current request. The mode comes from the request attributes that `ResolveResellerFromSlug` and the preview controllers already set. Replace the ~34 route pairs in the 13 storefront Blade files (all themes) and the controller redirects (`CartController::redirectToCheckout()`, checkout success) with it.
3. **Header and footer inside a reseller store:** the logo, "Shop", search and category menu point into the reseller store, and only categories that contain the reseller's products are listed. Links to company pages (About, Contact, Track order) stay where they are.
4. `ResellerPricing`'s session fallback stays as a safety net (for a customer who reaches a main-store page some other way).
5. Tests: from each reseller-store page, every rendered storefront link stays under `/store/{slug}` (a crawler-style test over the rendered HTML), plus the existing attribution/pricing tests.

## 5. Data model

| Change | Detail |
|---|---|
| `companies.reseller_portal_domain` | nullable string, unique, normalised with `Company::normalizeDomain()`. Set in Company Management (super admin), next to `reseller_module_enabled`. |
| `customers.reseller_store_name` | nullable string: the shop name shown on the store (§2.1 Q3). Falls back to `business_name`. |
| New `reseller_payout_accounts` | `company_id`, `customer_id`, `method`, `account_details`, `label`, `is_default`. `BelongsToCompany`, and added to `MultiCompanyIsolationTest`. |
| Nothing else | Orders, commissions and payout requests from the 2026-09-28 commission release are reused as they are. |

All migrations are additive. None of them are run on the demo DB outside the test suite.

## 6. Security rules (every phase)

- A portal session can only ever see the logged-in reseller's own rows. Every portal resource query is scoped with `customer_id = auth('customer')->id()` **and** the host's company. Tests prove that another reseller's order, commission or payout id returns 404.
- `canAccessPanel()` re-checks approval on every request, so a reseller who is rejected or disabled is locked out immediately.
- A customer of company A can't log in to company B's portal (the host → company check).
- Portal login and apply routes get the same `throttle` limits the storefront login routes use today (`routes/web.php`). Password reset uses the existing SMS code flow.
- Nothing from the admin panel is reachable on the portal host, and the portal is not reachable on the admin or shop hosts.
- The customer data shown follows the owner's answer to §2.1 Q2.

## 7. Phases

Each phase is its own commit, deployable on its own, with tests, CHANGELOG, UPDATE_NOTES and both lang files.

| Phase | Content | Depends on |
|---|---|---|
| **1. Store links** | §4 in full. Fixes the live bug first; independent of the portal. | — |
| **2. Portal foundation** | `reseller_portal_domain`, host → company resolution, `RestrictResellerPortalHost`, the `reseller` Filament panel with the `customer` guard, login / logout / password reset, the Apply page with account creation, the pending/rejected status page, the "Become a reseller" link pointing to the portal. | §2.1 Q1, Q4 |
| **3. Overview + Orders** | Pages 1–2, including the chart and share buttons. | 2, §2.1 Q2 |
| **4. Products & pricing** | Page 3: inline price edit, bulk actions, share and photo download. | 2 |
| **5. Earnings, withdrawals, store settings, profile** | Pages 4–7 and saved payout accounts. | 2, §2.1 Q3 |
| **6. Switch-over** | Replace "My Account → Your Store" with a link to the portal (§2.1 Q5), update `PROJECT_GUIDE.md` and `docs/deployment.md` (DNS/Coolify steps below), and write owner-facing notes. | 3–5 |

## 8. What the owner has to do on the server (Phase 2 go-live)

For each company that uses the portal:

1. **DNS:** add an `A` (or `CNAME`) record `reseller` → the same server as the main domain. For a separate reseller domain, point that whole domain at the server.
2. **Coolify:** add `https://reseller.<domain>` (or the new domain) to the app's **Domains** list, so Traefik issues an SSL certificate for it. A wildcard `*.domain` certificate needs a DNS challenge, so adding each host explicitly is simpler.
3. **Admin:** Company Management → the company → **Reseller portal domain** (only needed for a separate domain; `reseller.<domain>` works without it).

No new environment variables. `SESSION_DOMAIN` stays empty, so the portal login is separate from the shop and admin logins, which is intended.

## 9. Risks

| Risk | Mitigation |
|---|---|
| A second Filament panel with a non-`User` auth model | Filament supports `authGuard()` per panel. `Customer` gets `FilamentUser` + `HasName`. Covered by a login test in Phase 2. |
| The host middleware blocking something needed (Livewire, assets, health checks) | An explicit allow-list plus a test that walks the portal pages on the portal host |
| Missing a storefront link in some theme | The crawler-style test in §4.5 runs over every theme in `StorefrontThemeRegistry` |
| Resellers with bookmarks to `/account/reseller` | Phase 6 redirects the old page to the portal instead of deleting it |
