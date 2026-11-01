# Supplier Account

Owner request 2026-10-26: the hand-kept workbook of the Shahin Khan account (`Shahin Vai - Product Details`) becomes a report the ERP works out by itself, and the Report module is arranged around it.

## The rule

What we still owe a supplier:

```text
Value of goods received            pieces received x his buy price
- Goods he took from our stock     pieces he gave his own customers x his buy price
- Customer advance he collected    advance of those customers that he holds
= Value after deductions
- Total we gave                    cash into the account + shipping we paid + ad cost we paid
= Supplier still gets from us
```

A negative result means we have given more than the goods are worth. "NOT FINAL" shows while a received product has no buy price.

## Where each figure comes from

| Figure | Record |
|---|---|
| Received, short, buy price, cartons | **Purchasing → Goods Receipts** (`purchase_receipts`, `purchase_receipt_items`) |
| Goods taken, advance | **Purchasing → Supplier Orders** (`supplier_orders`, `supplier_order_items`), status Open or Delivered |
| Cash we gave | **Supplier Payments** with "Cash into the supplier account" on (`supplier_payments.for_supplier_account`) |
| Shipping, ad cost | **Expenses** with a supplier and a cost type (`expenses.supplier_id`, `supplier_cost_type`) |
| Old figures | **Purchasing → Account Entries** (`supplier_account_adjustments`): cash, shipping, ad cost kept outside the ledgers |
| Sold, avg rate | our own completed orders of the supplier's products (`products.supplier_id`, or a product he was received for) |
| Outside godown | our own orders that are Confirmed or Processing |
| Damage | the damage on receipts, plus any other damage stock movement |
| Stock | the stock-movement ledger up to the date |

Each product row also has a **Difference**: received - short - damage - sold - outside - taken - stock. It is 0 when the books agree. Nuport-imported orders and history receipts move no stock, so they show up here until the physical stock is entered.

## Goods Receipt

A receipt is goods one supplier delivered on one date: per product cartons, pieces per carton, **received (pcs)** (what the supplier sent), **short** (lost on the way), **damage** (arrived broken) and the **buy price per piece** (no shipping or other landed cost).

Stock added = received - short - damage (a `purchase` movement of received - short, and a `damage` movement). A receipt with **Add to stock** off (old goods the stock already counts) only counts in the account. It is separate from a Purchase (the invoice flow); do not enter the same goods through both.

## Supplier Order

An order the supplier placed for his own customer, filled from our stock. It is its own record on purpose: the money is not ours, so it never reaches our sales, profit, customer dues, courier bookings, notifications or website webhooks.

- Open and Delivered take the pieces out of our stock (a `sale` movement per line) and charge the supplier his buy price; Returned and Cancelled give the stock back.
- The supplier's buy price is the latest receipt price of that product, fixed when the line is saved.
- **Advance paid by customer** and who holds it: the supplier (comes off our debt) or ZamZam (record it in Customer Payments too).
- Products offered are the supplier's own (`products.supplier_id`, "Main supplier" on the product) once he has some assigned.

## Reports (Reports → Suppliers)

- **Supplier Account**: choose a supplier and an **As of** date. The account block on top, the product table below, product names open **the product page** (receipts by date, sales by price, damage, pieces in the pipeline, goods taken, advance). Print, Excel, CSV and PDF.
- **Supplier Accounts**: every supplier with an account, the purchase invoice payable next to the supplier-account balance.

The **Supplier Report** page (Reports cluster, `SupplierReportService`) is a different report: it works from Purchase lines (short and damaged pieces on arrival, costs after arrival, profit per product). It does not read Goods Receipts, Supplier Orders or Account Entries, and the Supplier Account does not read Purchase lines, so goods entered in both would be counted twice in the two reports. Use one way per supplier.

## Importing the old workbook

`Purchasing → Goods Receipts → Import supplier workbook`, or `php artisan supplier-account:import-workbook <file.xlsx> <supplier id or name> <company slug> [--map=map.csv] [--date=YYYY-MM-DD] [--replace] [--skip-unmatched] [--apply]`. Without `--apply` it only previews.

- Reads one tab per product (received cartons and pieces, short, damage, buy price, the "Taken by" rows) and the Summary sheet (cash, shipping, ad cost).
- Products are matched to ERP products by name; a two-column CSV (workbook name, ERP SKU) fixes the rest. Products that have variants are matched by variant SKU.
- Saves one **opening receipt**, one supplier order per "taken" row (delivered, no stock movement) and account entries for the three money totals. Stock and account balances never change.
- The preview prints the workbook's own figures next to the import's (value received, goods taken, advance, balance); they must agree.
- **Replace** swaps an earlier import for the figures of a newer workbook.
- A "Customer Advance" cell is a number, or text with an amount after a dash ("City Bank - 80000"); "Full Paid" has no amount and counts as 0.

## Not done yet

- Pieces still in shipment at the supplier (the workbook's yellow "in shipment" cell) are not recorded.
- Meta ad spend is not pulled in; ad cost is an expense or an account entry.
- One main supplier per product; a product bought from two suppliers is not split.
- Stock pools (stock shared between companies) are not followed.
- The supplier cannot sign in to place his own orders; staff enter them.
