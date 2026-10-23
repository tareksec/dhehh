# Roles and Permissions

Business Dashboard uses role-based permissions for Filament admin access and custom business actions.

## Built-In Roles

| Role | Intended Use |
|---|---|
| Super Admin | Full system owner with all permissions |
| Manager | Business manager with sales, purchase, inventory, accounts, and report access |
| Sales Staff | Sales user with customer/order/report view access |
| Inventory Staff | Inventory and purchase user |
| Accountant | Accounts, payments, expenses, and report export user |

## Permission Matrix

| Module | Super Admin | Manager | Sales Staff | Inventory Staff | Accountant |
|---|---:|---:|---:|---:|---:|
| Dashboard | Full | View | View | View | View |
| Sales | Full | Create/Edit | Create/Edit | View | View |
| Purchasing | Full | Create/Edit | No | View/Create/Edit | View |
| Inventory | Full | Create/Edit | View | Create/Edit | No |
| Accounts | Full | Create/Edit | No | No | Create/Edit |
| Reports | Full | View/Export | View | View | View/Export |
| Settings | Full | No | No | No | No |
| Backups | Full | No | No | No | No |
| Users/Roles | Full | No | No | No | No |
| AI Tools | Full | Menu + Image Generation + Video Generation | No | No | No |

## Permission Keys

Available permission keys:

```txt
dashboard.view
sales.view
sales.create
sales.update
sales.delete
purchasing.view
purchasing.create
purchasing.update
purchasing.delete
inventory.view
inventory.create
inventory.update
inventory.delete
accounts.view
accounts.create
accounts.update
accounts.delete
reports.view
reports.export
backups.manage
settings.manage
users.manage
ai_tools.menu
ai_tools.image_generation
ai_tools.image_generation.review
ai_tools.video_generation
ai_tools.video_generation.review
ai_tools.content_creation
investments.view
investments.manage
investments.settle
investments.manage_channel_partner
investments.override_investment_window
investments.portal_manage
investments.payout_batch_create
investments.payout_batch_approve
```

`investments.portal_manage` lets a user switch the investor portal on and
off (Investments → Investor Portal Settings; the custom domain stays Super
Admin only) and invite, disable or re-enable an investor's or channel
partner's portal sign-in (the "Portal access" actions on their pages), and
use the **Open investor portal** button on their pages, which opens the
portal in a new tab as that person (look-only; see
`InvestorPortalStaffEntryService`). No
built-in role except Super Admin (via `*`) holds it; grant it to a custom
role from the role editor
(`15_INVESTOR_PORTAL_IMPLEMENTATION_PLAN.md`).

`investments.payout_batch_create` makes a payout batch (Investments → Payout
Batches), takes payouts out of a draft, downloads the payment list, marks
failed payments and records the batch as paid. `investments.payout_batch_approve`
approves a batch. The approver must be a different user from the one who made
the batch, even for Super Admin. Either permission can cancel a batch that is
not yet recorded paid. Since IMS v2 (2026-10-19, `IMS-V2/ROLES.md` §3.3) a
Manager holds `payout_batch_approve` and an Accountant `payout_batch_create`
by default.

IMS v2 permissions (`IMS-V2/ROLES.md` §3.2):

```text
ims.office.access          open the IMS office (Super Admin, Manager, Accountant)
ims.kyc.review             approve / turn down KYC (Manager)
ims.investments.confirm    confirm money received: waiting -> active (Manager)
ims.proposals.approve      decide profit proposals (Manager)
ims.settlement.void        void a settlement (Super Admin only)
ims.capital_returns.manage pay back capital, void a return (Accountant)
ims.updates.manage         project updates, milestones, reports (Manager)
ims.feed.write             post to the feed (Manager)
ims.feed.moderate          hide comments, see who saw a post (Manager)
ims.support.reply          answer support conversations (Manager)
ims.reconcile              read and match bank / bKash statements (Accountant)
ims.settings.manage        IMS settings (Super Admin only)
ims.money_rules.manage     investor / partner % (Super Admin only)
ims.domain.manage          the IMS domain (Super Admin only)
```

A Manager also holds `investments.settle` and `investments.portal_manage` by
default now. An Accountant never makes an investment active or shares out
profit. An "office staff" person (support replies, project updates) is set up
with custom permissions.

`ai_tools.content_creation` is reserved for a future tool — the key exists now
so role setup does not need revisiting when it ships, but nothing is gated by
it yet. `ai_tools.video_generation` gates the Video Generation tool the same
way `ai_tools.image_generation` gates Image Generation (generate + attach to a
product or offer, plus the Video Library).
`ai_tools.image_generation.review` lets a user approve or reject other
people's generated images on the Image Library when a company has turned on
the approval workflow (AI Tools → Image Governance).
`ai_tools.video_generation.review` does the same for videos on the Video
Library (AI Tools → Video Governance). By default only Super Admin (via `*`)
and the built-in Manager role hold `ai_tools.menu`,
`ai_tools.image_generation`, `ai_tools.image_generation.review`,
`ai_tools.video_generation`, and `ai_tools.video_generation.review`; grant
them to any custom role from the role editor.

### Trade Portal (`/trade`, 2026-10-22)

```text
trade.access               open the Trade Portal (a company with the portal switched on)
trade.profile.manage       edit the Business profile there (addresses, TIN/BIN/IRC, banks, letterhead)
trade.sales                Delivery Challans: sell, receive money, print, cancel
trade.purchases            Goods in: enter purchases, mark goods arrived
trade.stock                Stock: add items, correct counts
trade.dues                 Dues: see who owes, receive money
trade.import               Bring in old data (buyers with old dues, items with stock)
trade.lc                   LC files: import details, stages, print the LC letter, DRL, certificate, packing list, shipment advice
trade.cashbook             Cash book and balance sheet; record bank deposits, supplier payments, costs
trade.money_accounts       Cash and bank accounts: add them, set the money in them at the start
```

The **Trade Owner** role (`trade_owner`, made by a migration, editable in
Users → Roles) holds all of these. A role whose permissions are all
`trade.*` opens `/trade` only: `/admin` refuses it (`User::isTradePortalOnly()`).
A role with any other permission, or none, keeps `/admin` as before. Staff
accounts open no panel other than `/admin`, `/office` and `/trade`.

## Custom Roles

Custom roles can be created from the admin panel. A custom role stores:

- Name
- Slug
- Permission list
- Active/inactive state

Users assigned to inactive custom roles receive no permissions from that role.

## Security Rules

- A user with no role falls back to `sales_staff`, not `super_admin`.
- Only Super Admin can delete sensitive financial records.
- A user cannot deactivate their own account.
- The last active Super Admin cannot be downgraded or deleted.
- Report export requires `reports.export`.
- Backup access requires `backups.manage` or Super Admin.
- Company settings access requires `settings.manage` or Super Admin.

## Recommended Production Practice

- Keep at least two active Super Admin users.
- Use custom roles for narrow client-specific jobs.
- Review inactive users monthly.
- Review audit logs after role or payment changes.
- Avoid sharing admin accounts between staff.
