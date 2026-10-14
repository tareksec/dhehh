# IMS data feed (read-only sync API)

This is the contract for **IMS** (`tareksec/ims`, Investment & Inventory Management System), which pulls its business data from this ERP. It answers IMS's questionnaire `ERP-INTEGRATION-REQUIREMENTS.md`; the answers are in the last section.

The feed is **read-only**: `GET` only, so IMS can read a company's data and change nothing. Everything stays inside the company the key belongs to.

## Getting a key

ERP → **Settings → Integrations → Website API → Generate API key**, and choose the type **Data sync (read-only feed for IMS)**. The key is shown once; send it to IMS through a secure channel, never by email or in a document. Make **one key per business** (each business is a company in this ERP): the key decides whose data IMS sees.

A *Data sync* key opens `/api/v1/sync/*` and nothing else; a *Website* key (the older kind) cannot read the feed. Either wrong combination answers `403 wrong_key_scope`. Keys that existed before this feature are Website keys.

```
Authorization: Bearer <key>
```

Base URL: `https://<erp-domain>/api/v1/sync`. Rate limit: 300 requests per minute per key.

## Endpoints

| Path | What |
|---|---|
| `GET /ping` | Health check. Returns the company, the base `currency`, `timezone`, `server_time` and the list of resources. |
| `GET /products` `/categories` `/stock-movements` | Catalogue and the stock ledger. |
| `GET /suppliers` `/purchases` `/supplier-payments` | Buying. A purchase carries its lines, the cost components and the ERP's landed cost per unit. |
| `GET /containers` `/shipments` | Shipping. |
| `GET /customers` `/orders` `/order-payments` `/customer-payments` | Selling. An order carries its lines, its payments (each with its own `paid_at`) and its costs. |
| `GET /expenses` `/expense-categories` | Costs. |

### Query parameters (all optional)

| Parameter | Meaning |
|---|---|
| `updated_since` | ISO 8601 time. Only records changed at or after it. **Inclusive**, so a record on the boundary second is not lost; upsert by `id`, so a repeat is harmless. An offset is honoured (`2026-10-01T15:00:00+06:00` = `09:00Z`). |
| `cursor` | The `meta.next_cursor` of the previous page. |
| `per_page` | 1–500, default 100. |
| `status` | Orders / purchases / shipments / containers / products: the status. Stock movements and order payments: the `type`. |
| `date_from`, `date_to` | `YYYY-MM-DD`, on the record's business date (order date, purchase date, payment date, expense date). |
| `ids_only=1` | Only `id`, `updated_at`, `deleted` of every matching record. |

### Response

```json
{
  "data": [ { "id": 12, "order_number": "SYN-…", "total_amount": "1000.00", "…": "…" } ],
  "meta": { "resource": "orders", "count": 100, "per_page": 100, "has_more": true, "next_cursor": "W…", "server_time": "2026-10-13T08:00:00+00:00" }
}
```

Records are ordered by `updated_at`, then `id`. Paging by cursor never skips or repeats a record even when many share one second, and a record edited between two pages simply comes back once more at the end.

## How IMS should read it

1. **First load:** `GET /orders?per_page=500`, follow `next_cursor` until `has_more` is `false`; same for every resource. Use `date_from` to start from a chosen date.
2. **Every 15–60 minutes:** ask each resource with `updated_since` = the newest `updated_at` already stored **minus 2 minutes** (a row committing at the same moment as a read can carry an earlier time). Upsert by `id`.
3. **Once a day (full check):** `ids_only=1` for every resource and compare with the stored ids. A record missing from the feed was **deleted** in the ERP.
4. Edits to *only* a payment's date or method do not change the order row. Read **`/order-payments`** with `updated_since` as well, or the change is not noticed.

## Data rules

- **Money** is a string with two decimals (`"1234.50"`), in the company's base currency (`/ping` → `currency`). There is no exchange-rate table in this ERP; foreign-currency purchase costs are entered in BDT already.
- **Times** are ISO 8601 in UTC with the offset (`2026-10-01T10:00:00+00:00`). **Dates** are `YYYY-MM-DD`. The database clock is UTC.
- **Empty values** are `null`. Quantities are whole numbers. Text is UTF-8; Bangla names are supported.
- **Deleted / cancelled:** an order that is deleted stays in the feed with `"deleted": true`; cancelled, returned and refunded orders keep their `status`. Other records are removed for good: use the daily `ids_only` check.
- **Sale statuses:** `draft`, `confirmed`, `processing`, `completed`, `cancelled`, `returned`, `refunded`. The ERP's own accounting counts `confirmed`, `processing` and `completed`; *final* for profit sharing is the owner's call (see below).
- **Purchase statuses:** `draft`, `received`, `cancelled`. **Shipment statuses:** `planned`, `booked`, `shipped`, `in_transit`, `customs`, `received`, `cancelled`. **Payment types:** `advance`, `partial`, `final`. **Stock movement types:** `opening`, `purchase`, `sale`, `return`, `damage`, `adjustment`, `transfer` (`quantity` is stored positive, the type gives the direction).
- `landed_unit_cost` on a purchase line is the ERP's own figure, so IMS can compare it with the landed cost it works out.

## Errors

JSON with `ok: false`, `error` and a `hint`: `401 missing_api_key` / `invalid_api_key`, `403 wrong_key_scope`, `404` (unknown resource), `422 invalid_cursor` or a validation message, `429` (rate limit, retry after a minute). Every call is a `GET`, so repeating a request is always safe.

## Answers to IMS's questionnaire

| Section | Answer |
|---|---|
| 1. ERP | ZamZam ERP (Laravel / Filament, custom-built), hosted by the owner (Coolify). |
| 2. Access | REST API (JSON over HTTPS), Bearer key per company. No SOAP, GraphQL or database access. No webhooks for IMS yet (the existing website webhooks are a separate feature); poll with `updated_since`. |
| 2. Limits | 300 requests/minute/key, up to 500 records per page. No IP allow-list in the ERP; it can be added at the proxy if IMS gives its IP. |
| 3. Data | Everything in the table in section 3 exists **except** currency rates (single base currency per company). |
| 4. IDs | `id` is permanent and never reused; `order_number`, `purchase_number`, `sku` are unique business numbers. Each business is one company, one key. |
| 5. Direction | ERP → IMS only. IMS has no write access at all. |
| 5. Deletes | Orders: flagged `deleted`. Others: gone, found by the daily `ids_only` check. |
| 9.2 | Pagination by cursor, `updated_since`, status and date filters, line items included, last-modified time on every record. |
| 9.3 | See *Data rules*. |
| 9.5 | Webhooks: not provided for IMS. |
| 9.6 | Nothing is written back. |
| 10.1 | One business (company) = one IMS project is the natural fit with this ERP's structure (option A). **Owner to confirm.** |
| 7. Not shared | Passwords, reseller store data, supplier bank details and investor data are not in the feed. Customer name, phone, address and balances **are** (IMS asked for them); product and purchase cost prices are too. **Owner to confirm.** |

## Open owner decisions

1. **Investor accounting twice?** IMS says it will do investor management, profit sharing and payouts. This ERP already has its own investor portal and groups. Decide which system is the source of truth, or the same money is calculated in two places.
2. Confirm **one business = one project** (10.1).
3. Confirm what may not be copied to IMS (customer personal details, cost prices).
4. Which sale status is **final** for profit sharing (the ERP counts `confirmed`, `processing`, `completed` as sales).
5. Does IMS get its own IP allow-list, and who is the night-time contact if a sync fails?
