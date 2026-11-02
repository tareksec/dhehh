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

## Phase 2 (not in this build)

Snooze, scheduled send, rules (auto label / assign), "Suggest reply" (a draft for staff to review, never sent by the
system), IMAP checking for mailboxes that cannot forward, SES inbound.

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
pushed to the feature branch. Nothing goes to `main` without the owner's approval.

1. **Email delivery page for any provider.** A `EmailDeliveryService` (provider detected from the SMTP host, usage
   counted from the Email Log for any host, generic free allowance and price settings with the old `ses_*` values as
   fallback), a Settings → Email delivery page with the approved design, the usage section removed from the Amazon
   SES page (which keeps only the SES tools), tests.
2. **Data model and inbound pipeline.** Migrations, models, MIME parser service, threading, customer matching,
   attachment storage, the inbound webhook, the Cloudflare Worker script and a setup guide, fixtures and tests.
3. **Outbound mail.** The compose service (new, reply, reply all, forward), drafts, signature, attachments,
   threading headers, Email Log rows, undo-send window, tests.
4. **Email page.** Folders, list, conversation view, labels, stars, bulk actions, search, mailbox switcher, the
   phone layout, CSS (`.zz-mail-*`), navigation badge, tests.
5. **Compose window, customer panel, assignment.** Rich text, attachments, Quick replies, customer panel with its
   buttons, assignee, status, internal notes, tests.
6. **Mailbox settings.** Connection (token, test mail, status), signature, labels, behaviour, tests.
7. **Finish.** Code map, release notes, `npm run build`, render check at 390 and 1440 px (light and dark), full
   `php artisan test`, push the feature branch.

## What the owner has to do outside the ERP

- Turn on Cloudflare Email Routing for the domain, create the Worker from the guide in `docs/email-inbox-setup.md`
  and paste the mailbox's forwarding address into it.
- Choose and set up the sending provider in Integrations → Email Setup (Cloudflare Email Sending, Brevo, SES ...).
