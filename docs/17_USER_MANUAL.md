# User Manual — Garments BOM Management System

> **Status: living draft.** Updated up to 2026-10-21. Every new screen or change that users can see adds or edits a section here in the same change. When all modules are finished this file is turned into the final manual (Word / PDF). Sections marked **Not built yet** are listed at the end.
>
> Written in simple English for factory and office staff. No technical words.

---

## 1. What this system does

Before this system, each department kept its own Excel files. The same data was typed again and again, and nobody was sure which file was the latest. Now **everyone works from one place**. Data entered once is shown to every department that needs it.

The order moves through the company like this:

```
Order and BOM (Merchandising)
   -> Purchase Order and PI (Supply Chain)
   -> Payment request and payment (Supply Chain, Accounts)
   -> Material receiving (Store)
   -> Material issue to production (Store)
   -> Reports (Management)
```

Who does what:

| Department | Main job in the system |
|---|---|
| Merchandising | Brings in the buyer's order and BOM, keeps styles in order |
| Supply Chain | Raises POs, collects PIs, raises payment requests (PRA) |
| Accounts | Pays approved requests and records the payment reference |
| Store | Receives and issues material |
| Management / Admin | Watches progress, controls who can do what |

You only see the screens your role needs.

---

## 2. Merchandising

### 2.1 Style Library

**What it is.** The list of a buyer's styles, kept the same way the buyer's folders are:

```
Buyer  ->  Season  ->  Mother style  ->  Child style
Ann Taylor   SP27       748268            866317 (linked style no.)
                        720193            PETITE (fit variant)
```

- **Mother style** is the main style number.
- **Child style** is a style linked to it, for example another style number shown in brackets, or a fit such as PETITE or CURVY.

**Open it:** left menu, **Style Library**.

**Add a season:** choose the buyer, press **Add Season**, type a code such as `SP27`, press **Save Season**.

**Add styles:** choose the season. In the box write one or many styles separated by commas, for example `882635, 720193(PETITE), 748268 (866317)`. Text in brackets becomes a child style. Press **Add Styles**.

You rarely need to type styles by hand: when you import the buyer's Excel (next section) the styles are added for you.

### 2.2 Import Buyer Excel (any buyer, any layout)

Use this when the buyer's Excel does not look like the standard sample. It does not matter which sheet the data is on, which row the titles are on, or what the columns are called.

**Open it:** **Workspace**, then **Import Buyer Excel**. There are three steps.

**Step 1: Choose file.**
1. Choose the **buyer**.
2. Type the **season**, for example `SP27`.
3. Choose the Excel or CSV file. Press **Continue**.

**Step 2: Match columns.**
- The system finds the sheet with the data and the row with the column titles by itself. If it picks wrongly, change **Sheet** or **Title row** and press **Reload**.
- Each column of the buyer's file is shown with an example value and the system column it will go into.
- Columns the system recognised are filled in. **Yellow rows are not matched.** Choose the right system column from the list, or leave **Skip this column** (for example vendor or payment columns, which belong to other departments).
- A system column can be used only once.
- Tick **Save this matching for [buyer]**. Next time this buyer sends a file with the same layout, the system matches it for you and tells you so.

**Step 3: Preview and import.**
- You see how many rows will come in, how many columns were matched or skipped, and which styles were found.
- A yellow message appears if no Style column is matched, or a required column is empty. You can still import; fill those later in the Workspace.
- Press **Import**. The file appears under **Workspace**, **Existing Files**. Formula columns are calculated by the system.

**Good to know**
- Only Merchandising columns can be filled here.
- Formulas in the buyer's file are not recalculated. The value saved in Excel is used.
- Large files take 10 to 20 seconds to import. Please wait for the page to finish.
- If you stop half way, press **Cancel Import**.

### 2.3 Style status (how far each style has come)

**Open it:** **Style Library**, choose the season, press **View Status** on a style.

**What you see**
- Top row: order lines, order quantity, number of materials, and how many are **Ordered, PI received, Paid, Received, Issued** (with percentages).
- **Child style** buttons narrow everything to one child style.
- **Orders:** one line per color and customer PO, with ship date, quantity and how many materials are ordered or received.
- **Materials:** every material of the style with its PO, vendor, PI and payment status, received quantity, and its **Stage**. Use the search box or the stage list to find a material.

**Stage means the furthest step the material has reached:**

| Stage | Meaning |
|---|---|
| Not ordered | No PO yet |
| Ordered | A PO exists |
| PI received | The supplier's PI has arrived |
| Paid | The PI is paid |
| Received | Material has arrived in store |
| Issued | Material has been issued to production |

**Red "Late" and amber "Due soon"** appear only on material that is **not yet ordered**, using its "order to be placed by" date: Late if that date has passed, Due soon if it falls in the next 14 days.

These figures come from the same Workspace columns the other departments fill in. If a number looks wrong, check the Workspace row for that material.

### 2.4 When you change an order that already has a PO

If you change a field that a purchase order is built from (style, color, quantity, item and similar) and press Save in the Workspace, **Supply Chain is told at once**. They get a message such as "PO source changed: Qty on HB26FA0007. Regenerate before sending", and the PO shows **Source changed** until they regenerate it. If the PO is already in a payment request, the message says so. You do not need to phone them.

---

## 3. Supply Chain

- **Merchant changed an order:** you get a message (bell icon) and the PO shows **Source changed**. Open the PO and use **Regenerate** before sending it to the supplier.
- **You raise a PO or a payment request (PRA):** the Workspace columns PI Status, PI Amount, Payment Required Date, Payment Status and order status update **by themselves**. Nobody has to press Save in the Workspace.

## 4. Accounts

### 4.1 Payments screen

**Open it:** left menu, **Payments**.

- **Awaiting payment** lists approved payment requests that are not yet paid, with supplier, amount and required date. **Paid** lists the paid ones.
- To record a payment: type the **Payment reference no.** in the row and press **Record Payment**. The number is written to **Pmt Doc No** on every line of that request, and Payment Status becomes **Pmt Done** everywhere.
- A request that is **still at its payment step in the approval flow** shows **Open PRA** instead. Complete it there (see below); this screen never skips an approval step.
- A request also counts as paid if someone already typed the Pmt Doc No in the Workspace.

### 4.2 Payment step of a payment request

At the last step of a payment request there is a box **Payment reference no. (optional)**. If you fill it in, it is written to every line of the request. If you leave it empty the request still completes, but Payment Status stays Pmt Pending until a reference is recorded.

## 5. Store

No change to how you receive and issue material. What you record now also shows in the **Style status** screen (Received, Issued).

---

## 6. For IT / Admin

| Task | How |
|---|---|
| After pulling new code | `php artisan migrate`, then `php artisan view:clear` |
| Fill the standard order tables for files that were uploaded before | `php artisan bom:sync-tables --dry-run` to see what would happen, then `php artisan bom:sync-tables` |
| Switch off automatic refresh of Workspace columns from PO / PRA | `.env`: `SUPPLY_CHAIN_SYNC_WORKSPACE=false` |
| Switch off automatic filling of the standard order tables | `.env`: `BOM_SYNC_TABLES=false` |

On the live server, every push to `master` runs the migrations by itself. Only `bom:sync-tables` needs to be run once by hand for files that already exist.

---

## 7. Common questions

**The Style status page says "No orders are linked".** The style has no order yet. Import the buyer's Excel with the same season code, or run `bom:sync-tables` for older files.

**My buyer's Excel has no Season column.** That is fine. You type the season in Step 1 of the import.

**A column stays yellow in Match columns.** The system does not know that title. Pick the system column yourself, or Skip it. Save the matching so you do not repeat it.

**Record Payment says my role cannot edit Pmt Doc No.** Ask Admin to give your role that column.

**A file is locked.** Admin has locked it for editing. Ask Admin to unlock it.

---

## 8. Words used in this system

| Word | Meaning |
|---|---|
| BOM | Bill of Materials: all materials needed for a style |
| Season | A buyer's selling season, for example SP27 |
| Mother / child style | Main style / a style or fit linked to it |
| PO | Purchase Order sent to a supplier |
| PI | Proforma Invoice from the supplier |
| PRA | Payment Request Approval |
| Workspace | The shared sheet of order and material columns |
| Pmt Doc No | Payment reference recorded against a line |

---

## 9. Not built yet

Pre-costing, BOM approval, buyer inquiry and quotation, sample management, TnA (time and action), planning, commercial (LC / export documents) extensions, shipment documents, SAP FICO-style accounting, HR. These will be added to this manual as they are delivered.
