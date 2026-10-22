# Non-Laravel Apps & Folders
> Last checked: 2026-10-20, commit 0e5316d

## What it does
The repository houses several auxiliary applications, standalone microservices, design specifications, workflow automations, and build tooling that operate outside the core Laravel PHP framework. These include an Android hybrid wrapper application providing native thermal POS printing and crash reporting, a lightweight Capacitor mobile shell, and a containerized Node.js Baileys WhatsApp Web microservice (`whatsapp-bridge`). It also stores n8n workflow definitions for external chatbot automations, comprehensive UI design systems and prototypes for storefront themes, deployment metadata generators for containerized releases, and GitHub Actions CI/CD workflows that validate MySQL migration compatibility.

## Where to start
- `android/app/src/main/java/com/zamzamint/erp/MainActivity.java` — Android Capacitor entry point injecting native POS print and crash bridges into the webview.
- `whatsapp-bridge/src/server.js` — Node.js Express server running the Baileys WhatsApp Web QR-socket microservice.
- `docs/whatsapp-web-rules.md` — Authoritative rules, legal restrictions, and safety invariants for the WhatsApp Web bridge.
- `scripts/write-deployment-metadata.mjs` — Build script hashing source directories and embedding deployment IDs into production containers.
- `.github/workflows/mysql-migrations.yml` — CI workflow testing all migrations against production MySQL 8.4 to prevent SQLite divergence.
- `.github/workflows/deploy.yml` — GitHub Actions automated testing and deployment workflow triggering Coolify webhooks.
- `ERP Frontend Design System/design_handoff_storefront_themes/README.md` — Design handoff documentation for Blade storefront themes.
- `design-system/noor-solar-energy/MASTER.md` — Master design specifications and color tokens for the Noor Solar Energy storefront.

## Files

### Android Native Wrapper (`android/`)
| Path | What it does |
| --- | --- |
| `android/app/build.gradle` | Android Gradle build script configuring package ID `com.zamzamint.erp`, SDK versions, and Capacitor dependencies. |
| `android/app/src/main/AndroidManifest.xml` | Android application manifest declaring permissions (Internet, Bluetooth, Camera, Notifications). |
| `android/app/src/main/java/com/zamzamint/erp/MainActivity.java` | Main activity initializing Capacitor plugins, network monitors, and native JavaScript bridges. |
| `android/app/src/main/java/com/zamzamint/erp/CrashReporter.java` | Native uncaught exception handler transmitting Android crash logs to `/webhooks/mobile-crash-reports`. |
| `android/app/src/main/java/com/zamzamint/erp/PrintBridge.java` | JavaScript interface (`window.ZzPrintBridge`) driving `WebView.createPrintDocumentAdapter` through Android's `PrintManager` to open Android's native print dialog. |
| `android/app/src/main/java/com/zamzamint/erp/ShareBridge.java` | JavaScript bridge invoking native Android system share dialogs for receipts and images. |
| `android/app/src/main/java/com/zamzamint/erp/PushAvailabilityBridge.java` | Checks Google Play Services availability and registers FCM push tokens. |
| `android/app/src/main/java/com/zamzamint/erp/NetworkMonitor.java` | Monitors active connectivity and renders offline fallback screens when connection is lost. |
| `android/app/src/main/java/com/zamzamint/erp/ResilientBridgeWebViewClient.java` | Custom WebView client handling SSL error fallbacks and deep-link routing. |
| `android/app/src/main/java/com/zamzamint/erp/ZamZamApplication.java` | Custom Android Application class initializing crash logging upon app cold boot. |

### Mobile Shell Wrapper (`mobile-shell/`)
| Path | What it does |
| --- | --- |
| `mobile-shell/README.md` | Documentation detailing Capacitor setup, local development sync, and APK building. |
| `mobile-shell/www/index.html` | Minimal fallback HTML loader used by the mobile shell before loading the live ERP dashboard. |

### WhatsApp Web Microservice (`whatsapp-bridge/`)
| Path | What it does |
| --- | --- |
| `whatsapp-bridge/Dockerfile` | Alpine Node.js container recipe for running the Baileys bridge in Docker/Coolify. |
| `whatsapp-bridge/package.json` | Node.js manifest requiring `@whiskeysockets/baileys`, `express`, and `qrcode`. |
| `whatsapp-bridge/README.md` | Architecture and setup guide for the QR-linked WhatsApp Web microservice. |
| `whatsapp-bridge/src/server.js` | Express HTTP server providing `/session`, `/qr`, `/send`, and `/status` endpoints. |
| `whatsapp-bridge/src/session.js` | Manages Baileys multi-device socket lifecycle, authentication state, and reconnection. |
| `whatsapp-bridge/src/normalize.js` | Normalizes Baileys message objects into standardized JSON payloads for Laravel webhooks. |
| `whatsapp-bridge/src/outbox.js` | Disk-backed, ordered event delivery queue persisting outbound events to disk until ERP responds with 2xx. |
| `whatsapp-bridge/src/browser.js` | Emulates browser user-agent and platform signatures for WhatsApp Web pairing. |

### n8n Automation Workflows (`n8n Workflows/`)
| Path | What it does |
| --- | --- |
| `n8n Workflows/README.md` | Operational notes explaining how exported n8n workflows trigger ERP APIs and webhooks. |
| `n8n Workflows/Whatsapp Chatbot + Order Notification.json` | n8n workflow recipe connecting WhatsApp triggers to automated customer order updates. |
| `n8n Workflows/whatsapp image send.json` | Workflow automation dispatching product images over WhatsApp. |
| `n8n Workflows/messenger n8n workflows/Advanced  Messenger 2.0.json` | Complex conversational sales workflow for Facebook Messenger. |
| `n8n Workflows/messenger n8n workflows/Casual image send 2.0.json` | Messenger bot recipe handling customer photo requests. |
| `n8n Workflows/messenger n8n workflows/Image Send tool.json` | Automated image search and delivery tool for Messenger leads. |

### Design Systems & Storefront Specifications
| Path | What it does |
| --- | --- |
| `design-system/noor-solar-energy/MASTER.md` | Master design specification, component hierarchy, and Tailwind tokens for Noor Solar Energy. |
| `ERP Frontend Design System/Tasneem-Website-Redesign-Plan.pdf` | Visual architectural redesign plan for the corporate client website. |
| `ERP Frontend Design System/design_handoff_storefront_themes/README.md` | Index and technical handoff notes for storefront theme prototypes. |
| `ERP Frontend Design System/noor-solar-tailwind-livewire-threejs-v6.html` | High-fidelity interactive prototype featuring 3D product previews for solar storefronts. |
| `ERP Frontend Design System/solar-3d-homepage-prototype.html` | Standalone HTML/Three.js prototype for solar engineering e-commerce themes. |

### Build Scripts & CI/CD Workflows
| Path | What it does |
| --- | --- |
| `scripts/write-deployment-metadata.mjs` | Node.js script executed during builds to hash source files and write `deployment.json`. |
| `.github/workflows/deploy.yml` | GitHub Actions CI workflow executing PHPUnit, frontend builds, and Coolify webhook deployment. |
| `.github/workflows/mysql-migrations.yml` | GitHub Actions CI workflow running migrations against MySQL 8.4 service to catch dialect issues. |

## Routes and panels
- **Native Android Endpoints**:
  - `POST webhooks/mobile-crash-reports` (name `mobile-crash-reports.store`) -> `App\Http\Controllers\MobileCrashReportController@store`
  - `POST admin/push-devices` (name `admin.push-devices.store`) -> `App\Http\Controllers\Admin\PushDeviceController@store`
  - `DELETE admin/push-devices` (name `admin.push-devices.destroy`) -> `App\Http\Controllers\Admin\PushDeviceController@destroy`
- **WhatsApp Bridge Endpoints**:
  - `POST webhooks/whatsapp-web/{channel}` (name `webhooks.whatsapp-web.handle`) -> `App\Http\Controllers\WhatsAppWebWebhookController@handle`
  - `POST webhooks/whatsapp-web/{channel}/media` (name `webhooks.whatsapp-web.media`) -> `App\Http\Controllers\WhatsAppWebWebhookController@media`
  - `GET|HEAD admin/crm/whats-app-web-login` (name `filament.admin.crm.pages.whats-app-web-login`) -> `App\Filament\Pages\WhatsAppWebLogin`
- **CI/CD Triggers**:
  - `.github/workflows/deploy.yml`: Triggered on push to `main` branch.
  - `.github/workflows/mysql-migrations.yml`: Triggered on push to all branches and pull requests.

## Data
- Deployment metadata generated by `scripts/write-deployment-metadata.mjs`: stores deployment metadata (`commit`, `deployment_id`, `built_at`, source hashes) read by `AppDeployment`.
- `whatsapp_contacts`: Database table in Laravel populated by contacts synced from the `whatsapp-bridge` microservice. Key columns: `id`, `company_id`, `channel_id`, `jid`, `lid`, `phone`, `name`, `push_name`, `verified_name`, `customer_id`, `lead_id`, `first_seen_at`, `last_seen_at`, `created_at`, `updated_at`.
- `conversation_messages`: Stores inbound messages forwarded by the `whatsapp-bridge` via HTTP POST. Key columns: `id`, `conversation_id`, `direction`, `type`, `body`, `media_path`, `media_mime`, `external_message_id`, `delivery_status`, `sent_by`, `raw_payload`, `sent_at`, `generated_by`, `ai_confidence`, `ai_meta` (JSON), `delivery_channel`, `revoked_at`, `created_at`, `updated_at`.
- `push_devices`: Stores FCM device tokens registered by native Android devices. Key columns: `id`, `user_id`, `token`, `token_hash`, `device_id`, `platform`, `app_version`, `is_active`, `last_seen_at`, `disabled_at`, `last_error_code`, `last_error_at`, `created_at`, `updated_at`.

## Rules that matter
- **Native Android Printing**: The Android app embeds the web application in a WebView and injects `window.ZzPrintBridge`. When staff prints an invoice or thermal packing slip (`resources/views/orders/print[-bulk].blade.php`), the web application calls `ZzPrintBridge.print`, which invokes `WebView.createPrintDocumentAdapter` through Android's own `PrintManager` to open Android's native print dialog (there is no ESC/POS or Bluetooth printing). Enforced by `android/app/src/main/java/com/zamzamint/erp/PrintBridge.java`.
- **WhatsApp Web Safety Policy**: The `whatsapp-bridge` Node.js service must strictly adhere to the guidelines in `docs/whatsapp-web-rules.md`:
  - It must never initiate chats to new numbers (inbound replies only).
  - Bulk broadcasts, marketing campaigns, and AI auto-replies are prohibited on the bridge (must use official Cloud API).
  - Outbound events are queued on disk (`outbox.js`) and removed only after the ERP acknowledges delivery with 2xx.
  - Disconnect or ban events must never delete archived chat records in the ERP database.
  Enforced by `whatsapp-bridge/src/server.js`, `whatsapp-bridge/src/outbox.js`, and `docs/whatsapp-web-rules.md`.
- **MySQL Migration CI Verification**: Because unit tests run on SQLite (which ignores index length limitations and table lock constraints), `.github/workflows/mysql-migrations.yml` spins up a live MySQL 8.4 service container on every pull request and branch push. Migrations must pass on MySQL before being merged to `main`. Enforced by `.github/workflows/mysql-migrations.yml`.
- **Deployment Hash Immutability**: Production releases hash 10 critical source directories (`app`, `bootstrap`, `config`, `database`, `lang`, `public`, `resources`, `routes`, `scripts`) using SHA-256 via `scripts/write-deployment-metadata.mjs`. The resulting `deployment_id` uniquely identifies the build in `AppDeployment::current`. Enforced by `scripts/write-deployment-metadata.mjs` and `app/Support/AppDeployment.php`.
- **Design System Source of Truth**: When building new Blade storefront themes or modifying Tailwind CSS classes, developer implementations must conform to the design tokens, typography, and component structures defined in `ERP Frontend Design System/` and `design-system/noor-solar-energy/MASTER.md`.

## Links to other modules
- **core-platform.md**: `scripts/write-deployment-metadata.mjs` writes the deployment metadata read by `AppDeployment` and displayed on the App Versions page.
- **crm-messaging.md**: The `whatsapp-bridge/` service directly powers the WhatsApp Web channel in `ConversationChannelResource` and `WhatsAppWebLogin`.
- **sales-orders-couriers.md**: The Android `PrintBridge` is called by the invoice print pages (`resources/views/orders/print[-bulk].blade.php`, routes `orders.print` / `orders.print.bulk`) when they run inside the app.
- **storefront-blade.md**: Storefront Blade views (`resources/views/storefront/themes/`) are derived directly from the HTML prototypes in `ERP Frontend Design System/`.
- **api-integrations.md**: n8n workflows interact with the ERP via `/api/v1/*` website API endpoints and inbound webhook endpoints.

## Related docs
- `docs/whatsapp-web-rules.md` — Mandatory compliance rules and safety invariants for the WhatsApp Web bridge.
- `docs/deployment.md` — Coolify deployment guides and build instructions.
- `mobile-shell/README.md` — Guide for building and running the Capacitor Android application.
- `whatsapp-bridge/README.md` — Setup, environment variables, and Docker deployment guide for the Baileys bridge.
- `n8n Workflows/README.md` — Guide for importing and running external n8n automation workflows.
