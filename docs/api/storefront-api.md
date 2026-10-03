# Storefront API (for the headless Next.js website)

> **বাংলা সারসংক্ষেপ:** zamzamint.com-এর নতুন Next.js ওয়েবসাইটের কোনো আলাদা ব্যাকএন্ড নেই — ERP-ই তার ব্যাকএন্ড। এই API দিয়ে ওয়েবসাইট ERP থেকে শুধু **পড়ে** (পণ্য, ক্যাটাগরি, ব্যানার, মেনু, পেজ, ফুটার, থিমের রং/ফন্ট, ফ্ল্যাশ সেল ইত্যাদি)। সব কনটেন্ট ও কনফিগারেশন ERP অ্যাডমিন থেকেই বদলায়, ওয়েবসাইটে কিছু হার্ডকোড করা নেই। API চালু করতে **Site → Storefront Settings → Next.js Site** সেকশনে *Enable Next.js site API* অন করুন; API key তৈরি হয় **Settings → Integrations → Website API** থেকে। এই API key শুধু Next.js **সার্ভারের** এনভায়রনমেন্টে থাকবে, ব্রাউজারে কখনো নয়। চেকআউট/পেমেন্ট এখনো এই API-তে নেই (ধাপ ২)।

This is the read-only API the company's Next.js site (e.g. `zamzamint.com`) uses. It sits next to
the server-to-server API in [`website-integration.md`](website-integration.md) and uses the
**same per-company Bearer key**, so a key only ever sees its own company.

Base URL: `https://<your-erp-domain>/api/v1/storefront`

## 1. Turning it on

1. **Site → Storefront Settings → Next.js Site** → switch on **Enable Next.js site API** and enter
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
| Success | `200`, body `{ "data": ..., "meta": ... }` (`meta` only on lists) |
| Errors | `{ "ok": false, "error": "<code>", "hint": "..." }` — `401 missing_api_key` / `invalid_api_key`, `403 nextjs_site_disabled`, `404 product_not_found` / `category_not_found` / `page_not_found`, `422 invalid_collection` / `invalid_kind`, `429` rate limited |
| Rate limit | 600 requests/minute **per API key** (plus a 3000/minute per-IP backstop). All visitors share one Next.js server, so cache responses (Next's data cache) instead of calling per visitor |
| Visibility | Exactly the Blade storefront's rules: a product is shown only while `is_active` **and** status *Available*; categories only while active; pages only while published |
| Images | Always absolute URLs (the website is on another domain). A storage path that belongs to another company is never turned into a URL |
| Text | Returned exactly as the admin entered it. There is no translation of admin content yet (planned in `docs/i18n-bangla-english-plan.md`, Phase 4) |
| Money | Plain numbers in the company currency (`BDT`). `price` is the regular price, `sale_price` the discounted price |

## 3. Endpoints

### `GET /site`
Everything site-wide, from the same storefront settings row the Blade site uses:
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

### `GET /collections`
Live **Collections** (admin: *Site → Offers → type Collection*), ordered Flash Sale, Trending,
New Arrivals, then newest first. Each has `id, kind, kind_slug, title, slug, short_description,
cover_image_url, starts_at, ends_at` and `products` (cards, in the admin's order; invisible
products dropped). A collection with no visible product is omitted. `?kind=flash-sale|trending|new-arrivals`
returns one kind.

### `GET /pages` and `GET /pages/{slug}`
Published content pages: `slug, title, excerpt, cover_image_url`; the detail adds
`content_html` (sanitised), `meta_title`, `meta_description`, `updated_at`.

## 4. Collections, deals and the ERP price

Flash Sale / Trending / New Arrivals are **managed by the admin** as an Offer of type *Collection*:
a title, products in order, an optional start and end time (a Flash Sale requires an end time —
it drives the countdown) and an optional **deal price** per product. A collection is *live* while
it is Published and now is inside its window. Collections never appear as landing pages and cannot
be bought through the offer checkout (`/offers`).

The ERP price itself is never rewritten: `selling_price` stays `sale_price ?? price`. While a
collection is live, `deal.price` is reported separately. If a product is in several live
collections the Flash Sale deal wins.

**Open decisions for checkout (Phase 2):** the website currently shows `deal.price` when there is
a deal, otherwise the deepest reached `tier_prices` row, otherwise `selling_price`. Checkout must
re-price every line **on the server** (never trust a price from the browser) and the owner must
confirm how a live deal interacts with wholesale tiers.

## 5. Keeping the website fresh

The website caches ERP responses and refreshes them when the ERP sends a signed
`product.updated` webhook (price/sale price/stock changes — see
[`website-integration.md`](website-integration.md) §3). Other changes (banners, menus, categories,
descriptions, collections) show up when the website's safety-net cache expires (default 2 minutes).

## 6. Not in this API yet

Cart/checkout/payment (delivery fee, VAT, new-customer advance, pre-order advance, courier-risk
advance, ZiniPay/PayStation), customer login and order tracking, reviews submission, reseller
pricing, and offer landing pages. These follow in later phases. The OpenAPI file
[`website-integration.openapi.yaml`](website-integration.openapi.yaml) does not describe the
storefront endpoints yet.
