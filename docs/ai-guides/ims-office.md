# IMS Office — guide for an AI assistant

Address: `https://zamzaminv.app/office`. Read [README.md](README.md) first for the rules you must follow.
Last checked against the code: 2026-10-24.

## What it is

The back office of the **Investment Management System (IMS v2)**, built on Islamic **Mudarabah** (profit sharing): investors put money into a **group** or **project** (an import / trade deal), the business trades, and the profit is shared by fixed percentages. Staff use the Office to bring in investors, check their identity (KYC), confirm money received, run projects, approve profit, pay out, and talk to investors. Investors see their side in the [Investor Portal](ims-investor.md).

- Staff sign in with an ERP staff account. The permission `ims.office.access` is needed.
- Bangla / English switch at the top. Light / dark button at the top.
- Several companies can use the one IMS address. Super Admins and roles with `ims.all_companies` can open every company; other staff only their own. A **company picker** (dropdown in the top bar; at the top of the menu on a small phone) chooses the company you are working in. An **All companies** page adds up the figures of every company the person may open.
- On a phone there is a bottom tab bar: **Home · Investors · + · To do · Menu**. "+" opens quick adds (Add investor, Record an investment, Invite an investor, New project, Post a project update, Write a feed post). "To do" is the owner's list of things waiting. "Menu" shows every page as tiles. On a laptop there is a slim icon rail on the left and the same pages in the top menu.

## Menu map

Only pages the person may open are shown.

| Group | Page | What it is for |
|---|---|---|
| (top) | Home | Today's money cards, groups close to maturity, the owner's to-do list |
| People | Investors | Directory of investors, capital, KYC status |
| People | KYC | Review uploaded NID cards beside what the system read from them; approve or reject |
| People | Invites | Make invitation links so a new investor can register |
| People | Investor Drafts | New applications waiting to be reviewed |
| People | Channel Partners | People who bring investors and earn a commission |
| Groups and projects | Investor Groups | A pool of investors with a cycle (months), target and status |
| Groups and projects | Projects | A trade / import deal funded by investors; linked purchases and settlement |
| Groups and projects | Investments | Each individual placement of money |
| Groups and projects | Money to confirm | Money that investors say they sent, waiting for staff to confirm it arrived |
| Profit and payouts | Profit proposals | The system drafts a profit sharing from completed sales; staff approve or dismiss |
| Profit and payouts | Settlements | Closing a project: revenue − cost = profit, shared by the percentages |
| Profit and payouts | Payout Batches | Groups of payouts to send to the bank together |
| Profit and payouts | Capital returns | Giving the original money back when a group / investment matures |
| Talk to investors | Investor Requests | Requests from investors (withdraw, reinvest, change details) |
| Talk to investors | Support | Help threads (chat) with investors |
| Talk to investors | Feed | Posts shown to investors |
| Talk to investors | Project updates | Milestones and photos of a project |
| Talk to investors | Project reports | Project reports as PDF |
| Tools | Statement matching | Upload a bank / bKash statement and match it with money received |
| Tools | Agreement Templates | The contract text with fields filled in automatically |
| Tools | Exchange Rates | Currency rates |
| Tools | IMS settings | Notifications, schedules, statements, registration switch |

## How money and people move (the main flow)

1. **Invite an investor** (Invites → create link, send it). The investor opens the link and registers with a phone code (OTP). Passwords are never sent; the investor sets their own.
2. **KYC**: the investor uploads their NID. The system reads it (OCR). Staff open KYC, compare, and **approve or reject**. An investor cannot put money in until KYC is approved.
3. **Record the investment**: the investor sends money (cash, bKash, bank). Staff record the investment (Investments → Record an investment, or the "+"); it stays **pending**. When the money has really arrived, staff confirm it (Money to confirm, or Statement matching) and it becomes **active**. A pending investment earns nothing.
4. **Groups / projects**: money sits in an Investor Group (a pool with a cycle) or a Project. The group moves through its states (collecting → running → maturing → settled) by itself on a schedule.
5. **Profit proposals**: every day at 07:00 the system looks at completed sales and drafts a profit proposal. Staff with the right permission **approve** or **dismiss** it. Approving never happens twice for the same period.
6. **Settlement**: when a project closes, Settlements calculates profit (or loss) and shares it by the project's percentages: investors, channel partner, company. A loss is taken from capital and never more than the amount invested.
7. **Payouts**: approved profit becomes payouts. Make a **Payout Batch**, someone else approves it (the person who made a batch cannot approve it), pay through the bank, then mark it paid with the bank reference or proof.
8. **Capital return** at maturity: Capital returns queue → pay → attach proof.
9. **Statements**: a monthly statement is made for each investor on the 1st of the month (09:00). Maturity reminders go out daily (08:00). The owner gets a daily digest.

Profit and loss percentages and money rules are set per project by a Super Admin only.

## Who can do what (defaults; the Super Admin can change any of it)

| Job | Super Admin | Manager | Accountant | Office staff |
|---|:-:|:-:|:-:|:-:|
| Enter the Office | yes | yes | yes | yes |
| Run investors, groups, projects | yes | yes | – | – |
| KYC approve / reject | yes | yes | – | – |
| Confirm money received | yes | yes | – | – |
| Approve profit proposals | yes | yes | – | – |
| Settlements (create) | yes | yes | – | – |
| Void a settlement | yes | – | – | – |
| Make a payout batch | yes | – | yes | – |
| Approve a payout batch | yes | yes | – | – |
| Capital returns | yes | – | yes | – |
| Match bank statements | yes | – | yes | – |
| Project updates, support replies | yes | yes | – | yes |
| Feed posts | yes | yes | – | – |
| IMS settings, percentages, domain | yes | – | – | – |

Rules behind the table: one person cannot do everything on purpose (money in, approval and cancel are in different hands); the accountant cannot activate an investment or share profit; sensitive data (NID, bank numbers) is hidden in lists and each view is logged.

## When the user is stuck — common cases

| What the user says | Likely reason and what to tell them |
|---|---|
| "I can't open /office" | The account lacks `ims.office.access`, or the person is not attached to any company. Ask the Super Admin. |
| "I see only one company / I need another" | Only Super Admins and roles with "all companies" can open every company. Otherwise only their own. Use the company picker; ask the Super Admin to widen access if needed. |
| "A menu or button is missing" | The role lacks that permission (table above). Ask the Super Admin. |
| "The investor says they paid, but it shows pending" | Money has to be confirmed. Open Money to confirm (or Statement matching), check it really arrived in the bank / bKash, then confirm. |
| "The investor can't invest" | KYC is not approved yet. Open KYC and review. |
| "I can't approve my own payout batch" | By design the creator and the approver must be different people. |
| "No profit proposal appeared" | Proposals are drafted once a day at 07:00 from completed sales; nothing is drafted for a period with no completed sales, or one already drafted. |
| "Profit numbers look wrong" | Do not change anything. Note the project, period and figures and ask the Super Admin; percentages are set per project. |
| "Page error / blank" | Note the exact message and time; tell the Super Admin. |

## Facts that must not be guessed

- Investments and profit payouts are shifts of funds, not company expenses; they never create expense records.
- The old external IMS is retired; everything is in this system now.
- Investor data is private per company. A channel partner sees their own investors' names and amounts only, never NID, phone or bank.
- Deleting is rare and audited. Never tell a user to delete to "fix" something; cancel / void with a reason and ask the Super Admin.
