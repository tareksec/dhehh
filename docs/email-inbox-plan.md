# Email inbox in the ERP — plan (owner request 2026-11-02)

The owner approved the design on the "ERP Email Inbox Design" canvas (desktop inbox with reading pane and customer
panel, compose window, mailbox settings, the any-provider Email delivery page, and four phone screens). This file is
the build plan. The code wins when it disagrees with this file — fix the file.

## Goal

A separate Gmail-like **Email** page in the CRM cluster, next to the chat Inbox, so staff read and answer customer
email without leaving the ERP, with the customer, their orders and their WhatsApp chat beside the message. The
Amazon SES usage page becomes a provider-neutral **Email delivery** page.

## Phase 1 (this build)

- Folders: Inbox, Starred, Sent, Drafts, Spam, Trash, All mail. Unread counts.
- Labels (coloured, per company), stars, mark read / unread, archive, spam, delete, bulk actions on selected rows.
- Search (sender, recipient, subject, text) with the quick filters Unread, Needs reply, Mine, Has files.
- Conversation view: messages of one thread, oldest collapsed, newest open, attachments, internal notes.
- Compose, reply, reply all, forward: To / Cc / Bcc, rich text, attachments, signature, draft saved by itself,
  undo send, Quick replies (the existing Quick Replies list).
- Customer panel: the customer matched by the sender's email, orders, dues, the WhatsApp chat link, Create order,
  Create lead, Link to an order.
- Assign a staff member and an Open / Pending / Closed status.
- Several mailboxes per company with a switcher; mailbox settings (connection, signature, labels, behaviour).
- Email delivery page: works for any SMTP provider (see Step 1).

## Phase 2 (owner asked for "the next step" on 2026-11-02)

Built one step at a time, each its own commit (see "Phase 2 steps" below): snooze, a Trash that empties itself,
scheduled send, rules (auto label / assign), address suggestions, "Suggest reply" (a draft for staff to review, never
sent by the system) and IMAP checking for mailboxes that cannot forward. Left for later, on the owner's word: SES
inbound (the owner does not use SES now) and a bottom reading pane.

## Decisions

- **Receiving.** The provider forwards each incoming mail to the ERP: Cloudflare Email Routing runs a tiny Worker that
  POSTs the **raw message** (`message/rfc822`) to `POST /webhooks/email/{mailbox}/{token}`. Raw MIME keeps the Worker
  a dozen lines with no npm packages (pasteable into the Cloudflare dashboard) and lets the ERP parse once, the same
  way for any future source (IMAP, SES inbound). The token is a per-mailbox secret, stored hashed; a new token
  invalidates the old one. The address is throttled, size-capped and idempotent on the message's own `Message-ID`.
- **Parsing.** `zbateson/mail-mime-parser` (pure PHP). Threads are joined by `In-Reply-To` / `References`, else by the
  same cleaned subject with the same other party in the last 14 days.
- **Showing HTML mail.** Sanitised with `symfony/html-sanitizer` (already in vendor), shown in a sandboxed iframe
  with a content-security-policy that blocks scripts, forms and remote images (the "Load remote images" switch
  stays off by default). Plain text mail is escaped.
- **Sending.** Through the site's own SMTP account (Integrations → Email Setup) via `StorefrontNotificationService`,
  so every provider that works for order emails works for the inbox. Each recipient gets an Email Log row
  (`kind = staff_reply`). `In-Reply-To` / `References` / `Message-ID` are set so replies thread in the customer's mail
  app. The delivery status (Delivered / Bounced) is shown only when the provider reports back (Amazon SES today);
  every other provider shows "Sent".
- **Company isolation.** Every new model uses `BelongsToCompany` + `CompanyScope`. A mailbox belongs to one company
  (and optionally to one site of it for the SMTP account). The two ZamZam companies therefore have separate
  mailboxes and separate inboxes; staff switch company to switch between them.
- **Attachments** are stored on the private disk, served only to signed-in staff of the same company through a
  controller (no public URL). Limit per mail 25 MiB (the provider's own limit).
- **No read tracking** (no tracking pixels), no auto-reply, no AI sending.

## Data model (all company-owned)

| Table | Holds |
| --- | --- |
| `email_mailboxes` | address, name, site (`storefront_setting_id`), receive mode, token hash, last received, signature, behaviour settings |
| `email_threads` | mailbox, subject, snippet, folder, unread, starred, assignee, status, customer, lead, order, last message time |
| `email_messages` | thread, direction, kind (message / note / draft), RFC ids, from / to / cc / bcc, subject, text, sanitised html, sent time, sender user, Email Log link |
| `email_attachments` | message, file name, type, size, private path, content id |
| `email_labels`, `email_label_thread` | label name and colour, label on a thread |

## Steps and commits

Each step ends green (its tests, bilingual keys, code map, `CHANGELOG.md`, `UPDATE_NOTES.md`) and is committed and
pushed to the feature branch `ccr-3799b463-p4pv7n`. Nothing goes to `main` without the owner's approval.

| Step | What | Commit | Status |
| --- | --- | --- | --- |
| 1 | **Email delivery page for any provider** (`EmailProvider`, `EmailUsageService`, Settings → Email delivery) | `131d66a` | done |
| 2 | **Data model and inbound pipeline** (tables, MIME parser, sanitiser, threading, customer matching, webhook, Cloudflare Worker, setup guide) | `4af2d2c` | done |
| 3 | **Outbound mail** (`OutboundEmailService`: new / reply / reply all / forward, drafts, undo send, `email:send-due`, Email Log rows) | `d080370` | done |
| 4 | **Email page** (folders, list, conversation, labels, stars, bulk actions with undo, search, quick filters, mailbox switcher, phone layout, sandboxed HTML, file downloads) | `deb331a` | done |
| 5a | **Writing in the page** (compose window, autosaved drafts, files, Quick replies, Send with the undo bar, send again, notes) | `cfc196b` | done |
| 5b | **Who the conversation is with** (assignee, status, customer panel, Create order / Create lead / Link to order) | `631a60f` | done |
| 6 | **Settings → Email inbox** (add mailbox, forwarding address shown once, new token, test mail, setup steps, signature, behaviour, labels; new-mail alerts) | `9290766` | done |
| 7 | **Finish** (this document, code map, release notes, `npm run build`, render check at 390 and 1440 px light and dark, full `php artisan test`) | this commit | done |

### Phase 2 steps

| Step | What | Commit | Status |
| --- | --- | --- | --- |
| 8 | **Snooze and a Trash that empties itself** (Snoozed view, wake-up and bell, `trash_days` setting, `email:wake-snoozed`, `email:purge-trash`) | `4f0746d` (on `main`) | done |
| 9 | **Scheduled send** (Send later in the compose window, Scheduled view, cancel / send now by the author) | this commit | done |
| 10 | **Rules and address suggestions** (auto label / assign / archive by sender or subject; To / Cc suggestions from past contacts and customers) | | planned |
| 11 | **Suggest reply** (a draft for staff to edit; never sent by the system; uses the ERP's existing AI setup) | | planned |
| 12 | **IMAP checking** (a mailbox that cannot forward is read from its IMAP account) | | planned |

### What differs from the first plan

- Drafts are private to the person writing them; Sent, Drafts, Starred and All mail are views, not folders.
- Undo send is a countdown bar in the page (default 10 seconds, 0 to 60 in Settings); the page sends when it ends and
  `email:send-due` (every minute) is the backup for a closed page.
- A lead made from an email needs a phone number (the CRM works from it); the lead source **Email** was added.
- Create order opens the existing New Order page with the customer picked (`?customer=ID`); it does not make the order.
- New mail alerts staff by default (bell and push); it is a setting of each mailbox.
- Not built (Phase 2): snooze, scheduled send, rules, Suggest reply, IMAP checking, Amazon SES inbound, address
  suggestions in To / Cc, a bottom reading pane, a Trash that empties itself after 30 days.

## What the owner has to do outside the ERP

- Turn on Cloudflare Email Routing for the domain, create the Worker from the guide in `docs/email-inbox-setup.md`
  and paste the mailbox's forwarding address into it.
- Choose and set up the sending provider in Integrations → Email Setup (Cloudflare Email Sending, Brevo, SES ...).
