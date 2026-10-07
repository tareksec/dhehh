# Storefront API (for the headless Next.js website)

> **বাংলা সারসংক্ষেপ:** zamzamint.com-এর নতুন Next.js ওয়েবসাইটের কোনো আলাদা ব্যাকএন্ড নেই — ERP-ই তার ব্যাকএন্ড। এই API দিয়ে ওয়েবসাইট ERP থেকে **পড়ে** (পণ্য, ক্যাটাগরি, ব্যানার, মেনু, পেজ, ফুটার, থিমের রং/ফন্ট, ফ্ল্যাশ সেল ইত্যাদি) এবং **অর্ডার দেয়** (কোট, চেকআউট, অনলাইন পেমেন্ট ফেরত, অর্ডার দেখা)। গ্রাহক **অ্যাকাউন্ট খুলতে, সাইন ইন করতে** (পাসওয়ার্ড বা এসএমএস/ইমেইল কোড), পাসওয়ার্ড রিসেট করতে, **অর্ডারের অবস্থা ট্র্যাক করতে**, আগের অর্ডার আবার করতে এবং **রিভিউ দিতে** পারেন (§6) — সবই Blade স্টোরফ্রন্টের একই নিয়মে। দাম, ডেলিভারি চার্জ, VAT, নতুন কাস্টমারের ডেলিভারি অগ্রিম, প্রি-অর্ডার অগ্রিম, কুরিয়ার-ঝুঁকির অগ্রিম, চেকআউট পলিসি ও ZiniPay/PayStation পেমেন্ট — সবকিছু ERP সার্ভারে হিসাব হয় (Blade চেকআউটের একই সার্ভিস), ব্রাউজার থেকে কোনো দাম নেওয়া হয় না। সব কনটেন্ট ও কনফিগারেশন ERP অ্যাডমিন থেকেই বদলায়। Next.js সাইটের নিজস্ব সেটিংস রো আছে (ব্লেড স্টোরফ্রন্ট রিসেলার স্টোর, Next.js মূল স্টোরফ্রন্ট) — **Site → Storefront Settings**-এ *Site: Next.js* রো খুলে *Next.js Site* সেকশনে *Enable Next.js site API* অন করুন; API key তৈরি হয় **Settings → Integrations → Website API** থেকে। এই API key শুধু Next.js **সার্ভারের** এনভায়রনমেন্টে থাকবে, ব্রাউজারে কখনো নয়।

This is the API the company's Next.js site (e.g. `zamzamint.com`) uses: read-only catalog endpoints (§3), the checkout endpoints (§5) and the customer account, order tracking and review endpoints (§6). It sits next to
the server-to-server API in [`website-integration.md`](website-integration.md) and uses the
**same per-company Bearer key**, so a key only ever sees its own company.

Base URL: `https://<your-erp-domain>/api/v1/storefront`

## 1. Turning it on

1. **Site → Storefront Settings** → open (or create, with *Site: Next.js site*) the company's **Next.js**
   row — it is separate from the Blade (reseller) storefront row, so its look, content, checkout rules
   and keys are customised on their own; a new one can start as a copy of the Blade settings. In its
   **Next.js Site** section switch on **Enable Next.js site API** and enter
   the website's domain (it must not be another company's domain or a reseller's store domain).
   While the switch is off every endpoint answers `403 nextjs_site_disabled` — it is the owner's
   kill switch. A domain is not required to start developing against the API.
2. **Settings → Integrations → Website API** → *Generate API key*, and set the webhook URL
   (`https://<website>/api/erp/webhook`) and signing secret.
3. In Coolify put the key in the Next.js app's **server** environment (`ERP_API_KEY`).

> The key can also read and write prices/stock through the other `/api/v1` endpoints. **Never**
> expose it to a browser, never prefix it `NEXT_PUBLIC_`. The website calls this API from its
> server only (server components / route handlers). There is no CORS configuration on purpose.

Send on every request:

```
Authorization: Bearer <key>
Accept: application/json
```

## 2. Behaviour shared by every endpoint

| Topic | Rule |
| --- | --- |
| Success | `200` (`201` for a placed order), body `{ "data": ..., "meta": ... }` (`meta` only on lists) |
| JSON only | Every answer is JSON even without an `Accept` header (a validation error is `422 { message, errors }`, never a redirect) |
| Errors | `{ "ok": false, "error": "<code>", "hint": "..." }` — `401 missing_api_key` / `invalid_api_key`, `403 nextjs_site_disabled`, `404 product_not_found` / `category_not_found` / `page_not_found`, `422 invalid_collection` / `invalid_kind`, `429` rate limited. Checkout adds the codes in §5 |
| Rate limit | 600 requests/minute **per API key** (plus a 3000/minute per-IP backstop). All visitors share one Next.js server, so cache responses (Next's data cache) instead of calling per visitor |
| Visibility | Exactly the Blade storefront's rules: a product is shown only while `is_active` **and** status *Available*; categories only while active; pages only while published |
| Images | Always absolute URLs (the website is on another domain). A storage path that belongs to another company is never turned into a URL |
| Text | Returned exactly as the admin entered it. There is no translation of admin content yet (planned in `docs/i18n-bangla-english-plan.md`, Phase 4) |
| Money | Plain numbers in the company currency (`BDT`). `price` is the regular price, `sale_price` the discounted price |

## 3. Endpoints

### `GET /site`
Everything site-wide, from the company's **Next.js** storefront settings row (never the Blade row):
`company`, `sites` (`blade_domain`, `nextjs_domain`), `branding` (logo URLs), `theme`
(`mode`, `colors` — the resolved palette incl. contrast colours and dark-mode colours — `fonts`
with the Google Fonts URL, `typography`, `appearance`), `contact`, `social_links`, `hero`,
`announcement`, `utility_bar`, `campaign`, `trust_items`, `offer_countdown` (`null` once it ended),
`business` (business-account text, bulk-pricing rows — wholesale), `features`, `menus.header` /
`menus.footer` (category and page ids already resolved to slugs; an inactive category or an
unpublished page is dropped), `footer_blocks` (the Footer Builder, legal links resolved to page
slugs) and `seo`.

### `GET /categories`
Active categories: `id, name, slug, description, image_url, icon, products_count` (visible products).

### `GET /products`
Paginated product **cards**. Query:

| Param | Meaning |
| --- | --- |
| `category` | category slug (`404 category_not_found` if unknown) |
| `q` | search in name, SKU and brand (max 100 chars) |
| `sort` | `latest` (default), `price_asc`, `price_desc` |
| `collection` | `flash-sale`, `trending`, `new-arrivals` — only products in a **live** collection of that kind |
| `slugs` | comma list (max 50); returns those products in that order, unpaginated — use it to refresh cart/wishlist lines |
| `page`, `per_page` | `per_page` default 24, max 48 |

Card fields: `id, sku, slug, name, brand, unit, category{id,name,slug}, image_url, price,
sale_price, selling_price, has_discount, discount_percent, deal, stock, reorder_level,
stock_status, is_preorder, orderable, moq, tier_prices, has_variants, rating{average,count},
created_at`.

* `stock_status` is `in_stock`, `low_stock` (the ERP's own rule: stock ≤ reorder level),
  `out_of_stock` or `preorder` (zero stock but pre-order allowed).
* **Wholesale:** `moq` (minimum order quantity) and `tier_prices` (`[{min_qty, price}]`,
  ascending; malformed rows dropped). Tier prices apply to the base product only, never to variations.
* `rating` counts **approved** reviews only.
* `deal` is `null` or `{price, kind, collection_id, ends_at}` — see §4.

### `GET /products/{slug}`
A card plus: `description_html` (sanitised by the same renderer the Blade site uses, safe to
inject), `description_text`, `weight_kg`, `gallery_urls`, `video_url`, `variants`
(`id, sku, options, label, price, stock, image_urls` — active variations only),
`min_order_quantity`, `max_order_quantity` (the bounds the Blade product page and cart enforce),
`preorder` (`{advance_percent}` or `null`), `reviews` (latest 10 approved; no customer or order ids),
`related` (up to 4 cards, same category) and `seo`.

### `GET /slides`
The Hero Slides for the company's active theme/template (schedule respected):
`id, image_url, image_mobile_url, fit_to_frame, heading, subheading, cta_label, cta_url, product{slug,name}`.
A link to a product that is no longer visible comes back as `product: null`.
`cta_url` is stored by the admin and may be a Blade path (`/products`, `/category/x`, `/product/x`,
`/pages/x`) — the website mirrors those routes.
A slide's **Show on** (admin: *Site → Hero Slides*) limits it to one site: this endpoint returns the
slides for **all sites** plus those limited to the company's **Next.js** site, never the Blade-only ones.

### `GET /collections`
Live **Collections** (admin: *Site → Offers → type Collection*), ordered Flash Sale, Trending,
New Arrivals, then newest first. Each has `id, kind, kind_slug, title, slug, short_description,
cover_image_url, starts_at, ends_at` and `products` (cards, in the admin's order; invisible
products dropped). A collection with no visible product is omitted. `?kind=flash-sale|trending|new-arrivals`
returns one kind.

### `GET /pages` and `GET /pages/{slug}`
Published content pages: `slug, title, excerpt, cover_image_url`; the detail adds
`content_html` (sanitised), `meta_title`, `meta_description`, `updated_at`.
A page's **Show on** (admin: *Site → Pages*) works the same way: pages for all sites and those limited to
the Next.js site are served (a page limited to the Blade storefront answers `404 page_not_found`), and menu
or footer links to a page of the other site are left out.

## 4. Collections, deals and the ERP price

Flash Sale / Trending / New Arrivals are **managed by the admin** as an Offer of type *Collection*:
a title, products in order, an optional start and end time (a Flash Sale requires an end time —
it drives the countdown) and an optional **deal price** per product. A collection is *live* while
it is Published and now is inside its window. Collections never appear as landing pages and cannot
be bought through the offer checkout (`/offers`).

The ERP price itself is never rewritten: `selling_price` stays `sale_price ?? price`. While a
collection is live, `deal.price` is reported separately. If a product is in several live
collections the Flash Sale deal wins.

**The price the server charges (owner decision 2026-10-04).** Checkout re-prices every line on the
server — a price from the browser is never read. Per line, in this order:

1. a **variation** keeps its own price (a product-level deal and wholesale tiers do not apply to variations);
2. otherwise a **live Collection deal price** wins over the wholesale tiers;
3. otherwise the deepest **wholesale tier** reached by the quantity (`tier_prices`);
4. otherwise the normal **selling price** (`sale_price ?? price`).

A deal that is not lower than the normal selling price is ignored (`deal` is `null` on the card and
checkout never charges it). The website shows the same rule (`src/lib/pricing.ts`).

## 5. Checkout (quote → order → hosted payment)

The website never sends a price, a fee or a total to charge — only **what** the customer wants and
**who** they are. The ERP decides everything through the same `StorefrontCheckoutService` the Blade
checkout uses, so a rule changed in **Site → Storefront Settings** (delivery charges and areas, VAT,
checkout policy, advances, payment methods) applies to both sites at once.

A cart line is `{ "slug": "crystal-gift-box", "quantity": 12, "variant_id": 7 }` (`variant_id` only
for products with variations; at most 100 lines).

### `POST /checkout/quote`
Body: `items` and an optional `address`. Answers what the cart costs **right now**:

| Field | Meaning |
| --- | --- |
| `lines` | Priced lines: `slug, name, variant_id, variant_label, image_url, unit, quantity, unit_price, subtotal, moq, is_preorder` |
| `issues` | What the ERP changed or dropped: `{ slug, variant_id, code, requested, quantity? }` with `code` = `unavailable` (not sold / no such variation), `variant_required`, `out_of_stock`, `quantity_adjusted` (raised to the minimum order quantity or capped at stock — `quantity` is the accepted amount). **The quote already shows the adjusted quantity; an order is refused until the customer has seen it.** |
| `subtotal`, `vat` | `vat` = `{ active, label, inclusive, amount, included }` — exclusive VAT is added to the total, inclusive VAT is only informational |
| `delivery` | `inside` and `outside` rows (`fee, weight, billed_weight, time`) and `area` — the area the ERP detects from `address` (`null` without one). The client can never choose the area |
| `totals`, `total` | Order total per area; `total` is for the detected area, `null` until an address is given |
| `advance` | `preorder_due` (online advance for pre-order lines), `new_customer_delivery_enabled`, `risk_payment_enabled` |
| `online_payment_available` | A ZiniPay/PayStation gateway is configured. Pre-order lines cannot be ordered without it |
| `payment_methods` | The admin-managed options: `{ value, type: cod\|manual\|online_gateway, name, account_number, instructions }`. Send `value` back as `payment_method` |
| `messages` | The owner's own customer-facing text for the new-customer advance and the verified-advance rule (show it as written) |

### `POST /checkout`
Body:

| Field | Notes |
| --- | --- |
| `items` | as above |
| `name`, `phone`, `address` | required; `email`, `note` optional. A Bangladesh phone is normalised (`+8801…` → `01…`) |
| `payment_method` | one of the quote's `payment_methods[].value` (defaults to the first) |
| `sender_number`, `trx_id` | required only for a `manual:<id>` (send-money) method |
| `expected_total` | optional; the `total` the customer saw. If the server total differs by more than ৳0.01 → `409 price_changed` |
| `client_request_id` | optional idempotency key (≤100 chars). Same key → the same order/payment, never a second one (kept 30 minutes) |
| `return_url`, `cancel_url` | required, https and **on the company's own Next.js domain** (Storefront Settings → the Next.js row → Next.js Site). `http://localhost` / `127.0.0.1` are accepted only when the ERP's `APP_ENV` is `local`/`testing` |
| `client_ip`, `client_user_agent` | the **visitor's** IP and browser, forwarded by the Next.js server. The checkout policy (blocked IPs, orders per IP) uses `client_ip`; when it is missing the IP rules are skipped — the Next.js server's own IP is never used instead |
| `meta` | optional `fbp`, `fbc`, `event_source_url`, `consent_granted` for Meta conversion events (the current website sends none) |

Answers:

* `201 { data: { status: "placed", order } }` — a cash-on-delivery or manual-payment order, or an
  order that needed no advance. `order` is the summary below.
* `200 { data: { status: "payment_required", payment_url, payment: { id, amount, reasons, message } } }`
  — an advance (or the full amount, when the customer chose online payment) must be paid first.
  **No order exists yet**; it is created only after the gateway verifies the payment. Redirect the
  browser to `payment_url`. `reasons` are `preorder`, `new_customer_delivery`, `courier_low_success_ratio`,
  `courier_no_history`, `full_online_payment`; `message` is the owner's text for that rule. The gateway sends the
  customer back to `return_url` with `payment=<id>&ref=<signature>` appended (and `cancel_url` on cancel).
* `409` — `cart_unavailable` (nothing left to order), `cart_changed` (something was adjusted/dropped),
  `price_changed` (`expected_total` no longer matches), `request_in_progress` (the same
  `client_request_id` is being processed). The first three carry a fresh `quote`.
* `422` — validation (`{ message, errors }`), a checkout-policy refusal (`errors.checkout`), an
  unavailable or unknown payment method, or a return/cancel URL that is not on the company's site.

### `POST /checkout/payments/{payment}/finalize`
Body: `ref` (from the return URL) and optionally `trx_id` (PayStation sends it on return). The ERP
verifies the payment **with the gateway**, then places the order. `200 { data: { status: "placed", order } }`;
calling it again returns the same order. `404 payment_not_found` for an unknown id **or** a wrong
`ref` (same answer on purpose); `422 payment_not_completed` when the gateway does not confirm it —
no order is placed. The gateways' own webhooks finalize the payment too, so a customer who closes the
tab after paying still gets the order.

### `GET /orders/{order_number}?token=…`
The order summary for the success page. `token` is the unguessable value returned when the order was
placed; order numbers alone read nothing, and another company's key gets `404 order_not_found`.

`order` fields: `order_number, token, status, created_at, customer{name,phone,email,address},
delivery_area, items[{name,variant_label,quantity,unit_price,subtotal,image_url}], shipping_fee, vat,
total_amount, paid_amount, due_amount, payment{kind: manual|online, amount, status, paid_in_full}|null,
whatsapp_group{url,message}`.

### What the website must do
* Call these endpoints **from its server** (route handlers), never from the browser, and add
  `client_ip` (the visitor's IP — behind Coolify's proxy use the Nth address from the right of
  `X-Forwarded-For`, see the website README) and `client_user_agent`.
* Fix `return_url` / `cancel_url` itself (`<SITE_URL>/checkout/payment/return`, `<SITE_URL>/checkout`);
  never take them from a browser request.
* Use a new `client_request_id` for every edited form, and the same one for a retry of the same form.
* Rate limit its own public checkout routes per visitor IP: the ERP limit is per API key, shared by all
  visitors.

## 6. Customer accounts, order tracking and reviews

Same rules as the Blade storefront's account pages: the service behind both is `CustomerAccountService`
(registration reuses the CRM record of the same phone, so earlier orders appear; a wrong password is
counted per account; a login code is valid for 10 minutes and five tries; a reset code for 15 minutes),
`StorefrontOrderTrackingService` (the status history) and the review rules below. All of it is switched by
**Customer accounts** in Storefront Settings (answer `403 customer_accounts_disabled` while off) — except
order tracking, which works for everyone like the Blade `/track` page.

**How a customer is "logged in".** A successful sign-in answers with an opaque `token` (64 characters) and
`expires_at`. The website keeps it in an **httpOnly cookie on its own server** and sends it back as the
header `X-Customer-Token` next to the company API key. The ERP stores only a SHA-256 hash
(`storefront_customer_tokens`), the token only works for the company whose API key is sent, expires after
48 hours (30 days with `remember: true`) and stops working when an admin deactivates the customer, the
password is reset, or — for every other device — the password is changed. No token (or an expired one) →
`401 customer_unauthenticated`.

Failures answer `{ok:false, error:"<code>"}` so the website can word them in Bangla or English itself.
Field mistakes are the usual `422` with `errors`. Always send the visitor's `client_ip` (and
`client_user_agent`) on the public sign-in calls: the sign-in limiter (`storefront-auth`, 20 a minute) is
per visitor, not per website server.

### Sign in (public)
| Call | Body | Success | Errors |
|---|---|---|---|
| `POST /account/register` | `name, phone, email?, address?, password (min 8), password_confirmation, remember?, code?` | `201 {data:{customer, token, expires_at}}`, or `202 {verification_required:true, masked_identifier, expires_in_minutes}` when the phone must be proven first (see below) | `409 account_exists`, `409 email_taken`, `422 otp_invalid`, `422 verification_unavailable`, `422` |
| `POST /account/login` | `identifier` (phone or email), `password, remember?` | `200` same shape | `422 invalid_credentials`, `429 too_many_attempts` (+`retry_after`), `403 account_disabled` |
| `POST /account/otp` | `identifier` | `202 {ok, masked_identifier}` — the same answer whether or not an account exists | `422 otp_unavailable` (phone code but no SMS gateway) |
| `POST /account/otp/verify` | `identifier, code (6 digits), remember?` | `200` token | `422 otp_invalid` |
| `POST /account/password/forgot` | `phone` | `202 {ok}` (generic) | `422 sms_unavailable` |
| `POST /account/password/reset` | `phone, code, password, password_confirmation` | `200` token — every older login is signed out | `422 reset_code_invalid`, `429 too_many_attempts` |

**Phone verification on sign-up (2026-10-06).** A new account takes over the customer record (and order
history) of the same phone, so the phone is proven first. When the store has an SMS gateway, the first
`POST /account/register` (no `code`) texts a 6-digit code and answers `202 verification_required`; the
website asks for the code and sends **the same form again with `code`** → `201` and the token. A wrong or
expired code is `422 otp_invalid`; five wrong codes or 10 minutes throw the code away. Sending the form again
without `code` within a minute keeps the code already sent (a "resend" after that sends a new one). With no
SMS gateway, a brand-new number still signs up in one step, but a number that already has a customer
record answers `422 verification_unavailable` (also when the SMS could not be sent).

Phones are accepted as typed (`+880 1712-345678`, `8801712345678`, `01712345678`) and stored in the local
`01XXXXXXXXX` form checkout already uses. Five wrong reset codes lock that account's reset until the
15-minute window ends.

### The account (needs `X-Customer-Token`)
* `POST /account/logout` — ends only this login.
* `GET /account` — `customer`, `stats{order_count, active_order_count, completed_order_count, total_spent}`,
  `recent_orders` (3), `recent_activity` (5).
* `PATCH /account/profile` (`name, email?, address?`; `409 email_taken`) and `PUT /account/password`
  (`current_password, password, password_confirmation`; `422 current_password_incorrect`).
* `GET /account/orders?page=` — `{data:[{order_number, order_date, status, status_label, delivery_status,
  total_amount, due_amount, item_count, items[3], can_review}], meta}`; `GET /account/orders/{order_number}` —
  the order summary of §5 plus `tracking`, and per item `slug, review{status,rating,comment}|null, can_review`.
  Another customer's (or company's) order is `404 order_not_found`.
* `POST /account/orders/{order_number}/reorder` — `{data:{lines:[{slug, quantity, variant_id}], skipped}}`.
  The ERP does not touch a cart (the website's cart lives in the browser): it names the lines that can be
  ordered again; the website adds them and the normal quote re-prices and re-checks them.
* `POST /account/orders/{order_number}/reviews` — `product_slug, rating (1–5), comment?`. Only for a product on
  the customer's own **completed** order, once per product and order (`409 order_not_completed`,
  `409 already_reviewed`, `404 product_not_in_order`). A review starts **pending**; it shows up in
  `GET /products/{slug}` (`reviews`) only after the owner approves it in the ERP.
* `GET /account/activity?page=`.

### Order tracking (public)
* `POST /orders/track` — `order_number` + `phone` (the phone the order was placed with). A wrong phone is
  indistinguishable from a missing order (`404 order_not_found`), and eight wrong guesses lock that order
  number for 15 minutes (`429 too_many_attempts`). The answer is the order summary **without the customer's
  name, e-mail and address**.
* `GET /orders/{order_number}/tracking?token=…` — the same with the unguessable order `token` from checkout (the
  success link), no phone needed, customer details included.

Both add `status_label, order_date` and `tracking{delivery_status, delivery_status_label,
courier{tracking_id, status, status_label}|null, updates[{time, type: order|delivery|courier, status, label,
note}]}` (newest first). `status`/`delivery_status` are stable keys; the `label`s are English for any key the
website has no translation for.

Passing `X-Customer-Token` on `POST /checkout` (or the payment finalize call) also records "Order placed" in the
customer's account activity; a missing or expired token never affects the order.

## 7. Keeping the website fresh

The website caches ERP responses and refreshes them when the ERP sends a signed
`product.updated` webhook (price/sale price/stock changes — see
[`website-integration.md`](website-integration.md) §3). Other changes (banners, menus, categories,
descriptions, collections) show up when the website's safety-net cache expires (default 2 minutes).

## 8. Not in this API yet

Reseller pricing, offer landing pages, Meta pixel/conversion events and a cookie-consent banner for the
Next.js site, and review photos. These follow in later phases. The OpenAPI file
[`website-integration.openapi.yaml`](website-integration.openapi.yaml) does not describe the storefront
endpoints yet.
