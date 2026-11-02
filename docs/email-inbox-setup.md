# Email inbox: receiving mail through Cloudflare (setup)

How mail sent to `info@yourdomain` gets into the ERP's Email inbox. Plan and decisions: `docs/email-inbox-plan.md`.
The ERP side of the connection (the mailbox, its forwarding address, the test mail) is **Settings → Email**
(built in step 6 of the plan); until then a mailbox can only be made by a developer.

## How it works

```
sender --> Cloudflare Email Routing --> Email Worker --POST raw message--> ERP  webhooks/email/{mailbox}/{token}
```

- Cloudflare Email Routing receives the mail for your domain (it needs the domain's DNS on Cloudflare; turning
  it on replaces the domain's MX records, so do not turn it on for a domain whose mailboxes still live elsewhere).
- The Worker (`docs/email-inbox/cloudflare-email-worker.js`, about 40 lines, no npm packages) posts the **raw
  message** to the mailbox's forwarding address and nothing else.
- The ERP reads the message (names, Bangla text, attachments), joins it to its conversation, matches the sender to a
  customer, and shows it in Email.
- Sending is separate: replies go out through the site's SMTP account (Integrations → Email Setup), for example
  Cloudflare Email Sending, Brevo or Amazon SES.

## Steps on Cloudflare (once per domain)

1. **Email → Email Routing** of the domain: turn it on and let Cloudflare add its MX and SPF records. A domain that
   already receives mail somewhere else: stop here, do not switch it.
2. **Add a fallback destination** under Destination addresses (for example the owner's Gmail) and verify it from
   the mail Cloudflare sends there. It is where a message goes if the ERP cannot take it, so no mail is lost.
3. **Workers & Pages → Create → Worker.** Name it `erp-email-inbox`, press **Edit code**, replace the code with
   the contents of `docs/email-inbox/cloudflare-email-worker.js`, **Deploy**.
4. In the Worker's **Settings → Variables and Secrets** add:
   - `INBOX_URL` as a **Secret**: the forwarding address of the mailbox, copied from the ERP.
   - `FALLBACK` as a **Text** variable: the verified fallback address from step 2.
5. **Email Routing → Routing rules → Custom addresses → Create address:** `info` → action **Send to a Worker** →
   `erp-email-inbox`. Repeat for each address (`sales`, `support`...), or use a catch-all rule.
6. Send a test mail to the address from another account; it appears in the ERP's Email within a few seconds.

Without `FALLBACK` the Worker tells the sender the message did not arrive when the ERP is down, instead of losing
it quietly. With it, the message lands in the fallback mailbox and nobody is told anything went wrong.

## What the ERP accepts (the contract)

`POST /webhooks/email/{mailbox}/{token}`

| | |
|---|---|
| Body | the raw message (`message/rfc822`), up to **15 MB** (the web server's request limit is 16 MB) |
| Header `X-Envelope-From` | optional; used only when the message has no From header |
| `200 {"ok":true,"duplicate":false}` | kept |
| `200 {"ok":true,"duplicate":true}` | already held (the same Message-ID): nothing changed |
| `404` | unknown mailbox or wrong token (the same answer for both) |
| `409 mailbox_not_receiving` | the token is right but the mailbox is switched off or send-only |
| `413 message_too_large` | over 15 MB |
| `422 unreadable_message` | not a readable email message |

The token is a secret in the address: anyone who has the address can put mail into that mailbox, so treat it like a
password. A new token (Settings → Email) stops the old address at once. Only a hash of it is stored.

## Limits and notes

- The Worker script was run against stand-in messages (success, ERP error with and without fallback, too large)
  but not against Cloudflare itself: check step 6 above after deploying.
- Plain mail and mail with files up to 15 MB are kept. `<style>` blocks in HTML mail are removed (inline styles
  stay), so some newsletters look plainer than in Gmail.
- No read tracking, no auto-reply and no pictures loaded from other servers unless the mailbox allows it.
