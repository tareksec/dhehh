# CRM & Omnichannel Messaging
> Last checked: 2026-10-23 (SMS Dashboard added; gateway URL is built from the API key and sender ID boxes; a site with a broken gateway sends through the other working site)

## What it does
The CRM & Omnichannel Messaging module manages sales leads, customer inquiries, multi-channel chat conversations, automated follow-ups, and marketing broadcasts. It unifies customer communications across WhatsApp Cloud API, WhatsApp Web (QR Baileys bridge), Facebook Messenger, SMS gateways, and Telegram into a single real-time inbox. It incorporates an AI Inbox Control Plane featuring intelligent automated replies, multimodal image/voice note comprehension, intent qualification, cart link generation, and smart human hand-over queues (Agent Desk, Needs Attention, To Chase, Unanswered Questions). Additionally, it coordinates scheduled sales automation cadences, promotional SMS/Messenger broadcasts, quick-reply snippet management, and Firebase Cloud Messaging (FCM) web push notifications.

## Where to start
- `app/Models/Conversation.php` — Central conversation thread model tracking channel, customer, assigned staff, AI mode, and unread counts.
- `app/Services/Crm/ConversationMessengerService.php` — Core omnichannel dispatcher sending and receiving messages across connected providers.
- `app/Services/Crm/AiReplyService.php` — AI conversational agent generating contextual sales responses, catalog product cards, and cart checkout links.
- `app/Services/Crm/ConversationModeService.php` — Governs transitions between autonomous `ai` mode and human agent `manual` mode with activity timeouts.
- `app/Filament/Pages/Inbox.php` — Filament real-time chat interface featuring conversation threads, customer panels, and order generation.
- `app/Services/WhatsAppWeb/WhatsAppWebBridgeClient.php` — Integration client for the local `whatsapp-bridge` Baileys QR-code microservice.
- `docs/whatsapp-web-rules.md` — Mandatory compliance rules and safety invariants for QR-linked WhatsApp Web channels.
- `app/Services/Crm/AttentionQueueService.php` — Evaluates chat urgency, negative sentiment, and unanswered customer questions.

## Files

### Models
| Path | What it does |
| --- | --- |
| `app/Models/Lead.php` | Sales lead entity tracking acquisition source, pipeline stage, estimated value, and assigned agent. |
| `app/Models/LeadActivity.php` | Activity timeline recording notes, calls, follow-up tasks, and stage changes per lead. |
| `app/Models/Conversation.php` | Unified conversation thread grouping customer messages across a specific channel. |
| `app/Models/ConversationChannel.php` | Configured messaging channels (WhatsApp Cloud, WhatsApp Web, Messenger, Telegram, SMS). |
| `app/Models/ConversationMessage.php` | Individual message record storing text, attachments, direction (inbound/outbound), and AI tags. |
| `app/Models/ConversationModeEvent.php` | Audit log of mode transitions (`ai` to `manual` and vice-versa) with reasons and agent IDs. |
| `app/Models/WhatsAppContact.php` | Cached WhatsApp address book records and profile pictures synced from the phone. |
| `app/Models/SmsTemplate.php` | Dynamic SMS templates with variable placeholders for order updates and verification. |
| `app/Models/SmsLog.php` | One row per SMS: queued, sent, refused by the gateway, or skipped, with the reason and the SMS parts used. Order SMS keep their text; every other SMS (login/recovery codes, reminders, broadcasts) is a `kind = other` row with phone, result and parts only, never the text. |
| `app/Models/EmailLog.php` | One row per email: sent, failed or not sent, with the reason and the mail server's own answer (never the message text or a code). |
| `app/Models/Broadcast.php` | Marketing broadcast campaign entity targeting filtered customer segments. |
| `app/Models/BroadcastRecipient.php` | Individual recipient delivery status tracker for a broadcast campaign. |
| `app/Models/QuickReply.php` | Pre-saved response shortcuts for common customer inquiries. |
| `app/Models/PushDevice.php` | Browser and mobile device FCM registration tokens for web push alerts. |

### Services
| Path | What it does |
| --- | --- |
| `app/Services/Crm/ConversationMessengerService.php` | Dispatches outbound messages to the correct channel adapter and logs message records. |
| `app/Services/Crm/ConversationModeService.php` | Handles mode switching between AI auto-reply and human agent control. |
| `app/Services/Crm/ConversationPresenceService.php` | Tracks real-time staff typing indicators and active thread viewers in the inbox. |
| `app/Services/Crm/AiReplyService.php` | Coordinates LLM prompt preparation, knowledge retrieval, and automated response drafting. |
| `app/Services/Crm/AiRunService.php` | Low-level execution service executing AI evaluation cycles and logging token usage. |
| `app/Services/Crm/AiSettingsService.php` | Manages AI tool configuration, model temperature, system instructions, and tool enablement. |
| `app/Services/Crm/AiInstructionService.php` | Builds structured system prompts with product pricing rules and store policies. |
| `app/Services/Crm/AiInboxReportService.php` | Compiles AI performance reports (resolution rates, handover counts, customer sentiment). |
| `app/Services/Crm/AiSetupChecklistService.php` | Audits readiness of AI settings, catalog completeness, and channel credentials. |
| `app/Services/Crm/KnowledgeBaseService.php` | Searches and retrieves company FAQ knowledge chunks for RAG-assisted answers. |
| `app/Services/Crm/AttentionQueueService.php` | Filters conversations requiring urgent human review due to escalation or negative sentiment. |
| `app/Services/Crm/ToChaseService.php` | Identifies high-value pending sales conversations requiring proactive customer outreach. |
| `app/Services/Crm/BroadcastService.php` | Dispatches rate-limited bulk campaigns via Meta Cloud API or SMS. |
| `app/Services/Crm/LeadConversionService.php` | Converts qualified leads into customer accounts and sales orders. |
| `app/Services/Crm/SalesCartService.php` | Creates shareable one-click checkout cart links inside chat conversations. |
| `app/Services/Crm/SalesFollowUpService.php` | Schedules and executes automated sales follow-up cadences. |
| `app/Services/Crm/SalesQualificationService.php` | Evaluates buyer intent from chat transcripts and tags potential leads. |
| `app/Services/Crm/SalesReplyRenderer.php` | Formats product cards, variant options, and price breakdowns for chat rendering. |
| `app/Services/Crm/ProductCardService.php` | Prepares catalog product card payloads for WhatsApp and Messenger formats. |
| `app/Services/Crm/ContactSuppressionService.php` | Checks customer opt-out status to prevent sending unconsented marketing messages. |
| `app/Services/Crm/AiLlmClient.php` | Low-level client communicating with Gemini LLM endpoints for chat completions. |
| `app/Services/Crm/AiVisionDescriber.php` | Analyzes customer-submitted photos using vision AI to recognize product inquiries. |
| `app/Services/Crm/AiVoiceTranscriber.php` | Transcribes incoming audio voice notes to text using audio speech models. |
| `app/Services/WhatsAppWeb/WhatsAppWebBridgeClient.php` | Communicates with the local Node.js `whatsapp-bridge` service for QR session state. |
| `app/Services/WhatsAppWeb/WhatsAppWebSettingsService.php` | Manages rate limits, pauses, and safety toggles for QR-linked WhatsApp channels. |
| `app/Services/WhatsAppWeb/WhatsAppWebArchiveService.php` | Ingests and stores message history and media attachments from WhatsApp Web sessions. |
| `app/Services/SmsTemplateService.php` | Parses template tags and dispatches SMS via configured bulk SMS gateways. |
| `app/Services/OrderStatusSmsService.php` | Formats and sends automated parcel tracking SMS messages to customers. |
| `app/Services/SmsLogService.php` | Writes the SMS log (queued / sent / failed / skipped + reason), counts messages sent without a row of their own (`other()`, called by `StorefrontNotificationService::sendSms()`) and messages stuck for lack of a queue worker. |
| `app/Services/SmsUsageService.php` | The SMS dashboard's numbers from the SMS log: counts by result, by kind and by day, SMS parts used and the estimated cost. |
| `app/Services/SmsBalanceService.php` | Reads the BulkSMSBD balance (`/api/getBalanceApi`, host taken from the saved gateway URL), caches it five minutes, and gives the gateway's own site as the recharge link (the gateway has no recharge, report or price API). |
| `app/Support/SmsSegments.php` | Counts the SMS parts of a text (GSM 7-bit 160/153, Unicode and Bangla 70/67). |
| `app/Services/EmailLogService.php` | Writes the email log and turns a mail server's error text into a plain reason (wrong login, unverified address, limit, unreachable). |
| `app/Services/TelegramComplaintService.php` | Posts urgent customer complaints to private internal Telegram staff groups. |
| `app/Services/FirebaseHttpV1Sender.php` | Dispatches push notifications via Firebase Cloud Messaging (FCM) HTTP v1 API. |

### Filament Clusters, Resources & Pages
| Path | What it does |
| --- | --- |
| `app/Filament/Clusters/Crm.php` | Navigation cluster grouping Leads, Inbox, Channels, Broadcasts, and Automation. |
| `app/Filament/Resources/Leads/LeadResource.php` | Sales lead management, pipeline stages, activity logging, and order conversion. |
| `app/Filament/Resources/ConversationChannels/ConversationChannelResource.php` | Channel configuration (Cloud API, Web QR, Messenger, Telegram, SMS). |
| `app/Filament/Resources/Broadcasts/BroadcastResource.php` | Marketing broadcast campaign builder with audience filters and scheduling. |
| `app/Filament/Resources/QuickReplies/QuickReplyResource.php` | Canned response snippet manager with keyboard shortcuts. |
| `app/Filament/Pages/Inbox.php` | Live unified messaging inbox for real-time customer communication. |
| `app/Filament/Pages/AgentDesk.php` | Dedicated queue view for human agents managing assigned chats and order requests. |
| `app/Filament/Pages/NeedsAttention.php` | Prioritized inbox view showing frustrated customers and unhandled questions. |
| `app/Filament/Pages/ToChase.php` | Prospect chasing queue tracking customers who showed buying interest without ordering. |
| `app/Filament/Pages/UnansweredQuestions.php` | Shows questions where the AI had no answer, allowing one-click FAQ creation. |
| `app/Filament/Pages/AiInboxReport.php` | Analytics dashboard evaluating AI reply quality, deflection rates, and handovers. |
| `app/Filament/Pages/SmsDashboard.php` | Settings → SMS Dashboard: SMS sent / refused / skipped, parts, estimated cost (price per SMS is set on Integrations → SMS Gateway), the BulkSMSBD balance with a low-balance warning and a recharge link. Needs the manage-settings permission. |
| `app/Filament/Pages/SalesAutomation.php` | Automation rules manager for follow-up triggers and inactivity timeouts. |
| `app/Filament/Pages/SalesFollowUps.php` | Monitor for active and completed automated sales follow-up sequences. |
| `app/Filament/Pages/ConnectWhatsAppBusinessApp.php` | Embedded Meta Cloud API onboarding wizard for coexisting official WhatsApp channels. |
| `app/Filament/Pages/WhatsAppWebLogin.php` | Displays live QR code for scanning and linking WhatsApp Web Baileys bridge. |
| `app/Filament/Pages/SmsTemplates.php` | Admin editor for system notification SMS templates. |
| `app/Filament/Resources/SmsLogs/SmsLogResource.php` | SMS log table (a tab on Settings → Logs, no sidebar entry of its own): every order SMS and why it was or was not sent; one-column phone layout. |
| `app/Filament/Pages/Logs.php` | Settings → Logs: one page, one tab per log (SMS, audit, courier status/webhooks, Meta events, deploy errors, mobile crashes), each shown only to people who may open that log. |
| `app/Livewire/LogTable.php` | Embeds one log resource's own table inside a Logs tab (View buttons become links to the resource's page). |
| `app/Support/LogCatalog.php` | The list of logs on the Logs page and who may see each (the log resource's `canViewAny()`). |
| `app/Filament/Resources/EmailLogs/EmailLogResource.php` | Email log table (a tab on Settings → Logs, no sidebar entry of its own): every email and whether it was sent, failed or not sent, and why. |
| `app/Filament/Pages/OrderWhatsAppCard.php` | Settings → Order WhatsApp card: switch on the automatic order card, pick the official channel and approved template. |
| `app/Filament/Pages/PushNotificationSettings.php` | Configures Firebase Cloud Messaging credentials and web push permissions. |

### Controllers
| Path | What it does |
| --- | --- |
| `app/Http/Controllers/MetaWebhookController.php` | Receives inbound WhatsApp Cloud and Messenger webhook events from Meta. |
| `app/Http/Controllers/WhatsAppWebWebhookController.php` | Ingests message and sync events pushed by the local `whatsapp-bridge` service. |
| `app/Http/Controllers/Admin/ConversationMediaController.php` | Securely serves downloaded voice notes, images, and documents to admin users. |
| `app/Http/Controllers/Admin/PushDeviceController.php` | Registers and revokes browser FCM device tokens. |
| `app/Http/Controllers/FirebaseServiceWorkerController.php` | Serves dynamic JavaScript service worker for Firebase background push notifications. |

### Jobs, Commands & Schedule
| Path | What it does |
| --- | --- |
| `app/Jobs/AiAutoReplyJob.php` | Queued job executing LLM reasoning and sending AI responses to customer chats. |
| `app/Jobs/StoreIncomingMessageJob.php` | Ingests, normalizes, and attaches inbound messages to conversation threads. |
| `app/Jobs/ImportWhatsAppWebMessagesJob.php` | Batch processes historical chat logs retrieved from linked WhatsApp Web sessions. |
| `app/Jobs/SyncWhatsAppWebContactsJob.php` | Synchronizes contact names and numbers from the linked phone's address book. |
| `app/Jobs/RefreshWhatsAppWebNamesJob.php` | Updates push names and business profiles for linked WhatsApp contacts. |
| `app/Jobs/RefreshMessengerProfileJob.php` | Fetches Facebook user profiles and avatar URLs from Meta Graph API. |
| `app/Jobs/MarkConversationReadJob.php` | Sends read receipts back to external messaging platforms. |
| `app/Jobs/DownloadConversationMediaJob.php` | Downloads voice notes, images, and videos from carrier servers to local disk. |
| `app/Jobs/ImportConversationHistoryJob.php` | Imports past chat archives during initial channel onboarding. |
| `app/Jobs/SendBroadcastJob.php` | Dispatches broadcast messages to recipient batches with throttling. |
| `app/Console/Commands/ProcessSalesFollowUps.php` | Scheduled command (every 5 min) triggering scheduled sales outreach messages. |
| `app/Console/Commands/RevertConversationsToAi.php` | Scheduled command (every min) returning idle manual chats back to AI mode. |
| `app/Console/Commands/SendLeadFollowUpReminders.php` | Scheduled command (every 15 min) alerting staff of overdue lead follow-ups. |

### Views
| Path | What it does |
| --- | --- |
| `resources/views/filament/pages/inbox.blade.php` | Real-time chat inbox template with message feed, composer, and customer sidebar. |
| `resources/views/filament/pages/agent-desk.blade.php` | Streamlined operational desk view for human chat agents. |
| `resources/views/filament/pages/to-chase.blade.php` | Lead follow-up queue interface. |
| `resources/views/filament/pages/sales-automation.blade.php` | Multi-tab sales automation and attention queue template. |
| `resources/views/filament/pages/ai-inbox-report.blade.php` | Analytics dashboard view for AI customer engagement metrics. |
| `resources/views/filament/pages/whats-app-web-login.blade.php` | QR code scanning screen for linking the Baileys bridge. |
| `resources/views/filament/pages/connect-whats-app-business-app.blade.php` | Meta Cloud API Embedded Signup wizard view. |
| `resources/views/filament/pages/sms-templates.blade.php` | SMS notification template editor view. |
| `resources/views/filament/pages/push-notification-settings.blade.php` | Firebase push notification configuration view. |

### Migrations (name pattern only)
- Find a table's migrations by searching `database/migrations/` for the table name: several tables are created together in one migration, so the file name does not always match the table.
- `database/migrations/*create_sms_templates_table.php`
- `database/migrations/*create_sms_logs_table.php`
- `database/migrations/*create_email_logs_table.php`

### Tests
| Path | What it does |
| --- | --- |
| `tests/Feature/InboxPageTest.php` | Tests inbox message loading, channel switching, and message sending. |
| `tests/Feature/ConversationIngestTest.php` | Tests inbound webhook processing and conversation thread creation. |
| `tests/Feature/ConversationModeTest.php` | Tests automatic AI-to-manual switching when staff responds, and timeout reversion. |
| `tests/Feature/CrmSalesAutomationTest.php` | Tests automated sales follow-up cadences and cart link generation. |
| `tests/Feature/BroadcastTest.php` | Tests marketing campaign creation, audience filtering, and throttled sending. |
| `tests/Feature/LeadConversionTest.php` | Tests converting leads into customer accounts and sales orders. |
| `tests/Feature/EmailLogTest.php` | Tests the email log: sent / failed / not sent rows, reason mapping, no code stored, company isolation and the page. |
| `tests/Feature/LeadFollowUpReminderTest.php` | Tests scheduled staff notification alerts for pending lead activities. |
| `tests/Feature/WhatsAppWebArchiveTest.php` | Tests message archiving and media downloading from the Baileys bridge. |
| `tests/Feature/AiInboxReportTest.php` | Tests AI deflection rate calculations and inbox health metrics. |

## Routes and panels
- **Panels**:
  - `admin` panel:
    - CRM Cluster (`admin/crm/*`): Leads, Inbox, Agent Desk, Needs Attention, To Chase, Unanswered Questions, AI Inbox Report, Conversation Channels, Broadcasts, Quick Replies, Sales Automation, Sales Follow-Ups, SMS Templates, Push Notification Settings.
- **Webhook & Media Routes**:
  - `GET|HEAD webhooks/meta` (name `webhooks.meta.verify`) -> `App\Http\Controllers\MetaWebhookController@verify`
  - `POST webhooks/meta` (name `webhooks.meta.handle`) -> `App\Http\Controllers\MetaWebhookController@handle`
  - `POST webhooks/whatsapp-web/{channel}` (name `webhooks.whatsapp-web.handle`) -> `App\Http\Controllers\WhatsAppWebWebhookController@handle`
  - `POST webhooks/whatsapp-web/{channel}/media` (name `webhooks.whatsapp-web.media`) -> `App\Http\Controllers\WhatsAppWebWebhookController@media`
  - `GET|HEAD admin/conversation-messages/{message}/media` (name `conversation-messages.media`) -> `App\Http\Controllers\Admin\ConversationMediaController`
  - `POST admin/push-devices` (name `admin.push-devices.store`) -> `App\Http\Controllers\Admin\PushDeviceController@store`
  - `DELETE admin/push-devices` (name `admin.push-devices.destroy`) -> `App\Http\Controllers\Admin\PushDeviceController@destroy`
  - `GET|HEAD firebase-messaging-sw.js` (name `firebase-messaging-sw`) -> `App\Http\Controllers\FirebaseServiceWorkerController`

## Data
- `leads`: Sales lead database. Key columns: `id`, `company_id`, `name`, `phone`, `email`, `source`, `status`, `interest`, `estimated_value`, `assigned_to`, `next_follow_up_at`, `converted_customer_id`, `converted_order_id`, `note`, `created_by`, `follow_up_reminded_at`, `opted_out_at`, `qualification`, `temperature`, `temperature_locked`, `qualification_summary`, `external_reference`, `created_at`, `updated_at`.
- `lead_activities`: Interaction log. Key columns: `id`, `lead_id`, `user_id`, `type`, `note`, `next_action_at`, `created_at`, `updated_at`.
- `conversation_channels`: Messaging integrations. Key columns: `id`, `company_id`, `provider`, `connection_type`, `external_id`, `display_name`, `access_token`, `app_secret`, `verify_token`, `waba_id`, `auto_create_leads`, `is_active`, `web_status`, `web_phone`, `web_connected_at`, `web_disconnected_at`, `web_history_synced_at`, `created_at`, `updated_at`.
- `conversations`: Chat threads. Key columns: `id`, `company_id`, `channel_id`, `provider`, `external_contact_id`, `contact_name`, `contact_phone`, `lead_id`, `customer_id`, `status`, `assigned_to`, `last_message_at`, `unread_count`, `entry_point`, `ad_referral_id`, `ai_enabled`, `human_handled_until`, `automation_mode`, `handover_reason`, `mode_changed_at`, `mode_changed_by`, `is_staff_test`, `created_at`, `updated_at`.
- `conversation_messages`: Chat messages. Key columns: `id`, `conversation_id`, `direction`, `type`, `body`, `media_path`, `media_mime`, `external_message_id`, `delivery_status`, `sent_by`, `raw_payload`, `sent_at`, `generated_by`, `ai_confidence`, `ai_meta` (JSON), `delivery_channel`, `revoked_at`, `created_at`, `updated_at`.
- `whatsapp_contacts`: Synced WhatsApp directory. Key columns: `id`, `company_id`, `channel_id`, `jid`, `lid`, `phone`, `name`, `push_name`, `verified_name`, `customer_id`, `lead_id`, `first_seen_at`, `last_seen_at`, `created_at`, `updated_at`.
- `broadcasts`: Campaign broadcasts. Key columns: `id`, `company_id`, `name`, `audience_type`, `lead_status_filter`, `lead_source_filter`, `channel`, `whatsapp_channel_id`, `whatsapp_template_name`, `whatsapp_template_language`, `sms_body`, `status`, `recipients_count`, `sent_count`, `failed_count`, `created_by`, `queued_at`, `completed_at`, `messenger_channel_id`, `messenger_text`, `tested_hash`, `tested_at`, `created_at`, `updated_at`.
- `email_logs`: One row per email the system tries to send. Key columns: `id`, `company_id`, `kind`, `recipient`, `via` (`smtp:<host>` or `server:<mailer>`), `status` (`sent`, `failed`, `skipped`), `reason`, `detail` (the mail server's own answer), `created_at`, `updated_at`. The message text is never stored.

## Rules that matter
- **SMS counting and the dashboard**: every SMS that leaves through `StorefrontNotificationService` is counted. Order SMS and the test SMS go through `sendSmsDetailed()` and write their own row (with text); everything else goes through `sendSms()`, which adds a `kind = other` row without the text, so a login code is never stored. "Sent" means the gateway accepted it: BulkSMSBD sends no delivery reports. The cost on the dashboard is parts × the owner's `sms_price_per_sms` (stored with the other SMS keys in `notification_credentials`; blank means no cost is shown), an estimate. Gateway errors that name the request URL are passed through `StorefrontNotificationService::scrubUrls()` before they are logged or shown, because the URL carries the API key. A gateway URL template must contain `{phone}` and `{message}` (`StorefrontNotificationService::smsTemplateMissing()`): the Integrations URL box refuses to save one without them, `deliverSms()` does not call the gateway for one saved earlier (reason `bad_gateway_url`) and the dashboard warns. A URL copied from the gateway's example (`number=Receiver&message=TestSMS`) otherwise sends every SMS to a number called "Receiver" and the gateway answers 1001. The Integrations form builds that address from the API key and sender ID boxes (`App\Support\SmsGatewayUrl`: the standard BulkSMSBD address, `tidy()` of a pasted one, `preview()` for the live line). A site whose saved address cannot work (no `{phone}` / `{message}`) sends through the company's other working site (`StorefrontNotificationService::workingGateway()`, used by `sendSmsDetailed()` unless `ownGatewayOnly`, which the Integrations test button sets), and `SmsTemplateService::gatewayFor()` prefers a usable site: login codes on one site are not lost because the other site was fixed. Covered by `SmsDashboardTest` and `SmsGatewayUrlTest`.
- **WhatsApp Web (QR) Compliance Rules**: Follows strict policies documented in `docs/whatsapp-web-rules.md`:
  - **Permanent Archive**: Logging out, disconnecting, or account bans never deletes archived conversations, messages, or media files.
  - **Outbound Disabled by Default**: Sending via WhatsApp Web is off by default and requires explicit super admin opt-in acknowledging Meta's unofficial client terms.
  - **Inbound Reply Only**: The ERP may ONLY send messages in a conversation where the customer messaged first. It must never initiate unprompted chats to new numbers.
  - **No Automation or Bulk Sending**: Broadcasts, automated follow-up cadences, order-status SMS fallbacks, and AI auto-replies are strictly prohibited on QR-linked Web channels (must use official Cloud API).
  - **Pacing and Throttling**: Outgoing messages are dispatched sequentially with admin-configured pauses and hourly rate caps.
  - **Immediate Cease on Disconnect**: If the bridge reports `banned`, `logged_out`, or `replaced`, sending halts immediately without automatic retries.
  Enforced by `app/Services/WhatsAppWeb/WhatsAppWebBridgeClient.php` and `docs/whatsapp-web-rules.md`.
- **Conversation Mode Transitions**: Conversations start in `ai` mode. As soon as a human agent sends a message or claims the thread, the conversation transitions to `manual` mode and logs a `ConversationModeEvent`. Scheduled command `crm:revert-conversations` checks conversations every minute and automatically reverts idle manual chats back to `ai` mode after the configured inactivity threshold expires. Enforced by `app/Services/Crm/ConversationModeService.php` and `app/Console/Commands/RevertConversationsToAi.php`.
- **Attention Queue Triage**: Inbound messages are evaluated for risk factors: customer frustration or profanity flags `NeedsAttention`; buying intent flags `ToChase`; and unknown customer questions that lack RAG knowledge answers flag `UnansweredQuestions`. Enforced by `app/Services/Crm/AttentionQueueService.php`.
- **Multimodal Message Ingestion**: Voice notes sent by customers are transcribed to text using `AiVoiceTranscriber`, and photos are described using `AiVisionDescriber` before the conversation is handed to `AiReplyService` for response generation. Enforced by `app/Services/Crm/AiVoiceTranscriber.php` and `app/Services/Crm/AiVisionDescriber.php`.
- **Strict Opt-Out Suppression**: If a customer requests to opt out of marketing or their record has `opted_out_at` set, `ContactSuppressionService` blocks any scheduled broadcast or sales follow-up across all channels. Enforced by `app/Services/Crm/ContactSuppressionService.php`.

## Links to other modules
- **core-platform.md**: Multi-tenant isolation is enforced on conversations and leads via `BelongsToCompany` and `CompanyScope`.
- **catalog-inventory.md**: AI auto-replies query `Product` and `StockMovement` to verify stock availability before recommending items.
- **sales-orders-couriers.md**: Conversations generate checkout links via `SalesCartService` that convert directly into `Order` records; order status transitions trigger customer SMS updates via `OrderStatusSmsService`.
- **api-integrations.md**: External website contact forms create `Lead` records via `POST /api/v1/leads`.
- **ai-marketing-tools.md**: Marketing campaigns and ad creatives synchronize with broadcast audiences and lead tracking.

## Related docs
- `02_LEAD_CRM_MODULE_PLAN.md` — Original architecture plan for multi-channel CRM and lead management.
- `16_AI_INBOX_CONTROL_PLANE_PLAN.md` — Complete plan for the AI Inbox Control Plane, queues, and handover rules.
- `docs/whatsapp-web-rules.md` — Safety invariants, Meta legal constraints, and bridge operational rules.
- `docs/CRM_AI_SALES_AUDIT_2026-09-09.md` — Comprehensive sales qualification and conversation audit findings.
