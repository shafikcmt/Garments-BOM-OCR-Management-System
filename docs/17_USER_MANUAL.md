# User Manual — Garments BOM Management System

> **Status: living draft.** Updated up to 2026-10-26. Every new screen or change that users can see adds or edits a section here in the same change. When all modules are finished this file is turned into the final manual (Word / PDF). Sections marked **Not built yet** are listed at the end.
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
| Management / Admin | Watches progress (Style Status), gives follow-ups, controls who can do what |

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

### 2.2a Create a BOM on screen (no Excel needed)

You can make a style's BOM directly in the system.

**Open it:** open the style (**Style Library** or **Style Status**, then **View Status**) and press **Create BOM**. Once the style has a BOM the button says **Add to BOM**.

**1. Order lines.** One line for each colour and customer PO of the style:
- **GMTS color**, **Customer PO**, **Ship date**
- **Garment qty** (required): the quantity of that order line. The material quantity is worked out from it.
- **Child style** (only if the style has child styles): choose which child this line is for.
- **Add Order Line** adds a row, **Remove** takes one away. If the style already has order lines they are filled in for you, so you can add more materials without typing them again.

**2. Materials.** One line for each material:
- **Type** (SHELL, TRIMS, LINING and so on), **Description** (required), **SAP code**, **Material color**, **Unit**
- **Consumption** (required) per garment, and **Wastage %** if there is any.
- **Add Material** adds a row.

**3. Create BOM.** Under the buttons the page says, for example, "3 order line(s) x 4 material(s) = 12 BOM line(s)": **every order line gets every material.** (The limit is 500 BOM lines at once; the number turns red above that.)

**What happens next**
- The lines go into the Workspace like any imported BOM, in one file per buyer and season called "BOM [buyer] [season] (created in the system)". Formula columns such as consumption with wastage and **Materials to be ordered** are calculated for you.
- The style then appears in **Style Status** with its orders and materials, and you can **Submit for Approval** (section 2.6).
- A material that is already on an order line is **never added twice**. If you press Create BOM again with the same lines and materials you are told "Nothing was added".
- To change a line that already exists, use the Workspace (the file named above), not this screen.
- If a Workspace column does not exist, or your role may not edit it, you are told which one at the top of the page and after saving. The rest is still saved.
- If a BOM was already approved and you add material, it shows **Changed after approval** and must be approved again.

**Save typing: copy an earlier BOM, and let the system suggest materials**

*Start from an earlier BOM.* At the top of **Create BOM**, the box **Start from an earlier BOM** lists this buyer's styles that already have a BOM (this style too, for example to copy one colour's materials to another colour).
1. Choose the style, and, if you want only one colour or PO, choose it in **From**. The default is all its order lines, with a material that appears on several lines counted once.
2. Press **Copy Materials**. The **Materials** table below is filled with the type, description, SAP code, material color, unit, consumption and wastage of that BOM. **It replaces anything you had typed in that table.**
3. Check the consumption and wastage (they may differ this season), add your own order lines, and press **Create BOM**. Nothing is saved until you press it.

You can only copy from a style of the **same buyer**.

*Suggestions while you type.* In the **Description** or **SAP code** of a material, type two or more letters. The system offers materials this buyer has used before (from every BOM made or imported so far; there is no list to maintain). Choose one and the empty boxes of that row are filled: type, SAP code, color, unit, consumption and wastage as they were last time. **Anything you already typed is left alone.** If the same material was used twice, the newest figures are offered.

Use the Excel import (section 2.2) when the buyer sends a ready file; use this screen when you build the BOM yourself.

### 2.3 Style status (how far each style has come)

**Open it:** **Style Library**, choose the season, press **View Status** on a style. (Or from **Style Status**, section 2.4.)

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

### 2.4 Style Status: a whole season at a glance

**Open it:** left menu, **Style Status**. Merchandising, Admin and Management can all open it. It is for watching progress; nothing can be edited here.

**Seasons list.** Choose a **buyer** (or leave "All buyers"). Each season shows how many styles, order lines and materials it has, bars for **Ordered** and **Received**, and the number that are **Late**. Press **View Season**.

**Season page.** Every style of the season in one table, with bars for **Ordered, PI received, Paid, Received, Issued**. The styles that need attention (most **late**, then most **due soon**) are listed first. Press **View Status** on a style to see its materials one by one (section 2.3).

The cards at the top are the totals of the whole season. The red or yellow bar warns about materials that are not ordered yet and are late or due soon (same rule as in 2.3).

If a season shows "No orders linked", import the buyer's Excel with that season code first (section 2.2).

### 2.5 When you change an order that already has a PO

If you change a field that a purchase order is built from (style, color, quantity, item and similar) and press Save in the Workspace, **Supply Chain is told at once**. They get a message such as "PO source changed: Qty on HB26FA0007. Regenerate before sending", and the PO shows **Source changed** until they regenerate it. If the PO is already in a payment request, the message says so. You do not need to phone them.

### 2.6 BOM approval

Before Supply Chain orders material, the BOM of a style is **checked and approved**.

```
Merchant submits  ->  Merchandising Head approves  ->  (Management approves)  ->  Approved
```

The Management step is optional; Admin switches it on (see below).

**Submit a BOM (Merchandising)**
1. Open the style (**Style Status**, then **View Status**).
2. In the **BOM approval** box press **Submit for Approval**. The style must already have its BOM (import the buyer's Excel first).
3. The Merchandising Head gets a message in the bell icon.

**Approve or return (Merchandising Head, then Management)**
1. Left menu, **BOM Approvals**. The red number is how many are waiting for **you**. Open **Waiting for me**.
2. Press **Open Style**, check the materials, then in the **BOM approval** box press **Approve**.
3. If something is wrong, write the reason in **Comment** and press **Return to Merchant**. A reason is required.
4. You cannot approve a BOM you submitted yourself, unless nobody else is able to.

**After it is returned.** The merchant fixes the BOM in the Workspace and presses **Submit Again**. Every step (who, when, comment) is kept in the history table of the same box.

**After it is approved.** The merchant and Supply Chain are told: "BOM approved and ready to order."

**If the BOM changes after approval.** Changing a material, quantity, consumption or order quantity after approval shows **Changed after approval** (red). It must be submitted and approved again. A BOM that was changed after it was submitted cannot be approved; return it instead.

**Status you will see**

| Status | Meaning |
|---|---|
| Not submitted | Nobody has asked for approval yet |
| Waiting for Merchandising Head / Management | Submitted, waiting for that person |
| Returned | Sent back with a reason |
| Approved | Approved and unchanged |
| Changed after approval | Approved before, edited since, needs approval again |

The **Season page** of Style Status shows this status for every style.

**Admin settings** (top of **BOM Approvals**, Admin only)
- **Second approval by Management**: adds the Management step.
- **Supply Chain cannot generate a PO until the BOM is approved**: when ticked, Supply Chain gets the message "The BOM of style 748268 is not approved…" if they try to generate a PO for an unapproved style. Both boxes are **off** until Admin ticks them, so nothing changes by itself. Styles that are not linked to an imported BOM (older files) are never blocked.

### 2.7 Pre-costing

Pre-costing is the **cost sheet of a style, before the order is booked**: what each part of the garment costs, and what is left of the price. It is an *estimate*. The BOM (sections 2.2 and 2.6) is the *actual* material list after the order.

**Open it:** open the style (**Style Status**, then **View Status**) and press **Open Pre-costing**.

**Two costings per style**

| Costing | What it is |
|---|---|
| **Buyer** | The price build-up you quote to the buyer |
| **In-house** | What the factory expects to spend |

Use the two buttons at the top to switch. When both exist, the page shows both margins side by side.

**Make a costing**
1. Press **Start Buyer Costing** (or In-house). The sheet starts with five rows: Fabric, Trims, CM, Overhead, Commission.
2. Choose the **Currency** and whether costs are **per piece or per dozen**. Type the **Quoted price**.
3. Under each **cost head** (Fabric, Trims and accessories, Embroidery / print, Washing, Testing / other, CM, Overhead, Commission) fill the lines: **Description, Qty, Unit, Wastage %, Rate**. The **Amount** is worked out for you: `Qty x (1 + Wastage %) x Rate`. For CM or Commission use Qty 1 and type the amount in Rate.
4. Press **Add Line** for more lines under a head, **Remove** to take one away. Empty rows are ignored.
5. Press **Save Costing**. The cards at the top show **Total cost, Quoted price, Profit and Margin** (profit as a share of the quoted price).

**Finalize and revise**
- When the costing is agreed, press **Finalize Revision 1** (save first). A final revision **cannot be edited**.
- To change it, press **New Revision**. Revision 2 starts as a copy and is a draft until you finalize it. Old revisions stay as they were; the **Revision history** table at the bottom compares total cost and margin of every revision.
- Only one draft can exist at a time.

**Who can do what.** Merchandising and Admin make and change costings. Management can open and read them. Other roles cannot.

The **Season page** of Style Status shows the **Margin** of each style's latest *final* buyer costing.

---

## 3. Supply Chain

- **Merchant changed an order:** you get a message (bell icon) and the PO shows **Source changed**. Open the PO and use **Regenerate** before sending it to the supplier.
- **BOM not approved:** if Admin has switched the gate on, you cannot generate a PO for a style whose BOM is not approved. You are told which style. Ask Merchandising to get it approved (section 2.6). You are told when it is approved.
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

## 6. Follow-ups: asking someone to chase

A follow-up is a small task: **one person, one style, one due date.** It follows the "Time and Action" way of working used by garments ERP systems: everyone has a to-do list, tasks have a traffic light, and nothing is forgotten.

### 6.1 Ask someone to follow up (Merchandising, Admin, Management)

1. Open the style: **Style Status** or **Style Library**, then **View Status** on the style.
2. In the **Follow-up** box choose **Who should follow up**, the **Due date** (three days ahead is filled in) and, if you want, a **Note**.
3. Press **Create Follow-up**.

If the style has late or due-soon materials, the task is named after them, for example "Order 4 late material(s) for style 748268". The person gets a message in the bell icon straight away.

You can only choose people who may see that buyer. One person cannot be given two open follow-ups for the same style. The due date cannot be in the past.

### 6.2 Your to-do list (everyone who can be given a task)

**Open it:** left menu, **Follow-ups**. A red number next to it is how many of your follow-ups are due today or overdue.

- **My follow-ups** are the ones given to you. **Given by me** are the ones you asked others to do. Admin and Management also see **Everyone**.
- Tasks are grouped **Overdue** (red), **Due today** (yellow), **Coming up** (green). **Show done** also lists finished ones.
- Press **Mark Done** when finished. The person who gave the task is told. **Reopen** brings a finished task back.
- Click the style number to see the materials behind the task.

Only the person it was given to, the person who gave it, and Admin can change a task.

### 6.3 Reminders

Every morning (about 8:30) anyone with a follow-up that is **due today or overdue** gets one message: "You have 2 overdue and 1 due today follow-up(s)." You get at most one a day.

The **Season page** of Style Status shows how many follow-ups are open for each style.

---

## 7. For IT / Admin

| Task | How |
|---|---|
| After pulling new code | `php artisan migrate`, then `php artisan view:clear` |
| Fill the standard order tables for files that were uploaded before | `php artisan bom:sync-tables --dry-run` to see what would happen, then `php artisan bom:sync-tables` |
| Turn the second (Management) BOM approval or the PO gate on or off | Screen: **BOM Approvals**, Settings box (Admin only). Both are off by default. |
| Switch off automatic refresh of Workspace columns from PO / PRA | `.env`: `SUPPLY_CHAIN_SYNC_WORKSPACE=false` |
| Switch off automatic filling of the standard order tables | `.env`: `BOM_SYNC_TABLES=false` |
| Send the daily follow-up reminders by hand | `php artisan followups:remind` (the scheduler runs it every morning at 08:30, Dhaka time; the server's scheduler must be running) |

On the live server, every push to `master` runs the migrations by itself. Only `bom:sync-tables` needs to be run once by hand for files that already exist.

---

## 8. Common questions

**Who makes the BOM? Is it created by the system after I upload the Excel?** The **merchant** makes it: either on screen with **Create BOM** (section 2.2a) or in an Excel file that is uploaded or imported (section 2.2). The system does **not invent** materials or consumption; they come from the merchant (from the buyer's tech pack or the costing). Once the BOM is in, the system takes over: it arranges every line under the right style, season and colour, **calculates** the formula columns, and passes it on to BOM approval, PO, payment, receiving and issue. Making a BOM automatically from an order and a consumption master is **not built yet**.

**The Style status page says "No orders are linked".** The style has no order yet. Import the buyer's Excel with the same season code, or run `bom:sync-tables` for older files.

**My buyer's Excel has no Season column.** That is fine. You type the season in Step 1 of the import.

**A column stays yellow in Match columns.** The system does not know that title. Pick the system column yourself, or Skip it. Save the matching so you do not repeat it.

**Record Payment says my role cannot edit Pmt Doc No.** Ask Admin to give your role that column.

**A file is locked.** Admin has locked it for editing. Ask Admin to unlock it.

---

## 9. Words used in this system

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
| Follow-up | A task asking one person to chase a style by a date |
| BOM builder | The Create BOM screen: order lines and materials of a style entered on screen |
| Material suggestions | Materials this buyer used before, offered while you type; learned from earlier BOMs |
| BOM approval | Checking and approving a style's BOM before it is ordered |
| Pre-costing | Cost sheet of a style by cost head, before the order; Buyer and In-house, in revisions |
| Margin | Profit as a share of the quoted price |
| TnA | Time and Action: a to-do list with due dates and traffic lights |

---

## 10. Not built yet

Automatic BOM from an order and consumption master, comparison of pre-costing with the BOM, size-wise costing, buyer inquiry and quotation, sample management, the rest of TnA (task templates by lead time, tasks created automatically, mobile app), planning, commercial (LC / export documents) extensions, shipment documents, SAP FICO-style accounting, HR. These will be added to this manual as they are delivered.
