# Website ↔ ERP Integration Guide

This is the developer-facing guide for connecting an external company
website to ZamZam ERP. The full endpoint reference (request/response shapes)
is in [`website-integration.openapi.yaml`](website-integration.openapi.yaml)
— import it into Postman (File → Import) or any Swagger UI. This document
covers the two things a spec file doesn't explain well: authentication and
webhook signature verification.

If a website team wrote their integration against a different, assumed API
shape (paths, field names, headers, event names) before seeing this guide,
see [`website-mapping-tasneem-noor.md`](website-mapping-tasneem-noor.md) —
a path-by-path, field-by-field mapping note written for Tasneem Knit
(tasneemknitindustry.com) and Noor Solar (noorsolaren.com), two separate
companies in this ERP.

## What this integration does

- **Products & stock — two-way, real-time.** Your website can read current
  price/stock, and can also write price/stock changes back (e.g. from your
  own admin dashboard). ERP-side changes are pushed to you immediately via
  webhook.
- **Orders — website → ERP.** A checkout on your website creates a Sales
  Order in ZamZam ERP.
- **Inquiries — website → ERP.** A contact/inquiry form submission creates a
  CRM lead ("query list") in ZamZam ERP.
- **Inquiry/quote status — ERP → website, one-way.** When staff move a lead
  your website submitted to another status, you receive a
  `lead.status_updated` webhook.
- **Order status — ERP → website, one-way.** Status is only ever changed
  from inside the ERP (production/shipping updates). Your website receives
  those changes via webhook and displays them; it never sets status itself.

## 1. Authentication

Generate a key from **ZamZam ERP → Settings → Integrations → Website API →
Generate API key**. The plaintext token is shown exactly once — copy it
immediately, it cannot be retrieved again (only revoked and replaced).

Send it on every request:

```
Authorization: Bearer <your-api-key>
```

The key is scoped to exactly one company — every request you make only ever
sees or affects that company's data. There is no separate "company id"
parameter to send.

A missing or invalid key gets a `401` with a JSON `error`/`hint` field
explaining what went wrong (`missing_api_key` / `invalid_api_key`).

## 2. Calling the API

Base URL: `https://<your-erp-domain>/api/v1`. See the OpenAPI spec for every
endpoint. Two things worth calling out:

- **`PATCH /products/{sku}`'s `stock` field is a target value, not a delta.**
  Send the number your dashboard currently shows; ZamZam ERP reconciles the
  difference internally as an auditable stock-ledger entry. You never need to
  calculate or send a delta yourself.
- **`POST /orders`'s `external_reference` makes retries safe.** Use your own
  order id/number. Re-posting the same value returns the existing order
  instead of creating a duplicate — so if a request times out and you're not
  sure whether it went through, it's always safe to just send it again.

## 3. Receiving webhooks

Configure your webhook URL and get a signing secret from **ZamZam ERP →
Settings → Integrations → Website API**. Use **Send test webhook** there to
confirm your endpoint is reachable before going live.

Every delivery is a `POST` to your URL with:

```
Content-Type: application/json
X-ZamZam-Signature: <hex-encoded HMAC-SHA256 of the raw request body>
```

```json
{
  "event": "order.status_updated",
  "data": { "order_number": "TK-20260920-01", "status": "processing", "...": "..." },
  "timestamp": "2026-09-20T10:15:00+00:00"
}
```

Events sent: `order.status_updated`, `product.updated`,
`lead.status_updated`, `storefront.updated` and `ping` (test webhook only).

`storefront.updated` (empty `data` except `updated_at`) means storefront content
changed — settings (home page, header, footer, menus), hero slides, pages,
categories, offers/collections. It is sent about 5 seconds after the first change
and at most once per 5 seconds per company, so the website can refresh its cache
and show a dashboard edit within seconds. The headless Next.js site
(`/api/erp/webhook`) refreshes everything it caches on it.

`lead.status_updated` is sent only for leads you created with an
`external_reference` (your own quote id) on `POST /leads` — that is how you
match it to your quote. Its `data` is `id`, `external_reference`, `status`
(`new`, `contacted`, `quoted`, `won`, `lost`) and `updated_at`. Re-posting
the same `external_reference` returns the existing lead instead of creating
a duplicate, so a lead submission is safe to retry.

**Verify the signature before trusting the payload** — compute the same HMAC
over the exact raw bytes you received and compare with a constant-time
comparison:

```php
// PHP
$signature = hash_hmac('sha256', $rawRequestBody, $webhookSecret);
if (! hash_equals($signature, $_SERVER['HTTP_X_ZAMZAM_SIGNATURE'] ?? '')) {
    http_response_code(403);
    exit;
}
```

```js
// Node.js
const crypto = require('crypto');
const signature = crypto.createHmac('sha256', webhookSecret).update(rawRequestBody).digest('hex');
if (!crypto.timingSafeEqual(Buffer.from(signature), Buffer.from(req.headers['x-zamzam-signature'] || ''))) {
  return res.status(403).end();
}
```

A delivery that fails (non-2xx response, timeout, connection error) is
retried by ZamZam ERP a couple of times with a short backoff, then dropped —
if you need a guarantee you didn't miss anything, use `GET
/orders/{order_number}` to poll as a fallback.

## 4. Firewall / network access

ZamZam ERP is hosted on a VPS behind Coolify/Traefik; there is no IP
allow-list by default; requests are authenticated by the Bearer API key
described above, not by source IP. If you need the connection restricted to
your website's IP addresses specifically, ask — that is a Traefik-level rule
we can add, it is not required for the API itself to work securely.

## 5. Staging / testing

See [`staging-setup.md`](staging-setup.md) — you'll be given a separate
staging URL, API key, and webhook secret to test against without touching
live data.
