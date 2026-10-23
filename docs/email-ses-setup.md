# Sending the ERP's email through Amazon SES

The ERP sends email through an SMTP account saved **per site** (each company's Blade storefront and Next.js site has its own row). Amazon SES has an SMTP interface, so it plugs into the same place as any other SMTP provider: **Settings → Integrations → Email Setup**. Nothing is installed or configured on the server for it.

Email the ERP sends this way: customer login codes (storefront), reseller password-reset links, and — from the company's main site — investor and IMS emails (invitations, codes, notices, the owner's daily digest). If a site has no SMTP host saved, these fall back to the server's own mailer (`MAIL_*` in the environment; see the last section).

## 1. In AWS SES (once per region)

1. Use the region the domains were verified in. SES identities and SMTP credentials belong to one region; the ERP must use that same region.
2. Each domain you send from must be a verified identity (the domains you already have approved). The **From address** of a site must be on one of them, for example `orders@<your-domain>`.
3. Check the account is out of the SES **sandbox** (SES → Account dashboard). In the sandbox SES only delivers to addresses you verified yourself, so customers would not receive anything. Verifying a domain does not lift the sandbox; that is a separate "production access" request.
4. SES console → **SMTP settings** → **Create SMTP credentials**. Download them: the **SMTP user name** looks like `AKIA…` and the **SMTP password** is a long generated string. These are *not* your AWS login, and not the IAM secret access key either. One set works for every domain in that region, so the same pair can be entered on each site.

## 2. In the ERP (once per site)

1. **Settings → Integrations → Email Setup**. If the company has two sites, pick the site in the **Site** box first.
2. **Amazon SES region**: type the region (for example `ap-southeast-1`). The SMTP host (`email-smtp.<region>.amazonaws.com`), port `587` and TLS are filled in.
3. **SMTP username** / **SMTP password**: the pair from step 1.4. The password is stored encrypted.
4. **From address**: an address on a domain verified in SES for this site. This is required with SES, because the SMTP user name is not an email address. **From name** is the display name customers see.
5. **Save changes** (a reminder appears if there is no usable From address), then **Send test email** and open the inbox (check spam too).

The test email is sent through the saved settings and shows the mail server's own answer when it fails. Every email the ERP sends, the test ones included, is also written to the **Email** tab of **Settings → Logs** (see below):

| Answer | Usually means |
| --- | --- |
| `535 Authentication Credentials Invalid` | Wrong SMTP user name or password, or credentials created in another region than the host's. |
| `554 Message rejected: Email address is not verified` | The From address is not on a verified identity, or the account is still in the sandbox and the recipient is not verified. |
| Connection could not be established / timed out | The server cannot reach the SES host on that port. Check the host spelling and the server's outbound firewall; SES also accepts 465 (SSL) and 2587. |
| Throttling / daily quota exceeded | SES sending limits of the account; see SES → Account dashboard. |

Port 465 connects over SSL; any other port starts with TLS when the server offers it (the SMTP encryption box does not change this).

## 3. Email Log: was it sent, did it fail, and why

The **Email** tab of **Settings → Logs** lists every email the ERP tried to send for the company: when, what kind of message (customer login code, reseller password reset link, investor code or invitation, investor message, owner's daily digest, test email), who it went to, and the result:

- **Sent**: the mail server (SES) accepted the message. This alone does **not** prove the inbox received it: a bounce or a spam-folder delivery happens afterwards. With tracking on (section 4) the row later becomes **Delivered**, **Bounced** or **Marked as spam**.
- **Failed**: the mail server or the connection refused it. The **Why** column gives a plain reason (wrong SMTP user name or password, an email address not verified, sending limit reached, mail server unreachable, invalid address) followed by the mail server's own answer.
- **Not sent**: nothing was attempted or nothing left the server: no email account is set up for the site, or the server's own mailer only writes to a log file.

The text of a message is never stored, only who it went to and the result, so a login or reset code never sits in the log. The log can be filtered by result and by kind of message, and each company sees only its own rows.

## 4. Control SES from the dashboard (Settings → Amazon SES)

The page shows the state of the SES account and controls it through the SES API, so the AWS console is only needed once. It needs an AWS key of its own: in AWS **IAM** create a *separate* user for the dashboard (not the SMTP user: an SMTP password cannot be turned back into an API key), attach the policy shown on the page (**Which AWS permissions does the key need?**), create an access key for it and paste the access key ID, the secret and the region into step 1 of the page. The secret is stored encrypted and is never shown again.

What the page does:

- **Checklist and account:** whether the key works, sandbox or production, today's sending against the daily limit, Amazon's health rating of the account, and whether the site's From address is on a verified domain.
- **Domains and addresses:** add a domain (publish the three CNAME records the page shows at the place the domain's DNS is managed; verification follows within minutes to hours) or an address (click the link Amazon emails), and remove one.
- **Suppression list:** addresses Amazon refuses to send to after a bounce or a spam complaint; allow one again or block one by hand.
- **Bounce, complaint and delivery tracking:** **Turn tracking on** creates in your AWS account an SNS topic, an SES configuration set and a subscription that posts the reports to this ERP (`/webhooks/ses/...`, which only accepts messages with a valid Amazon signature for that topic). From then on the site's mail carries the configuration set's name and the Email Log shows **Delivered**, **Bounced** or **Marked as spam**. The site must be reachable on a public https address. **Turn tracking off** deletes what was created. If the set is deleted in the AWS console while tracking is on, sending fails with a message pointing here: turn tracking off and on again.

The page covers the SES account, not the sending itself: emails still go out through the SMTP account of section 2. Asking Amazon to lift the sandbox (production access) is still done in the AWS console (SES → Account dashboard), because Amazon wants a written use case.

## 5. Several companies on one AWS account

One AWS account can send for several companies: verify every company's domain in it once, and connect each company separately. SES identities, the SMTP login and the limits belong to the account; everything the ERP does with them is kept per company.

**In AWS (once for the whole account):** verify each company's domain (section 1), create one SMTP credential pair for the region (it works for every domain in that region), ask for production access (the sandbox is account-wide), and create one IAM user for the dashboard with the policy shown on **Settings → Amazon SES**.

**In the ERP, for each company** (pick the company in the top bar, then):

1. **Settings → Integrations → Email Setup** (pick the site if the company has two): the SES region, the same SMTP user name and password, a **From address on this company's own domain** (for example `orders@<that company's domain>`) and the company's **From name**.
2. **Settings → Amazon SES**: paste the AWS access key ID, secret and region (the same key for every company is fine; each company keeps its own copy, encrypted), **Save and check**, then **Turn tracking on**.
3. **Send test email** (Integrations → Email Setup) and look at **Settings → Logs → Email**.

What is separate for each company, and what is shared:

| Per company | Shared by every company on the account |
| --- | --- |
| The From address and name; the SMTP settings saved for its site | The sending limit, the send rate and the sandbox / production state |
| The Email Log (each company sees only its own rows) | Amazon's health rating of the account (one company's many bounces can put all of them under review) |
| Bounce / complaint tracking: its own SNS topic, SES configuration set and webhook, so a report always lands in the right company's log | The suppression list: an address that bounced for one company is refused for all |
| Its domains on the Amazon SES page, shown first | The list of domains: the other companies' domains are shown below, read-only for company staff (only a super admin can remove them) |

Keep each company sending only from its own domain, and clean up bad addresses (the Email Log shows them), because the reputation is shared. If a company must be fully isolated from the others, give it its own AWS account.

## 6. Server-wide fallback (optional)

Anything that is not tied to a site's own SMTP uses the environment's mailer. To send that through SES as well, set these in Coolify (they are not secrets in the repo):

```
MAIL_MAILER=smtp
MAIL_HOST=email-smtp.<region>.amazonaws.com
MAIL_PORT=587
MAIL_USERNAME=<SES SMTP user name>
MAIL_PASSWORD=<SES SMTP password>
MAIL_FROM_ADDRESS=<an address on a verified domain>
MAIL_FROM_NAME="<name>"
```

With `MAIL_MAILER=log` (the default) such emails are only written to the log.

## Not covered

SES can restrict an account that has many bounced or complained-about messages. With tracking on, the Email Log and the account health on **Settings → Amazon SES** show the problem early; the ERP does not yet stop sending to an address after it bounces (Amazon's own suppression list does that). Reputation graphs stay in **SES → Reputation metrics**.
