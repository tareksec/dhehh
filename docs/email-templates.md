# Email Templates

**Settings → Email Templates** is where the owner writes the emails the ERP sends. It is the email twin of **SMS Templates**. Every company has its own templates.

## What can be written

| Tab | Messages | If left empty |
| --- | --- | --- |
| **Order emails** | Order placed, and each stage: Confirmed, Processing, Shipping, Delivered, Completed, Cancelled, Returned, Refunded | Nothing is sent, so nobody gets an email before the owner has written it |
| **Codes & password reset** | Login code (shop customers), reseller password reset link, code to join IMS, investor password reset code | The plain email the system always sent |
| **Investor emails** | Invitation to join IMS, and each IMS news item (investment confirmed, profit shared, payout, statement ...) | The plain email the system always sent |
| **My templates** | The owner's own, for example an apology for a late parcel | Not applicable: staff send them by hand |

Each message has a **Subject** and a **Message**. Write the wording in the language the person should get; there is one text per message, as with SMS.

## Placeholders

Type `:name`, `:order`, `:total` ... and the real value is put in. The box under each message lists the ones that message can use, for example for an order: `:name` (customer), `:order` (order number), `:status`, `:items`, `:total`, `:due`, `:tracking`, `:company`. A code message must keep `:code`, the reset and invitation messages must keep `:link`; the page refuses to save one that does not, because the person would receive nothing to use.

A blank line starts a new paragraph. A line starting with `https://` becomes a link. A value is never put into the page as HTML, so a customer's name cannot break the layout.

## How the email looks

The text is wrapped in the company's branded layout: the logo (Settings → Company Settings; the company name if there is no logo) on white with a line in the site's colour (the theme colour of the company's main site), the message, a button when the email has a link (IMS news), and a footer with the company name, address, phone and email. A plain-text version is sent too.

**Preview** shows the message in that layout with example values. **Send a test** sends it with example values to an address you type, through the company's email account; the result is in **Settings → Logs → Email** as a *Test email*. **Use suggested wording** fills in a ready text you can edit.

## When an order email goes out

When an order is created (after a short wait so the items are saved) and every time it reaches a new stage, but only for a stage that has a written template. Not sent for imported past orders, reseller store orders, Delivery Challan sales (status emails), customers with no email address, or customers who asked not to be contacted. The same news is never sent twice. Each skipped email is written to the Email Log with the reason; a stage with no template leaves no trace.

The email goes through the email account of the order's own site (**Settings → Integrations → Email Setup**), else the company's main site. With Amazon SES see `docs/email-ses-setup.md` (the sandbox only delivers to verified addresses).

## Your own templates and "Email the customer"

On the **My templates** tab add a name (only you see it), a subject and a message. On an order, **Email the customer** (needs the permission to update orders; shown only when the company has a template and the customer has an email address) lets staff pick a template and mails it to the customer with that order's values filled in. The result is in the Email Log as *Email from a template*.

## For developers

`App\Services\EmailTemplateService` (definitions, rendering, saving, branding), `App\Services\OrderStatusEmailService`, `App\Jobs\SendOrderEmailJob`, `App\Mail\TemplatedEmail`, `App\Filament\Pages\EmailTemplates`, `App\Filament\Resources\Orders\Actions\EmailCustomerAction`, table `email_templates` (company-scoped). A new message is one entry in `EmailTemplateService::definitions()` plus the call site that uses `mailableOr()` (built-in mail as the fallback) or `render()`. Tests: `tests/Feature/EmailTemplatesTest.php`.
