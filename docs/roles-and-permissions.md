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
ai_tools.content_creation
```

`ai_tools.content_creation` is reserved for a future tool — the key exists now
so role setup does not need revisiting when it ships, but nothing is gated by
it yet. `ai_tools.video_generation` gates the Video Generation tool the same
way `ai_tools.image_generation` gates Image Generation (generate + attach to a
product or offer); its own `.review` sibling will be added when the Video
Generation approval workflow ships, mirroring `ai_tools.image_generation.review`.
`ai_tools.image_generation.review` lets a user approve or reject other
people's generated images on the Image Library when a company has turned on
the approval workflow (AI Tools → Image Governance). By default only Super
Admin (via `*`) and the built-in Manager role hold `ai_tools.menu`,
`ai_tools.image_generation`, `ai_tools.image_generation.review`, and
`ai_tools.video_generation`; grant them to any custom role from the role
editor.

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
