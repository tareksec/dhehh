# WhatsApp Web (QR) channel rules

These rules apply to every WhatsApp channel with `connection_type = 'web'`:
numbers linked by QR code through the `whatsapp-bridge` service. Any agent or
developer working on this channel must follow them. The owner asked for them
on 2026-10-05, before sending from the ERP is built. The terms below were
re-checked word for word on 2026-10-06.

## What WhatsApp's terms say (checked 2026-10-06)

The quotes below are copied word for word from WhatsApp's own pages. Only
the clauses that matter for a QR-linked bridge are included; the full
documents are at the links.

### WhatsApp Terms for WhatsApp Business App (effective September 23, 2026)

<https://www.whatsapp.com/legal/WhatsApp-Terms-for-WhatsApp-Business-App>

> **Restrictions.** Except as otherwise permitted by WhatsApp in writing, Company must not directly, indirectly, or through automated or other means: (a) distribute, sell, resell, or rent our Business App Services to third parties; (b) distribute or make our Business App Services available over a network to be used by multiple devices at the same time, except as authorized through tools and configurations that we have expressly provided for your use via our Business App Services; and (c) copy, reproduce, distribute, publicly perform or display, modify, or make derivative works based upon all or portions of our Business App Services. Company must not directly, indirectly, or through automated or other means: (d) remove any proprietary rights notices or markings; (e) reverse engineer any aspect of our Business App Services or do anything that may discover source code; (f) scrape or extract data from our Business App Services; (g) develop or use any applications that interact with our Business App Services without our prior written consent; and (h) create software or APIs that function substantially the same as our Business App Services and offer them for use by third parties in an unauthorized manner on or utilizing the WhatsApp platform or our Business App Services.

> **Compliance with Laws and Regulations.** […] Company must also secure all necessary rights, consents, and permissions (for example, opt-in) to share its customers’ contact and other personal data with WhatsApp, and to communicate with its customers via the WhatsApp service using this information. […] Company must also honor and comply with all WhatsApp user requests to stop or opt-out of receiving certain or all types of WhatsApp messages from Company. WhatsApp users may block Company, mark Company’s messages as spam, or report Company’s actions or messages to us to notify us that Company is violating our terms and policies. WhatsApp will then take appropriate action, which could result in WhatsApp suspending or terminating Company’s use of our Business App Services.

> **Acceptable Use.** […] Company must not directly, indirectly, or through automated or other means: […] (e) collect information of or about other users in any impermissible or unauthorized manner; (f) use our Business App Services other than for their intended purpose or interfere with, disrupt, negatively affect, or inhibit other users; (g) damage, disable, overburden, or impair our Business App Services; (h) send, distribute, or post spam, unsolicited electronic communications, chain letters, pyramid schemes, or illegal or impermissible communications; […] (l) bypass, ignore, or circumvent instructions in our robots.txt file or any measures we employ to prevent or limit access to any part of our Business App Services, including content-filtering techniques; or (m) expose WhatsApp or others to any type of harm or liability.

> **Enforcement.** […] If we determine that you have breached these Business App Terms or are not complying with our policies, we have the right to limit, throttle, suspend, or terminate Company’s account, depending on the type and circumstances of the breach and within our reasonable discretion. In the event that we terminate Company’s account, Company will not create another WhatsApp business account without our express written permission.

> **12. Modifying and Terminating our Business App Services**
>
> We may modify, suspend, or terminate Company’s access to or use of our Business App Services and these Business App Terms at any time and for any reason, permissible by applicable law, including if we determine, in our sole discretion, that Company violates these Business App Terms, receives excessive negative feedback, or creates harm, risk, or possible legal exposure for us, our users, or others. […]
>
> Company may terminate these Business App Terms at any time for any reason by providing us written notice. Upon termination of these Business App Terms for any reason, Company must promptly discontinue all use of our Business App Services, and uninstall and destroy all copies of software provided by WhatsApp, and delete any user information Company obtained in breach of these Business App Terms. […]

### WhatsApp Terms of Service (effective January 4, 2021, WhatsApp LLC — users outside the European Region and UK)

<https://www.whatsapp.com/legal/terms-of-service>

> **Our Terms And Policies.** You must use our Services according to our Terms and posted policies. If you violate our Terms or policies, we may take action with respect to your account, including disabling or suspending your account and, if we do, you agree not to create another account without our permission. […]

> **Legal And Acceptable Use.** You must access and use our Services only for legal, authorized, and acceptable purposes. You will not use (or assist others in using) our Services in ways that: […] (e) involve sending illegal or impermissible communications such as bulk messaging, auto-messaging, auto-dialing, and the like; or (f) involve any non-personal use of our Services unless otherwise authorized by us.

> **Harm To WhatsApp Or Our Users.** You must not (or assist others to) directly, indirectly, through automated or other means, access, use, copy, adapt, modify, prepare derivative works based upon, distribute, license, sublicense, transfer, display, perform, or otherwise exploit our Services in impermissible or unauthorized manners, or in ways that burden, impair, or harm us, our Services, systems, our users, or others, including that you must not directly or through automated means: (a) reverse engineer, alter, modify, create derivative works from, decompile, or extract code from our Services; […] (c) gain or attempt to gain unauthorized access to our Services or systems; […] (f) collect information of or about our users in any impermissible or unauthorized manner; […] (h) distribute or make our Services available over a network where they could be used by multiple devices at the same time, except as authorized through tools we have expressly provided via our Services; (i) create software or APIs that function substantially the same as our Services and offer them for use by third parties in an unauthorized manner; […]

> We may modify, suspend, or terminate your access to or use of our Services anytime for any reason, such as if you violate the letter or spirit of our Terms or create harm, risk, or possible legal exposure for us, our users, or others. […]

### WhatsApp Messaging Guidelines (last updated September 23, 2026)

<https://www.whatsapp.com/legal/messaging-guidelines>

> Do not engage in adversarial behavior. This includes efforts to undermine WhatsApp integrity systems, abuse reporting tools, compromise user accounts, scrape data, or use unofficial clients, bulk messaging, auto-messaging, auto-dialing, or automation to harm WhatsApp or our users.

### WhatsApp Business Messaging Policy (last updated September 23, 2026)

<https://whatsappbusiness.com/policy/> — it covers "The WhatsApp Business App" and "The WhatsApp Business Platform".

> You may only contact people on WhatsApp if: (a) they have given you their mobile phone number or username; and (b) you have received opt-in permission from the recipient confirming that they wish to receive subsequent messages or calls from you.

> You must respect all requests (either on or off WhatsApp) by a person to block, discontinue, or otherwise opt out of communications from you via WhatsApp, including removing that person from your contacts list. Do not confuse, deceive, defraud, mislead, spam, or surprise people with your communications.

> If you use or operate a service which utilizes WhatsApp in violation of our terms or policies, such as messaging people at scale in an unauthorized manner, we have the right to limit or remove your access to WhatsApp for Business.

> If we terminate your account for violations of our terms of service, the Business Messaging Policy, or the WhatsApp Messaging Guidelines, we may prohibit you and your organization from all future use of WhatsApp products and services.

### Not quoted

- WhatsApp Help Center, "About unofficial apps"
  (<https://faq.whatsapp.com/1217634902127718>): the article body loads only
  in a browser and could not be read from the cloud session, so it is not
  quoted here.

### What this means

- The bridge is an unofficial WhatsApp Web client (Baileys). Using it falls
  under Business App Restrictions (f) "scrape or extract data" and (g)
  "develop or use any applications that interact with our Business App
  Services without our prior written consent", and the Messaging Guidelines
  name "use unofficial clients" directly. This is true even when the bridge
  only reads and saves messages.
- WhatsApp can limit, throttle, suspend or terminate the account, and after
  a termination the business may not create another WhatsApp business
  account without written permission.
- Sending from the ERP adds the "auto-messaging" and "unsolicited
  communications" risks, and makes detection more likely.
- **Data kept after a ban.** Section 12 says that when the Business App
  Terms end, the company must "delete any user information Company obtained
  in breach of these Business App Terms". Chats archived through the bridge
  may count as obtained in breach. Rule 1 below keeps the archive because
  the owner asked for it; whether to delete anything after a termination is
  the owner's legal decision, not something the code does by itself.
- The official way to send from the ERP is the Meta Cloud API
  (**Connect WhatsApp App** / Coexistence, or a Cloud API channel).

## Rules

1. **The archive is permanent.** Logging out, deleting the channel or a ban
   never deletes saved conversations, messages, media or `whatsapp_contacts`
   rows. "Delete for everyone" only sets `revoked_at`. No code path may delete
   archive data because of the WhatsApp connection state.
   A channel moved to **Trash** keeps everything saved and **stops archiving**
   (the bridge's webhook answers "not found") until it is restored; messages
   that arrive in between are not saved. Owner decision 2026-11-03 (plan
   question 9: keep it as it is).
2. **Sending is off by default.** It is enabled per channel by a super admin,
   who first confirms they have read the risk above. Until enabled, the
   channel is read-only (the current behaviour).
3. **Reply only to customers who messaged first.** The ERP may send only in a
   conversation that already has an incoming message from that customer.
   It may never start a chat with a new number.
4. **No bulk or automated sending.** No broadcasts, campaigns, follow-up
   reminders, abandoned-cart or order-status notifications, and no AI
   auto-reply through a web channel. Only a staff member pressing **Send** on
   one conversation sends a message. Broadcasts and automation stay on the
   official Cloud API.
5. **Honour opt-out.** When a customer asks to stop, or the linked Lead or
   Customer is opted out (`opted_out_at`), the ERP must not send to them on
   this channel.
6. **One message at a time, with a limit.** Outgoing messages are sent one
   after another with a pause between them, and a per-channel limit on how
   many can be sent per minute and per hour. The pause and limits are
   admin-set values on the channel page; the owner chooses the numbers. They
   are not hardcoded.
7. **Stop on trouble.** If the bridge reports `banned`, `logged_out` or
   `replaced`, or WhatsApp rejects messages, sending stops at once and the
   page shows why. It does not retry automatically.
8. **Everything sent is saved and attributed.** Every outgoing message,
   including media, is stored in the conversation with the staff member who
   sent it, exactly like received messages.
9. **No SMS fallback.** A failed web-channel send stays failed. It is never
   silently sent as an SMS instead.
