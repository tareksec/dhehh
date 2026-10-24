# Investor Portal — guide for an AI assistant

Address: `https://zamzaminv.app/investor/`. Read [README.md](README.md) first for the rules you must follow.
Last checked against the code: 2026-10-24.

## What it is

The self-service site of the **Investment Management System (IMS v2)** for investors and channel partners. An investor puts money into a group or project run on Islamic **Mudarabah** (profit sharing by agreed percentages) and uses this site to watch it: investments, profit, payouts, statements, project news, and to send requests or ask for help. The staff side is [IMS Office](ims-office.md).

The investor is usually not technical. Explain in short, calm sentences. Never ask for the password, the OTP code, the NID number or bank account numbers.

## Signing in and joining

- A new investor is **invited**: staff send a link. The investor opens it and registers with a phone number and a one-time code (OTP) sent by SMS. The code works for at most 3 minutes; if it expires, ask for a new code. Passwords are never sent to anyone; the investor chooses their own.
- Forgot the password: use the "forgot password" link on the sign-in page; a code is sent by SMS (or an e-mail link if an e-mail is on file).
- Too many wrong tries lock the account for a while; wait and try again, or ask the company.
- Bangla / English and light / dark buttons are on the sign-in page and in the top bar.
- The same phone number can have accounts in several companies (businesses). One login covers all of them: see **My businesses**.

## Layout

On a phone: a bottom tab bar (Home, Investments, Payouts, Menu); the Profile page lists everything else. On a laptop: main pages as pills in the top bar, the rest under **More**, and a slim icon rail on the left.

## Pages

| Page | What it shows |
|---|---|
| Home | Total active capital, profit earned, recent news; totals per currency if there are several businesses |
| Projects | Projects the person can put money in or is already in, company by company |
| Investments | Active and finished investments, and units in a group |
| Profit (Payouts) | Profit payments from every business, with a Project filter; a printable slip per payout |
| Statement | Statement lines with a Project filter; monthly statement PDFs to download |
| Feed | Posts and project news from the company (can like and comment) |
| More → My businesses | One card per business the phone number is in, with totals (shown only if more than one); "Switch business" |
| More → Notifications | Alerts such as profit credited, statement ready |
| More → Requests | Send a request (withdraw, reinvest, etc.). It goes to the company of the chosen group / project / payout |
| More → Support | Chat with the company's staff |
| More → Documents | Files such as reports and prospectus |
| More → Agreements | Accepted agreements and contracts to read or download |
| More → Bank accounts | Where profit and returned capital will be paid (a change may need staff approval) |
| More → KYC | Upload NID / documents and see the status (Pending, Approved, Rejected) |
| More → Nominees | The person who inherits if something happens |
| More → Profile | Name, phone, e-mail, password, sign out |
| Channel partner → My investors | Only for channel partners: the investors they brought (names and amounts only) |
| Channel partner → Commissions | Commission earned and paid |
| Channel partner → Invite | The partner's own invitation link to share |

A person can be both an investor and a channel partner under one login.

## Main journeys

### First time
1. Open the invitation link → enter the phone number → enter the SMS code → choose a password.
2. **KYC**: upload clear photos of the NID (front and back). The system reads them, then staff check. Until staff approve, the person cannot invest. Status shows on the KYC page.
3. Fill **Bank accounts** and **Nominees**.
4. Read and accept the **Agreement** when asked.

### Putting money in
The investor pays the company (cash, bKash or bank) and tells the company; staff record it. It shows as **pending** until staff confirm the money arrived, then it becomes **active** and starts to count. If it stays pending, the investor should send the payment proof through **Support** or **Requests**.

### Watching profit
Profit is calculated by the company by agreed percentages when a project settles or a profit proposal is approved. It appears in **Profit** and in the **Statement**, and a notification is sent. A printable slip is available for each payout. If a figure looks wrong, do not guess: tell the investor to open **Support** with the project name, date and amount.

### Getting money back
At maturity the original capital is returned through the company's **Capital returns** process to the bank account saved in the portal. An investor who wants to leave early uses **Requests**. The company decides; do not promise a date.

### Switching business
If the person has accounts in several businesses, **My businesses** shows a card for each; pressing **Switch business** signs them into that business with no new login.

## When the user is stuck — common cases

| What the user says | Likely reason and what to tell them |
|---|---|
| "No SMS code came" | Check the phone number is the one the company has; wait for the 1 minute pause and ask again; the code lasts at most 3 minutes. Else contact the company. |
| "The code says expired" | Ask for a new code and enter it within 3 minutes. |
| "I can't invest / no invest button" | KYC is not approved or the project is not open for money (closed window). See KYC; ask Support. |
| "I paid but it isn't showing" | It is pending until staff confirm. Send the proof via Support. |
| "Where is my profit?" | See Profit and Statement. Profit appears only after the company approves it. If nothing yet, ask via Support; do not guess an amount or date. |
| "I can't change my bank account" | Changes may need staff approval. Send a request. |
| "I forgot my password" | Use "forgot password" on the sign-in page. |
| "I only see one business but I invest in two" | Businesses are matched by phone number. The number must be the same in both. Ask Support. |
| "Page error / blank" | Note the exact message and time; tell the company. |

## Privacy facts

- An investor sees only their own money. Never another investor's data, and never the company's customers, suppliers or prices.
- A channel partner sees their investors' names and amounts only, never NID, phone or bank details.
- Identity files and agreements are private; they download only while signed in.
