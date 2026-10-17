# রিসেলার স্টোরের সাবডোমেইন: Cloudflare ও Coolify সেটআপ গাইড

এই কাজটা সার্ভারের দিকের, ERP-এর কোডে আর কিছু করার নেই। কোডের অংশ তৈরি আছে। রিসেলার My Store-এ নাম বাছলে `name.offer.zamzamint.com` ঠিকানা তৈরি হয়, কাউন্টডাউন চলে, আর ঠিকানা HTTPS-এ খুললে "Live" দেখায়।

সাবডোমেইন কাজ করতে সার্ভারে **তিনটি জিনিস** লাগে:

1. **ওয়াইল্ডকার্ড DNS:** `*.offer.zamzamint.com` সার্ভারের IP-তে যাবে।
2. **ওয়াইল্ডকার্ড SSL সার্টিফিকেট:** `*.offer.zamzamint.com`-এর জন্য।
3. **রুটিং:** এই নামগুলোর সব রিকোয়েস্ট Coolify-তে ERP অ্যাপে যাবে।

ERP-তে এর বেশি কিছু সেট করতে হয় না। `TRUSTED_PROXIES=*` আগে থেকেই আছে।

## কেন Cloudflare-এর ফ্রি SSL যথেষ্ট না

Cloudflare-এর ফ্রি Universal SSL শুধু রুট ডোমেইন আর **এক স্তরের** সাবডোমেইন ঢাকে (`zamzamint.com`, `www.zamzamint.com`)। `name.offer.zamzamint.com` **দুই স্তরের**, তাই কমলা মেঘ (Proxied) অবস্থায় এর জন্য ফ্রি সার্টিফিকেট থাকে না। উপায় দুটি:

- **Cloudflare Advanced Certificate Manager:** পেইড (মাসিক ফি আছে, বর্তমান দাম Cloudflare-এ দেখে নিন)।
- **ফ্রি উপায় (নিচে এটাই লেখা):** সাবডোমেইনের রেকর্ড **DNS only (ধূসর মেঘ)** রাখা, আর Coolify-র Traefik নিজে Let's Encrypt থেকে `*.offer.zamzamint.com` ওয়াইল্ডকার্ড সার্টিফিকেট নেবে। Traefik ওয়াইল্ডকার্ড শুধু DNS challenge দিয়ে নিতে পারে, তাই Cloudflare-এর একটি API token দিতে হবে।

**ফ্রি উপায়টাই সুপারিশ করছি।** একটা কারণ আছে: ERP-র "My Store" পেজ দেখে সাবডোমেইনের IP আর `offer.zamzamint.com`-এর IP মেলে কি না। দুটোই DNS only হলে সার্ভারের একই IP আসে, তাই মেলে। কমলা মেঘে দুটো নাম Cloudflare-এর আলাদা IP পেতে পারে, তখন "Live" দেখানো আটকে যেতে পারে।

## ধাপ ১: Cloudflare API token

1. Cloudflare → My Profile → API Tokens → Create Token → Custom token।
2. অনুমতি দুটি: **Zone → Zone → Read** এবং **Zone → DNS → Edit**।
3. Zone Resources: শুধু `zamzamint.com`। অ্যাকাউন্টের সব জোন দেবেন না।
4. টোকেনটি একবারই দেখাবে, কপি করে রাখুন। এটা পাসওয়ার্ডের মতো গোপন, চ্যাটে বা গিটে দেবেন না।

## ধাপ ২: ওয়াইল্ডকার্ড DNS রেকর্ড

Cloudflare → `zamzamint.com` → DNS → Records → Add record:

| Type | Name | Content | Proxy status |
|---|---|---|---|
| A | `*.offer` | Coolify সার্ভারের IP | **DNS only** (ধূসর মেঘ) |

- `offer` ও `reseller.offer` যেমন আছে তেমন থাকবে। নির্দিষ্ট নাম ওয়াইল্ডকার্ডের চেয়ে আগে মেলে, তাই এগুলো ভাঙে না।
- যাচাই (কয়েক মিনিট পর, নিজের কম্পিউটারে): `nslookup test123.offer.zamzamint.com`। সার্ভারের IP দেখালে ঠিক আছে।

## ধাপ ৩: Coolify-র Traefik-এ Cloudflare DNS challenge যোগ করা

Coolify → **Servers → আপনার সার্ভার → Proxy → Configuration**। ফাইলটি ডিস্কে সরাসরি বদলাবেন না, Coolify প্রক্সি চালুর সময় তা মুছে দিতে পারে।

আমি **আলাদা একটি resolver** (নাম `cloudflare`) যোগ করার পথ ধরেছি। আগের `letsencrypt` resolver যেমন আছে তেমন থাকে, তাই সার্ভারের বাকি সব সাইটের সার্টিফিকেট নেওয়া আগের মতোই চলে। Coolify-র নিজের গাইড মূল resolver-টাই DNS challenge-এ বদলাতে বলে, কিন্তু তাতে Cloudflare-এ নেই এমন ডোমেইনের নতুন সার্টিফিকেট আটকাতে পারে।

1. `traefik` সার্ভিসের `environment`-এ যোগ করুন:
   ```
   - CF_DNS_API_TOKEN=<ধাপ ১-এর টোকেন>
   ```
2. `command`-এ এই **তিনটি লাইন যোগ করুন** (আগের কোনো লাইন মুছবেন না):
   ```
   --certificatesresolvers.cloudflare.acme.dnschallenge.provider=cloudflare
   --certificatesresolvers.cloudflare.acme.dnschallenge.delaybeforecheck=0
   --certificatesresolvers.cloudflare.acme.storage=/traefik/acme-cloudflare.json
   ```
   প্রতিটি resolver-এর স্টোরেজ ফাইল আলাদা হতে হয়, তাই শেষ লাইনের নাম `acme.json` নয়।
3. Save করে **Restart Proxy** চাপুন।

## ধাপ ৪: ওয়াইল্ডকার্ড সার্টিফিকেট চাওয়া

Coolify → Servers → আপনার সার্ভার → Proxy → **Dynamic Configurations** → নতুন ফাইল, নাম `wildcard-offer-zamzamint-com.yaml`:

```yaml
http:
  routers:
    wildcard-offer-zamzamint-com:
      rule: 'HostRegexp(`[a-z0-9-]+\.offer\.zamzamint\.com`)'
      entryPoints:
        - https
      priority: 1
      service: wildcard-placeholder
      tls:
        certResolver: cloudflare
        domains:
          - main: offer.zamzamint.com
            sans:
              - '*.offer.zamzamint.com'
  services:
    wildcard-placeholder:
      loadBalancer:
        servers: []
```

Save করুন (সাধারণত প্রক্সি রিস্টার্ট লাগে না)। তারপর **Proxy → Logs** দেখুন, `acme` সংক্রান্ত লাইনে সার্টিফিকেট পাওয়ার খবর আসা উচিত। ভুল হলে এখানেই কারণ লেখা থাকে (সাধারণ কারণ: টোকেনে Zone Read নেই, বা DNS ছড়াতে দেরি)।

## ধাপ ৫: সাবডোমেইনগুলো ERP অ্যাপে পাঠানো

ধাপ ৪-এর রাউটারের সার্ভিস ফাঁকা, তাই এটা শুধু সার্টিফিকেট আনে। রিকোয়েস্ট ERP-তে পাঠাতে হয়। এই ধাপটি Coolify-র সংস্করণ ভেদে আলাদা হতে পারে, আর আমি আপনার সার্ভারে এটা পরীক্ষা করতে পারিনি। তাই একটা পরীক্ষামূলক নামে আগে দেখে নিন।

**প্রথমে এটা চেষ্টা করুন:** Coolify → ERP অ্যাপ → Configuration → **Domains**-এ আগের ডোমেইনগুলোর সঙ্গে যোগ করুন `https://*.offer.zamzamint.com` এবং Redeploy করুন।

**Coolify ডোমেইন না নিলে বা রুট না করলে:** অ্যাপের Container Labels-এ (Custom labels) এই রাউটার যোগ করুন। পোর্টের জায়গায় অ্যাপ যে পোর্টে চলে (Ports Exposes) সেটা বসান:

```
traefik.enable=true
traefik.http.routers.reseller-wildcard.entryPoints=https
traefik.http.routers.reseller-wildcard.rule=HostRegexp(`[a-z0-9-]+\.offer\.zamzamint\.com`)
traefik.http.routers.reseller-wildcard.priority=5
traefik.http.routers.reseller-wildcard.tls=true
traefik.http.routers.reseller-wildcard.tls.certresolver=cloudflare
traefik.http.routers.reseller-wildcard.service=reseller-wildcard
traefik.http.services.reseller-wildcard.loadbalancer.server.port=<অ্যাপের পোর্ট>
```

`reseller.offer.zamzamint.com` ও `offer.zamzamint.com` আগের মতো তাদের নিজের রাউটারে যাবে, কারণ সেগুলোর নির্দিষ্ট নাম আছে, আর ওয়াইল্ডকার্ডের প্রায়োরিটি কম। রিডিপ্লয়ের পর ব্রাউজারে `reseller.offer.zamzamint.com/portal/login` এবং `offer.zamzamint.com` দুটোই একবার খুলে দেখুন, আগের মতো চলছে কি না।

## ধাপ ৬: ERP থেকে যাচাই (নতুন বাটন)

ERP অ্যাডমিন → Resellers → **Reseller Settings** → উপরে **"সাবডোমেইন সেটআপ পরীক্ষা করুন"** (Test subdomain setup)। এটা `setup-test-xxxxxx.offer.zamzamint.com` নামে একটা বানানো নাম খুলে দেখে:

| বার্তা | মানে | কী করবেন |
|---|---|---|
| সাবডোমেইন HTTPS-এ খুলছে | DNS ও সার্টিফিকেট দুটোই ঠিক | এবার "Reseller subdomains enabled" চালু করুন |
| সাবডোমেইন এখনো এই সার্ভারে পৌঁছাচ্ছে না | ওয়াইল্ডকার্ড DNS নেই বা ভুল IP-তে গেছে | ধাপ ২ দেখুন (DNS only কি না) |
| সাবডোমেইন পৌঁছাচ্ছে কিন্তু HTTPS কাজ করছে না | সার্টিফিকেট নেই | ধাপ ৩–৪ দেখুন, Proxy Logs পড়ুন |

যাচাই সার্ভার নিজেই নিজের পাবলিক ঠিকানা খুলে করে। কিছু হোস্টিংয়ে সার্ভার নিজের পাবলিক IP-তে ঢুকতে পারে না, তখন এটা ব্যর্থ বলতে পারে অথচ ব্রাউজারে ঠিক খোলে। তাই ফল সন্দেহজনক হলে নিজে ব্রাউজারে একটা বানানো নাম (যেমন `abc123.offer.zamzamint.com`) খুলে দেখুন। এতে ERP-র "ঠিকানা পাওয়া যায়নি" ধরনের পেজ এলে রুটিং ও SSL ঠিক আছে।

শেষে একটি পরীক্ষামূলক রিসেলার দিয়ে My Store-এ একটি নাম বাছুন, কাউন্টডাউন শেষে "Live" দেখালে সব ঠিক।

## TRUSTED_PROXY_HOPS নিয়ে

- **ERP (এই অ্যাপ):** `TRUSTED_PROXY_HOPS` বলে কিছু নেই। ERP `TRUSTED_PROXIES=*` ধরে কাজ করে, তাই Traefik বা Cloudflare যা-ই সামনে থাকুক বদলাতে হয় না।
- **Next.js ওয়েবসাইট:** ওখানে `TRUSTED_PROXY_HOPS` আছে, ভিজিটরের আসল IP বের করতে। মান নির্ভর করে **ওয়েবসাইটের নিজের ডোমেইন** (যেমন `zamzamint.com`) Cloudflare-এ কমলা মেঘে আছে কি না:
  - কমলা মেঘ (Cloudflare + Traefik): `2`
  - ধূসর মেঘ বা Cloudflare ছাড়া (শুধু Traefik): `1`
  
  রিসেলারের ওয়াইল্ডকার্ড রেকর্ড ধূসর রাখলে এর কোনো প্রভাব পড়ে না, কারণ ওটা অন্য হোস্ট। ওয়েবসাইটের ভুল মান থাকলে ভিজিটরের IP ভুল আসে, আর IP-ভিত্তিক নিয়ম (ঝুঁকি যাচাই, রেট লিমিট) ঠিক কাজ করে না।

## পরে বদলাতে চাইলে

- **পেইড Advanced Certificate Manager নিলে:** ওয়াইল্ডকার্ড রেকর্ড কমলা মেঘে নেওয়া যায়, তখন Cloudflare-এ SSL মোড **Full (strict)** রাখতে হবে। সার্ভারেও বৈধ সার্টিফিকেট লাগবে (ধাপ ৩–৪ রেখে দিলেই হয়)। কিন্তু উপরে বলা IP মেলানোর শর্তে "Live" দেখানো আটকাতে পারে, তাই নেওয়ার আগে আমাকে জানান।
- **রিসেলারের নিজের ডোমেইন (`myshop.com`):** এটা আলাদা ধাপ, `docs/deployment.md`-এর "Reseller custom domains" অংশ দেখুন।

## সূত্র

- Coolify: [DNS challenge](https://coolify.io/docs/knowledge-base/proxy/traefik/dns-challenge), [Wildcard certificates](https://coolify.io/docs/knowledge-base/proxy/traefik/wildcard-certs)
- Cloudflare: [Universal SSL-এর সীমা](https://developers.cloudflare.com/ssl/edge-certificates/universal-ssl/limitations), [Advanced Certificate Manager](https://developers.cloudflare.com/ssl/edge-certificates/advanced-certificate-manager/)
