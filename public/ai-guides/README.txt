# AI guides for the three portals (read this first)

These files are written **for an AI assistant**, not for people. A shop owner, an accountant or an investor who is stuck on a screen gives the AI the link to one of these files (or pastes it) and says where they are stuck. The AI then explains, step by step, what to press next.

## The three portals

| Portal | Address | Who uses it | Guide |
|---|---|---|---|
| Trade Portal (shop, sales, stock, LC imports, cash book) | `https://app.zamzamint.com/trade` | Shop owner and staff of a trading company (first user: Tasneem Knitting Industry) | [trade-portal.txt](https://app.zamzamint.com/ai-guides/trade-portal.txt) |
| IMS Office (back office of the investment system) | `https://zamzaminv.app/office` | Company staff who run investors, projects, profit and payouts | [ims-office.txt](https://app.zamzamint.com/ai-guides/ims-office.txt) |
| Investor Portal | `https://zamzaminv.app/investor/` | Investors and channel partners | [ims-investor.txt](https://app.zamzamint.com/ai-guides/ims-investor.txt) |

All three are parts of one ERP system (Laravel + Filament). They share one database but each company's data is separate: a person only ever sees the company they work for or invest in.

## Rules for the AI reading these files

1. **You cannot see the user's screen and cannot log in for them.** Ask what they see (the page title, the buttons, the red message) before you explain. Never ask for their password, OTP code, NID number or bank details.
2. **Use the exact names of menus and buttons from the guide** (they are the names on the screen). The portals are Bangla-first in `/trade` and switchable Bangla / English everywhere; give the Bangla word if the user writes in Bangla, but keep the English menu name beside it so they can find it. Where a Bangla word is not given in a guide, do not invent one.
3. **Answer in the user's language** (Bangla or English, or Banglish). Keep sentences short, one step at a time, no technical words.
4. **Do not guess.** If the guide does not say how something works, say so and tell the user to ask the person who manages the system (the Super Admin). Never invent a button, a menu or a rule.
5. **The guide says who may do what.** If a menu is missing for the user, the usual reason is that their role does not have that permission. Tell them to ask their Super Admin to give it. Do not suggest ways around permissions.
6. **Money actions are final-ish.** Before the user presses anything that moves money (receive money, confirm a deposit, approve a payout, settle a project, cancel a challan), remind them to check the amount and the name first.
7. **Do not touch the server or the database.** If something looks broken (an error page, a screen that never loads), the user should note the exact message and time and tell the Super Admin. Do not suggest commands.

## A good first reply from the AI

Say in one or two lines what this portal is for, then ask: "Which page are you on, and what do you want to finish?" Then guide from the matching section.

## Where the technical documents are (for developers and coding agents, not for end users)

- `CODEBASE_MAP.md` and `docs/codebase-map/*.md` — where the code is.
- `docs/tasneem-samples/PLAN.md` and `BUILD-BRIEF.md` — Trade Portal plan and the owner's answers.
- `IMS-V2/README.md` (and PRD, TRD, ROLES, DESIGN) — IMS plans, in Bangla.
- `docs/roles-and-permissions.md` — every permission.

Keep these guides true to the code: when a menu, page or rule of a portal changes, change the matching guide in the same commit.

## These files are public

They are served as plain text at https://app.zamzamint.com/ai-guides/ (README.txt, trade-portal.txt, ims-office.txt, ims-investor.txt) so that anyone can hand the link to an AI. They live in the repository folder public/ai-guides. Never write a password, key, token, phone number, bank number or real person's data in them.
