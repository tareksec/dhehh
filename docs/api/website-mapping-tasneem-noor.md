# ওয়েবসাইট ↔ ZamZam ERP API Mapping নোট (Tasneem Knit, Noor Solar)

Tasneem Knit (tasneemknitindustry.com) আর Noor Solar (noorsolaren.com) —
দুই ওয়েবসাইটের ইন্টিগ্রেশন ডকুমেন্টে যে ERP API ধরা হয়েছে, তা এই রিপোর
ZamZam ERP-র আসল API ([`website-integration.md`](website-integration.md),
[`website-integration.openapi.yaml`](website-integration.openapi.yaml))-এর
সাথে মেলে না। নিচে প্রতিটি পাথ, ফিল্ড, হেডার ও ইভেন্ট কীভাবে বদলাতে হবে তা
দেওয়া হলো। দুটি সাইট ERP-তে **আলাদা কোম্পানি** — তাই প্রত্যেকের API key,
webhook secret ও ডাটা সম্পূর্ণ আলাদা।

## ১. সংযোগের তথ্য (.env)

ভ্যারিয়েবলের নাম আপনারা যা খুশি রাখতে পারেন; মান কোথা থেকে আসবে তা নিচে।
প্রতিটি কোম্পানির অ্যাডমিন নিজের কোম্পানিতে লগইন করে **Settings →
Integrations → Website API** থেকে এগুলো তৈরি করবেন।

| ভ্যারিয়েবল | মান | মন্তব্য |
| --- | --- | --- |
| `ERP_BASE_URL` / `ERP_API_BASE_URL` | `https://staging-app.zamzamint.com/api/v1` | শেষে অবশ্যই `/api/v1`। এটা staging; live URL পরে দেওয়া হবে। |
| `ERP_API_KEY` | **Generate API key** থেকে পাওয়া টোকেন | একবারই দেখানো হয় — সাথে সাথে কপি করুন। প্রতিটি রিকোয়েস্টে `Authorization: Bearer <key>`। কোনো company id পাঠাতে হয় না; key-ই কোম্পানি ঠিক করে। |
| `ERP_WEBHOOK_SECRET` | **Webhook signing secret** | ERP থেকে আসা webhook যাচাইয়ের জন্য। |
| Webhook URL (ERP-তে বসানো হবে) | আপনার ওয়েবসাইটের **একটি** endpoint | যেমন `https://noorsolaren.com/api/erp/webhook`। প্রতি কোম্পানিতে একটাই URL। |

- Tasneem Knit ও Noor Solar-এর key/secret আলাদা; একটার key দিয়ে অন্য
  কোম্পানির ডাটা দেখা বা বদলানো যায় না।
- সব রিকোয়েস্টে `Accept: application/json` দিন। লিমিট: প্রতি মিনিটে ১২০টি
  রিকোয়েস্ট।
- সাধারণ এরর: `401` (`missing_api_key` / `invalid_api_key`), `404` (SKU বা
  অর্ডার পাওয়া যায়নি), `422` (ভ্যালিডেশন), `429` (লিমিট পার)।

## ২. ওয়েবসাইট → ERP

### পাথ mapping

| আপনাদের ডকে যা আছে | আসল ERP endpoint | অবস্থা |
| --- | --- | --- |
| `POST /api/queries` (Tasneem), `POST /quotes` (Noor) | `POST /api/v1/leads` | পাথ ও ফিল্ড বদলাতে হবে |
| `sendInventoryUpdateToERP()` (Tasneem), `POST /products/sync` (Noor) | `PATCH /api/v1/products/{sku}` | পাথ ও ফিল্ড বদলাতে হবে |
| `POST /quotes/status` (Noor) | নেই | স্ট্যাটাস শুধু ERP-র ভেতর থেকে বদলায়; এই কল বন্ধ রাখুন |
| — | `GET /api/v1/products`, `GET /api/v1/products/{sku}` | দাম/স্টক পড়ার জন্য (পেজিনেশন, `per_page` সর্বোচ্চ ২০০) |
| — | `POST /api/v1/orders`, `GET /api/v1/orders/{order_number}` | অনলাইন অর্ডার থাকলে (নিচে দেখুন) |

### কোটেশন → `POST /api/v1/leads`

ERP এটাকে CRM Lead (সোর্স `website`) হিসেবে রাখে। আপনাদের কোটেশন আইডি
`external_reference`-এ পাঠান; বাকি বাড়তি তথ্য `interest` / `note`-এ জুড়ে
দিন, অন্য ফিল্ড চুপচাপ বাদ পড়ে।

| আপনাদের ফিল্ড | ERP ফিল্ড | নিয়ম |
| --- | --- | --- |
| `website_quote_id` | `external_reference` | ঐচ্ছিক কিন্তু পাঠাতেই হবে, সর্বোচ্চ ১৯০ অক্ষর; স্ট্যাটাস webhook এই আইডি দিয়েই ফেরত আসে |
| `customer_name` | `name` | বাধ্যতামূলক, সর্বোচ্চ ২৫৫ অক্ষর |
| `phone` | `phone` | বাধ্যতামূলক, সর্বোচ্চ ৩০ অক্ষর |
| `email` | `email` | ঐচ্ছিক, বৈধ ইমেইল |
| `product.name` + `product.model` + `quantity` | `interest` | ঐচ্ছিক, সর্বোচ্চ ২০০০ অক্ষর; যেমন `620W TOPCon (NS-620TOP) — 500 kWp` |
| `company_name`, `delivery_location`, `notes` | `note` | ঐচ্ছিক, সর্বোচ্চ ২০০০ অক্ষর |
| `source`, `created_at`, `product.id`, `erp_product_id` | — | পাঠানোর দরকার নেই |

রেসপন্স `201` — `{ "success", "id" }` নয়, বরং:

```json
{ "data": { "id": 123, "external_reference": "TK-QUOTE-123456", "status": "new", "created_at": "2026-09-28T05:30:00+00:00" } }
```

`data.id` আপনাদের `erpReferenceId` হিসেবে সেভ করুন। একই
`external_reference` আবার পাঠালে নতুন Lead হয় না — আগেরটাই `200` সহ ফেরত
আসে, তাই timeout হলে নিশ্চিন্তে retry করতে পারেন।

### দাম/স্টক আপডেট → `PATCH /api/v1/products/{sku}`

প্রোডাক্ট আগে থেকেই ERP-তে থাকতে হবে; API দিয়ে নতুন প্রোডাক্ট তৈরি হয় না।
মিলানোর চাবি হলো ERP-র **SKU**। অন্তত একটি ফিল্ড পাঠাতে হবে, নাহলে `422`।

| আপনাদের ফিল্ড | ERP | নিয়ম |
| --- | --- | --- |
| `item_code` (Tasneem), `model` (Noor) | URL-এর `{sku}` | ওয়েবসাইটের আইটেমে ERP-র SKU সেভ রাখুন |
| `price` (Tasneem), `price_bdt` (Noor) | `price` | সংখ্যা, ০ বা বেশি |
| — | `sale_price` | ঐচ্ছিক ছাড়ের দাম, `null` দিলে মুছে যায় |
| `stock_quantity` (Tasneem) | `stock` | পূর্ণসংখ্যা। **চূড়ান্ত সংখ্যা পাঠান, পার্থক্য (+/-) নয়** |
| `stock_status`, `show_price`, `slug`, `brand`, `name` (Noor) | — | ERP-তে এই ফিল্ড নেই; ওয়েবসাইটেই রাখুন |

```json
PATCH /api/v1/products/NS-620TOP
{ "price": 16500, "stock": 50 }
```

রেসপন্স `200`: `{ "data": { "sku", "name", "price", "sale_price", "stock", "is_active", "updated_at" } }`।
আপনার এই আপডেটের জন্য ERP আবার আপনাকে webhook ফেরত পাঠাবে না।

### অনলাইন অর্ডার (প্রয়োজন হলে) → `POST /api/v1/orders`

ফিল্ড: `external_reference` (আপনার অর্ডার আইডি, বাধ্যতামূলক),
`customer.name`, `customer.phone`, `customer.address` (বাধ্যতামূলক),
`customer.email`, `note`, `items[].sku`, `items[].quantity`,
`items[].unit_price` (ঐচ্ছিক)। একই `external_reference` আবার পাঠালে নতুন
অর্ডার হয় না, আগেরটাই ফেরত আসে — তাই retry নিরাপদ।

## ৩. ERP → ওয়েবসাইট (Webhook)

ERP প্রতি কোম্পানির **একটি URL**-এ সব ইভেন্ট `POST` করে; কোন ইভেন্ট তা
বোঝার জন্য বডির `event` দেখুন। Tasneem-এর দুটি আলাদা route (`/inventory`,
`/quote-status`) একটা route-এ এনে `event` দিয়ে ভাগ করুন।

| বিষয় | আপনাদের ডকে | আসল ERP |
| --- | --- | --- |
| সিগনেচার হেডার | `X-ERP-Signature` | `X-ZamZam-Signature` |
| অ্যালগরিদম | HMAC-SHA256, hex | একই — কোনো বদল নেই |
| Bearer দিয়ে যাচাই (Noor-এর পদ্ধতি খ) | গ্রহণযোগ্য | পাঠানো হয় না; শুধু সিগনেচার |
| বডির আকার | `{ event, data }` | `{ event, data, timestamp }` |
| সফল রেসপন্স | — | যেকোনো `2xx`; ব্যর্থ হলে ERP কয়েকবার retry করে, তারপর বাদ দেয় |

সিগনেচার হিসাব করুন **কাঁচা বডির** ওপর — `JSON.parse` করে আবার stringify
করলে মিলবে না। Next.js route handler-এ:

```js
const raw = await req.text();
const expected = crypto.createHmac('sha256', process.env.ERP_WEBHOOK_SECRET).update(raw).digest('hex');
const given = req.headers.get('x-zamzam-signature') || '';
if (given.length !== expected.length || !crypto.timingSafeEqual(Buffer.from(given), Buffer.from(expected))) {
  return new Response(null, { status: 403 });
}
const { event, data } = JSON.parse(raw);
```

### ইভেন্ট mapping

| আপনাদের ইভেন্ট | ERP ইভেন্ট | কখন আসে |
| --- | --- | --- |
| `inventory.updated` (Tasneem), `product.updated` (Noor) | `product.updated` | ERP-তে দাম, ছাড়ের দাম বা স্টক বদলালে, এক প্রোডাক্ট এক কল |
| `inventory.bulk_update` (Noor) | — | bulk ইভেন্ট নেই; প্রতি প্রোডাক্টের আলাদা `product.updated` আসে |
| `quote.status_updated` | `lead.status_updated` | আপনার পাঠানো কোটেশনের (যেটায় `external_reference` ছিল) স্ট্যাটাস ERP-তে বদলালে — অংশ ৪ দেখুন |
| — | `order.status_updated` | `/orders` দিয়ে তৈরি অর্ডারের স্ট্যাটাস বা ডেলিভারি স্ট্যাটাস বদলালে |
| `ping` | `ping` | Integrations পেজের **Send test webhook**; `data` খালি |

### `product.updated` এর `data` ফিল্ড

| ERP ফিল্ড | Tasneem-এ যা ছিল | Noor-এ যা ছিল |
| --- | --- | --- |
| `sku` | `item_code` | `model` / `erp_product_id` |
| `name` | — | — |
| `price` | `price` | `price_bdt` |
| `sale_price` (null হতে পারে) | — | — |
| `stock` (পূর্ণসংখ্যা) | `stock_quantity` | `stock_status` নেই — `stock` থেকে নিজেরা ঠিক করুন (যেমন `> 0` হলে `IN_STOCK`, `0` হলে `ON_REQUEST`) |
| `updated_at` | — | — |

`website_machine_id`, `product_id` (ওয়েবসাইটের আইডি) আসে না — `sku` দিয়ে
আপনাদের আইটেম খুঁজুন। `INCOMING` ও `show_price`-এর কোনো ERP মান নেই।

### `order.status_updated` এর `data` ফিল্ড

`order_number`, `external_reference` (আপনার পাঠানো আইডি, সামনে `webapi-`
যুক্ত), `status`, `delivery_status`, `workflow_stage`, `updated_at`।

- `status`: `draft`, `confirmed`, `processing`, `completed`, `cancelled`, `returned`, `refunded`
- `delivery_status`: `not_booked`, `booking_pending`, `booked`, `picked_up`, `in_transit`, `delivered`, `partial_delivered`, `returned`, `cancelled`, `failed`

webhook মিস হলে `GET /api/v1/orders/{order_number}` দিয়ে স্ট্যাটাস পড়ে
নিতে পারেন।

## ৪. কোটেশন স্ট্যাটাস webhook (`lead.status_updated`)

ERP-তে কোনো কর্মী আপনার পাঠানো কোটেশনের স্ট্যাটাস বদলালে সাথে সাথে এই
ইভেন্ট আসে। শুধু `external_reference` দিয়ে পাঠানো কোটেশনের জন্যই আসে;
অন্য স্রোতের Lead আপনাকে পাঠানো হয় না।

```json
{
  "event": "lead.status_updated",
  "data": {
    "id": 123,
    "external_reference": "TK-QUOTE-123456",
    "status": "quoted",
    "updated_at": "2026-09-28T10:15:00+00:00"
  },
  "timestamp": "2026-09-28T10:15:00+00:00"
}
```

| আপনাদের ডকে | ERP ফিল্ড |
| --- | --- |
| `website_quote_id` / `quote_id` | `external_reference` |
| `erp_reference_id` / `erp_quote_id` | `id` |
| `status` | `status`: `new`, `contacted`, `quoted`, `won`, `lost` (ছোট হাতের) |
| `tracking_status`, `tracking_number`, `note` / `admin_notes` | এখনো নেই |

গ্রাহককে কী লেখা দেখাবেন (যেমন `won` → "অর্ডার নিশ্চিত"), সেটা ওয়েবসাইটে
ম্যাপ করুন। শিপমেন্ট ট্র্যাকিং দরকার হলে কোটেশন অর্ডার হওয়ার পর
`order.status_updated`-এর `delivery_status` ব্যবহার করা যায়।

### ERP-তে যা নেই

| আপনাদের চাহিদা | আপাতত কী করবেন |
| --- | --- |
| `tracking_status` / `tracking_number` | কোন ধাপ দেখানো হবে মালিক ঠিক করলে যোগ হবে; নিজেরা মান ধরে নেবেন না |
| `company_name`, `quantity`, `delivery_location`, product আলাদা ফিল্ড | `interest` / `note`-এ জুড়ে দিন |
| ওয়েবসাইট থেকে স্ট্যাটাস পাঠানো (`POST /quotes/status`) | আসবে না — স্ট্যাটাস শুধু ERP থেকে ওয়েবসাইটে যায়; কলটা বন্ধ করুন |
| API দিয়ে নতুন প্রোডাক্ট তৈরি (Noor-এর `products/sync`) | প্রোডাক্ট ERP অ্যাডমিন তৈরি করবেন; ওয়েবসাইট শুধু SKU দিয়ে দাম/স্টক বদলাবে |

## ৫. টেস্ট চেকলিস্ট

- [ ] Staging URL, API key ও webhook secret হাতে পেয়েছেন (দুই কোম্পানির আলাদা)
- [ ] ওয়েবসাইটের আইটেমে ERP-র SKU বসানো হয়েছে
- [ ] `GET /products` ঠিক কোম্পানির প্রোডাক্ট দেখাচ্ছে
- [ ] কোটেশন সাবমিট → ERP-র CRM Leads-এ সোর্স Website সহ একটি Lead
- [ ] ওয়েবসাইট থেকে দাম/স্টক বদল → ERP-তে বদল দেখা যাচ্ছে
- [ ] ERP-র **Send test webhook** → আপনার endpoint `ping` পেয়ে `2xx` দিচ্ছে
- [ ] ERP-তে দাম/স্টক বদল → `product.updated` এসে ওয়েবসাইট আপডেট হচ্ছে
- [ ] ভুল সিগনেচারে আপনার endpoint `403` দিচ্ছে

```bash
# কোটেশন পাঠানো
curl -X POST "$ERP_BASE_URL/leads" \
  -H "Authorization: Bearer $ERP_API_KEY" \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"external_reference":"TK-QUOTE-123456","name":"Md. Rahim Chowdhury","phone":"+8801712345678","interest":"620W TOPCon (NS-620TOP) - 500 kWp","note":"Company: Rahim Textiles Ltd | Delivery: Gazipur"}'

# দাম/স্টক আপডেট
curl -X PATCH "$ERP_BASE_URL/products/NS-620TOP" \
  -H "Authorization: Bearer $ERP_API_KEY" \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"price":16500,"stock":50}'

# নিজের webhook endpoint সিগনেচার সহ টেস্ট
BODY='{"event":"ping","data":[],"timestamp":"2026-09-28T10:00:00+00:00"}'
SIG=$(printf '%s' "$BODY" | openssl dgst -sha256 -hmac "$ERP_WEBHOOK_SECRET" | sed 's/^.* //')
curl -X POST https://<your-site>/api/erp/webhook \
  -H "Content-Type: application/json" -H "X-ZamZam-Signature: $SIG" -d "$BODY"
```

পূর্ণ endpoint রেফারেন্স: ERP টিমের
[`website-integration.openapi.yaml`](website-integration.openapi.yaml)
(Postman-এ Import করা যায়)।
