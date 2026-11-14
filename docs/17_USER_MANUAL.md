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
| Commercial | Records imported shipments (BL / AWB, dates, ports) and follows clearance |
| Accounts | Pays approved requests and records the payment reference |
| Store | Receives and issues material |
| Management / Admin | Watches progress (Style Status), gives follow-ups, controls who can do what |

You only see the screens your role needs.

---

## 2. Merchandising

### 2.0 Merchandising dashboard

**Open it:** left menu, **Dashboard** (the first screen after a merchant logs in).

It answers "what needs my attention today" for the buyers you look after. A merchant who is given only some buyers sees only those buyers.

**Top cards** (click a card to open the list behind it)

| Card | What it counts |
|---|---|
| Total Orders | Order lines, with the number of customer POs and styles under it |
| Order Quantity | Garment pieces of all those orders |
| Booking Pending | Materials with no PO yet; the small text says how many are already late |
| PI Pending | Materials with a PO but no PI from the supplier yet |
| Payment Pending | Materials whose PI has come but is not paid yet |
| PRA Pending | Payment requests still waiting for a check or an approval |

**Action chips** under the cards: late bookings, bookings due in the next 14 days, BOMs waiting for approval, your follow-ups due today, and orders shipping in the next 30 days. Click a chip to go straight to that list.

**Charts**
- **Material Pipeline**: how many material lines are at each step: Not ordered, Ordered, PI received, Paid, Received, Issued.
- **Order Quantity by Buyer**: each buyer's share of the pieces.
- **Order Quantity by Ship Month**: pieces shipping this month and in the next five months.

**Upcoming Shipments** lists the orders shipping in the next 30 days, with days left and how much of their material has been received. **Needs Attention** lists the styles with late bookings; press **View** to open the style.

The numbers come from the same place as **Style Status** (section 2.4), so both screens always agree. If the dashboard says "No orders are linked yet", import the buyer's Excel or create a BOM first (sections 2.2 and 2.2a).

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

**How the mother style is found when you import.** The system uses the first one that applies:
1. **The Mother Style column** of the buyer's file, if it has one and the cell is filled. The whole style code becomes the child, for example `788457(PETITE)` under mother `813132`.
2. **The style code.** The number before the bracket is the mother: `720193(PETITE)` goes under `720193`.
3. **The style itself.** A plain style such as `882635` is its own mother and has no child.

So a buyer whose file has no Mother Style column still imports normally. If your buyer's file has the column, keep it: no manual linking is needed afterwards.

### 2.1a Inquiries and quotations (before the order)

Use this when a buyer asks for a price on a style and there is no order yet.

**Open it:** left menu, **Inquiries**. The tiles show open inquiries, those whose **Quote by** date has passed, quotations waiting for the buyer, and the percentage won.

**Add an inquiry:** fill **Buyer**, **Style / design no.**, the **Received** date, and if you know them the description, product group, estimated quantity, the buyer's **Target price** and the **Quote by** date. Press **Add Inquiry**. The system gives it a reference such as INQ-26-0001.

**Work it** (press **Open** on the list):
1. **Start Costing:** choose the season (or type a new season code) and press the button. The style is made in the Style Library and the buyer pre-costing is started, ready to fill (section 2.7). If the style already has a pre-costing, it is reused and nothing is duplicated.
2. **Record Quotation:** type the price you quote and press the button. The price of the style's latest buyer costing is filled in for you; change it if the quotation differs. You can update it if the price is revised.
3. **Mark Won** when the buyer accepts. **Mark Lost** needs a reason, which stays on the inquiry. When the order comes, use **Import Buyer Excel** with the same season and style so it lands on the style made here.
4. **Cancel Inquiry** closes one the buyer withdrew. **Reopen** brings a lost or cancelled inquiry back.

**Print the quotation:** after the price is recorded, press **Download Quotation PDF** on the inquiry. It is an A4 page with the company heading, quotation number, date, **Valid until**, the buyer, the style, description, estimated quantity, **unit price and estimated value**, the **terms** and three signature lines (Prepared by, Authorised by, Accepted by buyer). Before you press it:
- Choose **Price per** piece or dozen when you record the quotation. A dozen price is also shown per piece on the page.
- Under **Details**, in **Printed on the quotation**, type the **Payment terms**, **Delivery terms**, **Valid for (days)** and **Remarks on the quotation**. Anything left empty prints the standard text (payment and delivery "To be agreed", valid for 30 days).
- The company name, address and logo come from the system settings, not from this screen; ask the person who looks after the server to change them.

This is the standard layout. Check it once with your own quotation before sending to a buyer, and tell the project owner what to change.

Merchandising and Admin work inquiries for the buyers they hold. Management can read them. The system never creates an order from an inquiry.

### 2.2 Import Buyer Excel (any buyer, any layout)

**This is the one way to upload.** On **Workspace**, the **New Excel File Upload** tab has one main button, **Import Buyer Excel**. An older **Quick upload (sample format only)** form is folded under it; use it only for a file made from **Download Sample**, and prefer **Import Buyer Excel** for everything else.

**Skipping the matching step.** When there is nothing to decide, the system goes straight from the file to the preview: when the buyer's saved matching was applied, or when every column of your file has the same name as a system column (a file made from **Download Sample**). Press **Back to matching** on the preview if you want to look at the matching.


Use this when the buyer's Excel does not look like the standard sample. It does not matter which sheet the data is on, which row the titles are on, or what the columns are called.

**Open it:** **Workspace**, then **Import Buyer Excel**. There are three steps.

**No Excel from the buyer?** Press **Download Sample** (top right of this page, or on the Upload Excel page). It is an empty Excel with the Merchandising columns, in the order Admin set. Fill one row per material line, save it, and upload it like any other file. When Admin adds a column on Excel Headers, it appears in the next sample you download.

**Step 1: Choose file.**
1. Choose the **buyer**.
2. Type the **season**, for example `SP27`.
3. Choose the Excel or CSV file. Press **Continue**.

**Step 2: Match columns.**
- The system finds the sheet with the data and the row with the column titles by itself. If it picks wrongly, change **Sheet** or **Title row** and press **Reload**.
- Each column of the buyer's file is shown with an example value and the system column it will go into. Match the buyer's mother style column (if any) to **Mother Style**, and the season column to **Season Name**.
- Columns the system recognised are filled in. **Yellow rows are not matched.** Choose the right system column from the list, or leave **Skip this column** (for example vendor or payment columns, which belong to other departments).
- A system column can be used only once.
- A buyer calls a column by a new name every time? Ask Admin to open **Admin, Excel Headers**, edit that header and type the name under **Other Names**. From then on that name is matched automatically in every buyer's file.
- Under the columns, **Mother and child styles** tells the system how this buyer writes styles. Choose once; it is saved with the matching:
  - **Automatic**: the Mother Style column if the file has one, otherwise the style code.
  - **Mother Style column only**: only that column. A blank cell is not guessed; it shows under **Rows to check**.
  - **Style code**: the number before the bracket, or before a fit word. `720193(PETITE)` goes under 720193.
  - **Every style is its own mother**: no child styles are made.
  - **Fit words** (optional): words such as `PETITE, TALL, CURVY, CURVY PETITE`. With them, `608468 PETITE` becomes mother 608468 with child PETITE even without a bracket.
  - Fit words are used only when the mother comes from the style code (Automatic without a Mother Style value, or Style code).
- Tick **Save this matching for [buyer]**. Next time this buyer sends a file with the same layout, the system matches it for you and tells you so.

**Step 3: Preview and import.**
- You see how many rows will come in, how many columns were matched or skipped, and which styles were found.
- Below that you see how many **mother styles**, **child styles** and **colors** the file contains, and a table showing each style, its mother, and where the mother came from (the Mother Style column, the style code, or the style itself). Check it against the buyer's file before importing: for example 14 mothers and 23 styles.
- **Rows to check** counts rows with no style (imported but not linked to the Style Library) and rows with an empty Mother Style (their mother is taken from the style code). A message above the table explains each case.
- A yellow message appears if no Style column is matched, or a required column is empty. You can still import; fill those later in the Workspace.
- Press **Import**. The file appears under **Workspace**, **Existing Files**. Formula columns are calculated by the system.
- Columns that belong to other departments (vendor, PO, PI, payment, shipment, receipt, issue) are not filled from your file. They appear in the workspace empty, so each department can update its own part after you upload. Formula columns are calculated by the system, not read from the file.
- A row whose style is `ALL` (for example poly bag or carton for every style) is imported as a **common item**. It stays in the BOM but is not added to the Style Library.
- **Order Qty** is a column you fill (the buyer's formula column "Customer Contract Quantity" is not read). If your file has the order quantity, match it to **Order Qty**. **Division** goes to the style's product group.
- Very long sheets are read until the data ends; a sheet that only looks long because of formatting is not a problem.

**Good to know**
- Only Merchandising columns can be filled here.
- Formulas in the buyer's file are not recalculated. The value saved in Excel is used.
- Large files take 10 to 20 seconds to import. Please wait for the page to finish.
- If you stop half way, press **Cancel Import**.


**Unmapped Columns (Admin).** Every time a file is imported, the system remembers the column titles of that file that it did **not** import and that match **no header** (by name, formula key or Other Name). Open them at **Admin > Excel Headers area > Unmapped Columns**. The list is grouped by title with the buyers that sent it, how many files carried it, and the last date.
- **Add as Other Name:** choose the header the title belongs to and press the button. The title becomes an Other Name of that header (the same as typing it under Other Names on Excel Headers), so the next file with this title is matched automatically. It applies to every buyer.
- **New Header:** opens the new-header form with the title already typed in, when the system truly needs a new column.
- **Ignore:** for columns nobody needs. They move to the **Ignored** tab and can be reopened.
A column that was skipped on purpose but matches another department's header (for example the buyer's Vendor column) is not listed.
### 2.2b Vendors: list, add, edit, Excel import and export (with bank details)

**Open it:** left menu, **Vendors**. (You can also press **Add Vendor** on the Merchandising Dashboard.)

**The list.** Every vendor with its contact, item type, location, bank and status. Use the search box (name, code, contact, city or item type). The **Bank** column says which bank is on file and the last four digits of the account, or **No bank details** if none was entered. The full account number is not shown on the list; it appears on the edit form and on Admin's screen.

**Add a vendor.**
1. Press **Add Vendor**.
2. Type the vendor name (required) and any contact details.
3. Under **Bank Details** fill what you know: bank name, branch, account name, account number, routing number, SWIFT code. All are optional.
4. Press **Save Vendor**. You return to the list with a confirmation.

**Edit a vendor.** On the list press **Edit** at the end of the vendor's row. The form opens with the current details, bank details included (the full account number is shown here so you can correct it). Change what you need and press **Save Changes**. The vendor's code must stay different from every other vendor's. Vendors you switch to inactive stay on the list, marked **Inactive**.

**Export to Excel.** Press **Export Excel** above the list. You get an Excel file of the vendors (only the ones your search shows, if you typed one) in the same columns as the upload template, plus **Status**. The account number is shown as its last four digits only (for example `****7890`), because the file leaves the system.

**Import from Excel.**
1. Press **Import Excel**, then **Download Template**. Fill one row per vendor; only **Vendor Name** is required. Delete the grey example row.
2. Choose the file and press **Import**.
3. The file is checked as a whole. If any row has a problem (a missing name, an invalid email, a Tolerance % outside 0 to 100, a Vendor Code already used by another vendor) **nothing is imported** and the rows to correct are listed. Fix them and upload again.
4. A vendor already on the list (same name, in any capital or small letters) is **skipped** and never overwritten; use **Edit** to change it. Skipped rows are listed after the import.

The vendor goes into the same list Admin manages (**Admin, Vendors**), so it can be used for bookings and payment requests, and your changes show there too. Only Admin can delete a vendor. The Store's own supplier list (**Store, Purchase Setup**, then open a supplier) has the same Bank Details fields.

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

### 2.2b Consumption Master (standard materials, fill the BOM faster)

The Consumption Master keeps the **standard materials, consumption and wastage of a product group** (for example Jacket), so you do not type the same materials for every style.

**Open it:** left menu, **Consumption Master**.

**Add a material:** choose the **Buyer** (Admin may choose **All buyers**), type the **Product group** (leave empty if it applies to every group), the **Description**, and the **Consumption**. Wastage % is optional. Press **Add Material**.

**Change or remove:** edit the figures on the line and press **Save**, or press **Delete**. Lines for **All buyers** can be changed by Admin only.

**Learn from an existing BOM:** under **Add a material**, choose a style that already has a BOM and press **Add Its Materials**. Its materials are copied for that buyer and the style's product group. Materials already there are not added twice. Set the style's product group first, otherwise they apply to every style of the buyer.

**Use it:** in **Create BOM** press **Fill from Consumption Master**. The Materials table is filled with the master's lines for the style's product group (the buyer's own line wins over an All-buyers line). **Nothing is saved yet.** Check every consumption and wastage, remove or add materials, then press **Create BOM** as usual.

The system **proposes, you decide**: it never makes a BOM without your pressing Create BOM. Management can read the master but not change it.

### 2.3 Style status (how far each style has come)

**Open it:** **Style Library**, choose the season, press **View Status** on a style. (Or from **Style Status**, section 2.4.)

**What you see** (from top to bottom)
- A red or yellow bar if materials are late or due to be ordered soon.
- Cards: order lines, order quantity, number of materials, and how many are **Ordered, PI received, Paid, Received, Issued**. The percentage is shown next to the name, for example "Ordered · 61%".
- **Pre-costing** and **BOM approval** side by side (sections 2.7 and 2.6), then **Follow-up** (section 6).
- **Child style** buttons narrow everything to one child style.
- **Orders:** one line per color and customer PO, with ship date, quantity and how many materials are ordered or received.
- **Materials:** every material of the style with its PO, vendor, PI and payment status, received quantity, and its **Stage**. Use the search box or the stage list to find a material.
- **Order Changes:** every change to the order columns (ship date, PO, colour, BOM quantity, consumption and the like) made in the Workspace: when, who, which colour / PO, the old and the new value. A change saved on several material lines at once shows once ("on 9 material lines"). Which columns count is set by Admin (**Show in Order Changes** on Excel Header Control). **The Store is told:** when a merchant saves a change in one of these columns, every Store user gets a notification "Order changed" naming the style and the columns, once per style per save, so receiving and issue are not done against an old figure. The Store roles are the ones Admin put under the Store department.

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

### 2.3a Size breakdown (colour x size)

**Open it:** open the style, then **Size Breakdown** on the **Orders** box.

1. **Choose a size set** (the sizes this style comes in) and press **Use Size Set**. If the buyer's sizes are not there yet, type a **New size set name** and the **Sizes** separated by commas (for example `XS, S, M, L, XL` or `0, 2, 4, 6, 8`) and press **Add Size Set**. A size set belongs to the buyer and can be used for all its styles.
2. The table has one row for each colour and customer PO of the style and one column for each size. Type the pieces per size.
3. **Size Total** adds the row up; **Difference** compares it with the order quantity (green "Matches", red when it differs).
4. Press **Save Size Breakdown**. If a row's total differs from its order quantity you get a yellow message naming the row; the figures are still saved.

The figures are kept by style, colour and PO, so they stay even when the Workspace file is re-imported. Management can open this page but cannot change it.

### 2.3b Size-wise BOM (consumption per size)

Use this when a bigger size needs more of a material than a smaller one, so one consumption for the whole order is not right.

**Open it:** open the style, then **Size-wise BOM** on the **Orders** box. You need the **Size Breakdown** filled first (section 2.3a).

1. Each row is a material of the style. **BOM cons. (incl. waste)** is the figure from the BOM.
2. Under a size, type the consumption for that size only if it differs. **Leave it empty to use the BOM figure.** Type the figure before wastage; the wastage of the BOM line is added by the system.
3. Press **Save Size-wise Consumption**.
4. **Size-wise need** is worked out size by size (pieces of the size x its consumption x wastage). **Workspace need** is the old figure (order quantity x BOM consumption). **Difference** is red when the size-wise need is higher, green when lower.

**Cost by size:** once a material has a size-wise consumption, the style's **Pre-costing** page shows a **Cost by size** box (section 2.7). It recalculates the matching costing line for each size and shows the cost, the difference against the sheet and the margin, then the **average by pieces** weighted by the Size Breakdown. The costing line is matched to the BOM material by name, so name them alike; a material with no matching line is listed in yellow. The costing itself is not changed.

A colour or PO that has no size breakdown is counted the old way, and the row says how many lines were counted so. This page does **not** change the Workspace, the PO or the BOM approval; use the Difference to decide what to book. Management can read but not change.

### 2.4d Analytics (order value, margin, win rate)

**Open it:** left menu, **Analytics**. Read-only; you see your own buyers (Admin and Management see all).

- **Order value by buyer:** for each buyer and currency, the number of styles, pieces, **order value**, **cost**, **profit** and **margin**. Choose one buyer in the filter to see the same by **season**.
- **Styles, lowest margin first:** the first place to look when a margin is thin (red when negative, yellow under 10%). Press the style number to open it.
- **Quotations won:** per buyer, open, won and lost inquiries and the win rate (section 2.1a).

**How the money is worked out:** pieces are the style's order lines (a colour and PO repeated in two files counts once). Price and cost are **per piece from the style's latest final buyer pre-costing** (a dozen sheet is divided by 12). They are **estimates** until the order is closed. Styles with no final costing and quoted price are not in the money columns; the yellow message says how many pieces they hold. Different currencies are never added together.

Capacity (can the factory take the order) is not shown yet.

### 2.4 Style Status: a whole season at a glance

**Open it:** left menu, **Style Status**. Merchandising, Admin and Management can all open it. It is for watching progress; nothing can be edited here.

**Seasons list.** Choose a **buyer** (or leave "All buyers"). Each season shows how many styles, order lines and materials it has, bars for **Ordered** and **Received**, and the number that are **Late**. Press **View Season**.

**Season page.** Every style of the season in one table, with bars for **Ordered, PI received, Paid, Received, Issued**. Click the style number (or **View Status**) to open it. The product group is shown under the style number and the number of order lines under the order quantity. The styles that need attention (most **late**, then most **due soon**) are listed first. Press **View Status** on a style to see its materials one by one (section 2.3).

The cards at the top are the totals of the whole season. The red or yellow bar warns about materials that are not ordered yet and are late or due soon (same rule as in 2.3).

If a season shows "No orders linked", import the buyer's Excel with that season code first (section 2.2).

### 2.4e Meetings (order progress meetings and their actions)

Keep the minutes of buyer and internal order meetings in one place, and make every agreed action a task with an owner and a date.

**Open it:** left menu, **Meetings**. Filter by buyer.

**Record a meeting:** press **New Meeting**. Fill the **Title**, **Date**, **Buyer**, and if the meeting was about one style its **Style no.** (it must exist in the Style Library for that buyer). Write who **Attended**, the **Minutes**, and the **Next meeting** date. Under **Actions** type each action, choose **Given to** and the **Due** date, press **Add Action** for more rows, then **Save Meeting**. Rows with an empty action are ignored.

**What happens to an action:** it is a normal **follow-up task** for that person: it is in their **Follow-ups** list, they get a message, the 08:30 reminder counts it, and a long-overdue one goes to the supervisor (section 6.4). The person presses **Mark Done** on their list, and the meeting page shows "done / total". The person must be able to see the buyer.

**Later:** open the meeting to correct the minutes, or add more actions. **Delete Meeting** removes the minutes; the actions stay on people's lists.

Merchandising and Admin record meetings for their buyers; Management can read them.

### 2.4a OCR Summary (no more hand-made pivots)

**Open it:** left menu, **OCR Summary**. Merchandising, Admin and Management can open it; you see only your buyers.

1. Choose a **Buyer** (or All buyers) and, if you want, a **Season**.
2. Pick a tab:

| Tab | One row per | What it shows |
|---|---|---|
| Contract Summary | Order line (style, colour, contract / PO) | Ship date, order qty, materials, % ordered / PI received / paid / received, late materials |
| Material Summary | Material (name, colour, unit) | BOM qty, ordered qty, (short) / excess, received qty and %, lines not ordered yet, vendors |
| Payment Summary | Supplier | POs, PIs, lines without PI, PI amount, paid, pending, next payment due (red if past) |
| Clearance Summary | BL / AWB (ordered material not yet received) | Ship mode, forwarder, suppliers, ETA, committed in-house, arrival status |

3. Press **Download OCR Excel** for the workbook: a **Database** sheet (every material line, every column you may see) plus the four summary sheets. When one buyer is chosen and a column matching is saved for that buyer (Import Buyer Excel, Step 2), the Database sheet starts with the buyer's own columns in the buyer's order and titles.

Good to know: a PI repeated on several lines is counted once in the Payment Summary. (Short) / excess counts only lines that have an ordered quantity. Columns are found by their name, so if a buyer's sheet has no ETA column, the ETA shows "—".

### 2.4a1 Buyer Orders (customer PO register and approval)

One card for each customer PO of a style. Quantity, colours and ship date are shown from the workspace (change them there, as before). The card keeps what the workspace does not hold, and the approval.

**Open it:** open the style, then **Buyer Orders** on the **Orders** box.

**On each card:**
- **Colours, Order qty, Size total, Ship date** are read-only. **Differs** appears when the Size Breakdown total is not the order quantity.
- Fill **Destination**, **Contract / L/C ref.**, **Quotation** (choose one of the style's final costings) and **Remark**, then press **Save**.
- Press **Send for Approval** when the PO is ready. Admin, Management of the buyer and a Merchandising department head are told. The person who sent it does not approve it, unless nobody else could.
- An approver presses **Approve**, or writes a comment and presses **Return**. A returned order shows the reason; correct it and send again.
- If the quantity, a colour, a ship date or a size figure changes after approval, the card shows **Changed after approval**. Send it for approval again.

Management can read and approve but not edit.

**Stopping Supply Chain until the order is approved (Admin).** On **BOM Approvals**, the Settings box has a switch **Supply Chain cannot generate a PO until the buyer order (customer PO) is approved**. It is off by default. When it is on, Supply Chain cannot generate a PO for a customer PO that is not **Approved** on Buyer Orders, or that **changed after approval**, and sees the reason. This includes orders that were never sent for approval, so switch it on only after the open orders have been approved. Rows that are not linked to a style (older files) are never blocked. Buyer Orders shows a blue note while the switch is on.

### 2.4b Samples

**Add a sample:** open the style, in the **Samples** box choose the **Sample type**, and if you want the child style, **Colour**, **Size**, **Qty** and **Needed by** (the buyer's date). Press **Add Sample**.

**Update it:** press **Update** on the sample, change the **Status**, **Sent date**, **Courier / AWB** or **Buyer comment**, press **Save**.

| Status | Meaning |
|---|---|
| Requested | Asked for, not started |
| In progress | Being made |
| Sent to buyer | Sent (the sent date fills in by itself if left empty) |
| Approved / Approved with comments / Rejected | The buyer's verdict |

- A sample not yet sent whose **Needed by** date has passed shows **Late** in red.
- The buyer comment gets today's date when you type it.

**All samples:** left menu, **Samples**. Every sample of your buyers, late ones first; filter by buyer, status (default: open ones) and type. Press the style number to open the style.

**Sample types:** at the bottom of the Samples page. Press a type to switch it off (not offered for new samples) or on; type a **New sample type** and press **Add Type**. Proto, Fit, Size Set, PP, Photo, TOP and Shipment are there to start with.

Management can see samples but not change them.


**Tag ID.** Every sample gets a Tag ID when it is added, for example **SMP-26-0012**. Write it on the physical tag so the sample and the system can always be matched. It is shown under the sample type everywhere and never changes. Samples that already existed received one too.

**History and rework.** Press **History** on a sample to see every step: each change of status and each new buyer comment, with the date and who recorded it. A sample the buyer **rejected** or **approved with comments** counts as **sent back**; the list shows "Sent back 2x" under the status. When you press **Save** on an update, the system adds a history line only if the status or the buyer comment is new, so saving the same thing twice adds nothing.

**Buyer Comments (Lessons).** On the Samples page press **Buyer Comments (Lessons)**. It lists what buyers said when samples were sent back, newest first. Filter by buyer and search a word of the comment, a style number or a product group (capital letters do not matter). Look here before making the next sample for the same buyer. You see only your own buyers.

Not built yet for samples: ordering the sample materials, sample costing and SAM.
### 2.4c Documents (document bank)

Keep the style's papers with the style: tech pack, approved lab dip, PP comments, test report, buyer e-mail.

**Add a document:** open the style, go to the **Documents** box. Press **Choose file** (PDF, picture, Excel, Word, PowerPoint, text or zip; up to 15 MB). Type a **Title** if you want (otherwise the file name is used) and a **Category** (pick a suggestion or type your own). Press **Add Document**.

**Open a document:** press **Download** on its line. **Delete** removes it for good; ask before you delete a paper others may need.

Merchandising and Admin can add and delete. Management can download but not change. Only people who may see the buyer can open its documents.

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
5. The bar at the bottom of the page stays in view while you scroll. It shows the **Total cost** as you type, with **Save Costing** and **Finalize Revision** next to it. Press **Save Costing**. The cards at the top show **Total cost, Quoted price, Profit and Margin** (profit as a share of the quoted price).

**Finalize and revise**
- When the costing is agreed, press **Finalize Revision 1** (save first). A final revision **cannot be edited**.
- To change it, press **New Revision**. Revision 2 starts as a copy and is a draft until you finalize it. Old revisions stay as they were; the **Revision history** table at the bottom compares total cost and margin of every revision.
- Only one draft can exist at a time.

**Approval**
- Pressing **Finalize Revision** sends the costing for approval. The Merchandising Head (department admin), Management and Admin get a message.
- The approver opens the costing, writes a **Comment** if needed and presses **Approve Costing**, or **Return to Merchant** (a comment is required). The person who finalized cannot approve it themselves while someone else can.
- **Returned** puts the revision back to draft with the reason shown at the top; correct it and finalize again. The merchant is told either way.
- A revision finalized before approval existed shows **Not sent for approval** with a **Send for Approval** button.

**Pre-costing vs BOM**
Once the style has a BOM, the costing page shows each costed material next to the BOM material with the same name: the BOM consumption per garment (with wastage, times 12 for a dozen), the **PI rate** Supply Chain recorded, the BOM amount and the **Difference** (red = the BOM costs more than the costing). Costed lines with no BOM material say "Not in the BOM"; BOM materials nobody costed are listed as "Not costed".

**Who can do what.** Merchandising and Admin make and change costings. Management can open and read them. Other roles cannot.

The **Season page** of Style Status shows the **Margin** of each style's latest *final* buyer costing.

---

## 3. Supply Chain

### 2.7a Costing templates (start a costing from a ready sheet)

A costing template is a **ready cost sheet** for a product group, with the usual lines, quantities, wastage and rates, so you do not type them for every style.

**Make one:** left menu, **Costing Templates**, **New Template**. Choose the **Buyer** (Admin may choose **All buyers**), type the **Product group** (empty = every group), the currency and whether costs are per piece or per dozen, then add the lines under their cost head and press **Save Template**. **Active** switches it on or off.

**Or save one from a costing:** open a style's Pre-costing, go to **Save as template** at the bottom, type a name and press **Save as Template**. It copies the lines, rates, currency and basis of the saved sheet (not the quoted price). Press Save on the costing first if you changed it.

**Use one:** on a style that has no costing yet, **Start a template** shows the templates that fit the style, best first (the buyer's own for the style's product group, then the group's for every buyer, then general ones). Choose one, or **No template (standard rows)**, and press **Start Buyer Costing**. The amounts and the total are worked out for you; change any line as usual.

The lines are **copied**: changing or deleting a template later never changes a costing already made. Lines for **All buyers** can be changed by Admin only. Management can read the templates.

Post-costing of materials is built (section 2.7b); full post-costing (CM, overhead, shipped quantity) is not.


### 2.7b Post-costing (what the materials really cost)

Pre-costing is the estimate. Post-costing looks at what actually happened. **Material post-costing** compares the material cost of the style's **final buyer costing** with what Store has received and issued.

**Open it:** on the style page press **Post-costing** (Orders box), or on the Pre-costing page.

**The four figures at the top:**
- **Material budget:** the material cost of the latest final buyer costing, per piece, times the order quantity (a dozen sheet is divided by 12).
- **Received:** every goods receipt (GRN) of the style's materials: received quantity times the unit price Store booked. Shown with the share of the budget.
- **Issued to production:** bulk issues, valued at the average price that material was received at.
- **Bought, not issued (in stock):** received minus issued. Money tied up in material that has not been used.

A green or red note says how far the material bought so far is below or above the budget. **Below is normal while the order is still being received.** Under it, **By material** lists each material with its received and issued quantity and value. Yellow badges warn about a material **received with no price** (its value counts as zero; add the price on Material Receiving) or **issued but never received** against this style.

**What this page does not know yet:** CM, overhead, commission, claims and the quantity actually shipped are not recorded in the system, so the real **profit** of an order cannot be worked out. Prices on receipts have no currency; they are taken to be in the costing's currency. Management can read the page; it changes nothing.
### 3.0 Supply Chain dashboard

**Open it:** log in as Supply Chain; the dashboard is the first page.

It shows every material line of your buyers' orders, from booking to arrival. The numbers come from the BOM workspace columns Supply Chain fills in, and from the same reading as **Style Status**, so both pages agree.

**Tiles (top row)**
- **Booking Pending**: material with no PO yet. The red line says how many are past their *Order to be placed by* date.
- **PI Pending**: a PO is placed but the PI is not received.
- **Payment Pending**: the PI is received but the payment is not done. The red line counts those past *Payment Req'd Date*.
- **In Transit**: shipped (an *ATD* is filled) but not yet arrived (no *ATA*).
- **Arrival Overdue**: not arrived and *Committed ETA* has passed.
- **PRA Pending**: payment requests waiting for check or approval.

**Chips (second row)**: late bookings, bookings due in 14 days, arrivals due in 7 days, order lines, booking POs generated, emails sent.

**Charts**
- **Material Pipeline**: how many material lines are at each stage, with the share booked, PI received, paid and received.
- **Open Work by Buyer**: booking, PI and payment pending lines for each buyer.
- **Arrivals by Committed ETA**: lines still to arrive, by month. The red bar is overdue.
- **Waiting on Vendors**: the eight vendors with most lines ordered but not received.

**Lists**: **Late Bookings**, **PI Pending** (oldest PO first) and **Arrival Overdue** show the eight worst lines. Fill the missing columns in the **Workspace** and the line leaves the list.

**Good to know**
- A line counts as arrived when an *ATA* is filled or the store has received it.
- If a column is empty the line is simply not counted in that step: for example no *Committed ETA* means it never shows as overdue.
- If you are assigned to some buyers only, you see those buyers' orders and files with no buyer.
- "No order lines are linked yet" means Merchandising has not imported a BOM yet.


- **Merchant changed an order:** you get a message (bell icon) and the PO shows **Source changed**. Open the PO and use **Regenerate** before sending it to the supplier.
- **BOM not approved:** if Admin has switched the gate on, you cannot generate a PO for a style whose BOM is not approved. You are told which style. Ask Merchandising to get it approved (section 2.6). You are told when it is approved.
- **You raise a PO or a payment request (PRA):** the Workspace columns PI Status, PI Amount, Payment Required Date, Payment Status and order status update **by themselves**. Nobody has to press Save in the Workspace.

### 3.1 PO Generate: the pending list

**Open it:** left menu, **PO Generate**, tab **Pending Preview**.

**One row is one booking.** Source rows that will go on the same PO are shown as one row:
- Rows with a **SAP Code**: the same vendor and the same SAP Code (styles and buyers may differ).
- Rows without a SAP Code: the same buyer, season, IHOD (ship date) and vendor.

The row shows how many source rows it holds, up to three of their items, and the total quantity (the stored **Materials to be Ordered** figures). A row that already has a PO is never merged.

**Rows per page.** The list shows 25 bookings per page. Use **Rows** next to the search box to show 50 or 100. The total at the bottom counts bookings, not source rows, and a booking never appears on two pages.

**Why a page can have few rows.** If many rows have no vendor and no SAP Code, they merge into one booking per ship date. Fill in **Vendor Name** and **SAP Code** in the Workspace and they split into separate bookings.

**Who sees what.** The list and its filters (**Buyer**, **Season**, **SAP Code**, **Vendor**, **IHOD**) show only the buyers you are assigned to, plus files that have no buyer set. A booking never pulls in rows of a buyer you cannot see. If you still see a buyer you are not assigned to, that buyer's file has no buyer set: ask Admin to tag the file.

### 3.1a Materials Ordered, Booking Qty and PO status

- **Materials Ordered** (Workspace, Supply Chain column) fills itself with the **Materials To Be Ordered** figure. You can type a different quantity over it at any time. While you have not changed it, it follows Materials To Be Ordered if that figure changes.
- The **Booking Qty** on the PO (booking format) is taken from **Materials Ordered**.
- **Material Order Status** shows **PO Pending** until a PO number exists. When you press **Generate PO**, the **Material PO Number** and **PO Date** (today) are written to every line of that PO in the Workspace, and the status changes to Short PO Qty, PO Raised or Excess Qty PO by comparing Materials Ordered with Materials To Be Ordered.

### 3.x Attaching documents to a payment request (PRA)

When you press **Create PRA** on the preview screen, the box has a **Supporting documents (optional)** field.

1. Click **Choose files** and select the PI copy and any other related files. You can pick up to 10 files (PDF, image, Excel, CSV or Word), 10 MB each.
2. Press **Create PRA**. The files are saved with the request.
3. Everyone who reviews the request (Merchant, Accounts, Commercial, approvers) sees a **Supporting Documents** box on the request page with a **Download** button for each file.

**Resubmitting a rejected PRA.** In **My PRA Status**, press **Resubmit**. The box lists the documents already attached: tick **Tick to remove** on any you want to drop, and use **Supporting documents** to add new or corrected files (for example an updated PI copy). Then press **Resubmit**.

### 3.x Supplier Delivery (who is late)

**Open it:** left menu, **Supplier Delivery**. It is read-only and changes nothing; use it each morning to decide whom to call.

**Where the dates come from.** For every generated PO the system takes the **promised date** from the PO's workspace row: **Committed In-house**, else **Committed ETA**, else **Committed Ex-mill** (the first one that is filled). A PO counts as **delivered** when **Actual In-house** is filled, or when Store has received **98% or more** of the PO quantity (the delivery date is Actual In-house, else the date of the last receipt).

**Each PO line is one of:**
- **Overdue:** not delivered and the promised date has passed. It shows how many days late.
- **No date given:** not delivered and nobody entered a promised date. Ask the supplier and fill it in.
- **Open, not due yet.** A line that has had part of the quantity shows "partial".
- **Delivered on time** or **Delivered late** (and how many days).

**The tiles** count overdue lines, lines with no date, open lines and the percentage delivered on time. **Suppliers** lists each supplier with PO lines, delivered, overdue, no date, open, the on-time percentage and the average days late; the ones with most overdue lines come first. Press a supplier to see its lines, or choose what to **Show** and search a supplier, PO number, style or item. **View PO** opens the booking PO.

You see only the POs of the buyers you hold. Management and Admin can read it too. To fix a date, change it in the workspace or on **Inbound Shipments** (the shipment writes Committed ETA and in-house dates to the PO).

## 3a. Commercial

### 3a.1 Inbound Shipments (imported material by BL / AWB)

Record each import shipment once, with its route and dates and the POs it carries, instead of typing the same shipment details in many workspace lines.

**Open it:** left menu, **Inbound Shipments**. The tiles show how many are planned, in transit, arrived, cleared and past their ETA (press a tile to filter). Filter by status, mode or search a shipment number, BL / AWB, container, forwarder, vessel or port.

**Add a shipment:** press **New Shipment**, choose the **Buyer**, then fill what you know: **Mode** (sea, air, road, courier), **BL / AWB no.**, BL status, **Forwarder**, vessel or flight, container numbers, **port of loading**, **port of discharge**, country of origin, and the dates. Press **Save Shipment**. It gets a number such as SHP-26-0012.

**The status is not typed; it follows the dates:**
- no departure date yet: **Planned**
- **ATD** (left) entered: **In transit**
- **ATA** (arrived) entered: **Arrived**
- **Customs cleared** date entered: **Customs cleared**
- **Actual in-house** date entered: **In-house**
A shipment whose **ETA** has passed and that has not arrived shows **Late**. A later date cannot be before an earlier one (for example, cleared before arrived).

**Add the POs it carries:** open the shipment, type part of a PO number, vendor, style or item of that buyer under **Find a PO to add** and press **Add** (the PO quantity is filled in; change it if only part of the PO is on this shipment). One shipment can carry many POs and a PO can come in several shipments. **Remove** takes a PO off the shipment; the PO itself is not changed.

**The workspace follows:** when you save, the **BL / AWB no**, **Committed ETD**, **Committed ETA**, **Committed in-house** and **Actual in-house** columns of the PO's workspace row are filled, for the columns the workspace has (Admin names them on Excel Headers). A value that is already there is left alone; every change is logged like a manual edit.

### 3a.2 Clearance Summary

On Inbound Shipments press **Clearance Summary**. It shows what is not yet in-house **by port**, what is **arriving by expected week (ETA)**, the count by status, and three lists to chase: shipments whose **ETA has passed**, shipments that **arrived 3 or more days ago and are not customs cleared**, and shipments **cleared but not yet in-house**.

Commercial and Admin record shipments for the buyers they hold; Management can read them.

## 4. Accounts

### 4.0 Accounts dashboard

**Open it:** left menu, **Dashboard**. It answers three questions: what must be paid, what is late, and where every payment request stands. It reads the same requests, and counts "paid" the same way, as the **Payments** screen (4.1), so the two always agree. Amounts are the PI amounts as entered; the system does not hold a currency on them.

**Top row (money):**
- **Awaiting payment:** the amount of approved requests not yet paid, and how many requests.
- **Overdue:** the part of that whose required date has passed.
- **Due in 7 days:** the part needed in the next week.
- **Paid:** requests whose payment reference is saved or whose lines all have a Pmt Doc No.
Press a tile to open Payments.

**Second row (requests):** payment requests raised, pending approval, approved, and the workspace columns you own.

**Charts and lists:**
- **Amount to pay, by when it is needed:** bars for Overdue, This week, Next week, In 2 weeks, In 3 weeks, Later and No date. A request with no required date goes under **No date**; fill the required date on its lines so it is planned.
- **Where the requests stand:** a ring of pending approval, awaiting payment, part paid and paid.
- **Owed to suppliers:** the five suppliers with the most unpaid amount, with a red bar and an "overdue" tag when some of it is late.
- **Pay these first:** the five oldest overdue requests, with how many days late. **Open Payments** goes to the screen where you record the payment.
- **Payment requests raised, last 6 months** and **Your share of the BOM**, as before.

You see only the requests of the buyers you hold.

### 4.1 Payments screen

**Open it:** left menu, **Payments**.

- **Awaiting payment** lists approved payment requests that are not yet paid, with supplier, amount and required date. **Paid** lists the paid ones.
- To record a payment: type the **Payment reference no.** in the row and press **Record Payment**. The number is written to **Pmt Doc No** on every line of that request, and Payment Status becomes **Pmt Done** everywhere.
- A request that is **still at its payment step in the approval flow** shows **Open PRA** instead. Complete it there (see below); this screen never skips an approval step.
- A request also counts as paid if someone already typed the Pmt Doc No in the Workspace.

### 4.2 Payment step of a payment request

At the last step of a payment request there is a box **Payment reference no. (optional)**. If you fill it in, it is written to every line of the request. If you leave it empty the request still completes, but Payment Status stays Pmt Pending until a reference is recorded.

## 5. Store

What you receive and issue also shows in the **Style status** screen (Received, Issued).

Left menu order for Store: **Purchase Requisition, Receiving, Issues, Stock Report, Items, Setup**.

### 5.1 Purchase requisition: from a section's sheet to the final approval

A department or section raises one of two kinds of requisition. On the Purchase Requisition page press **New Monthly Requisition** or **New Individual Requisition**.

| | Monthly | Individual |
|---|---|---|
| Use it for | The regular monthly needs | A one-off, urgent or special purchase |
| After the head checks it | The Store receives it and **merges** it with the month's other sheets | The Store receives it. It is **not merged** |
| Then | Final Requisition: Store Head, Finance & Commercial Head, Operation Head | Finance & Commercial Head, then Operation Head approve this same sheet |

Stock figures (Stock in Hand, Safety Stock) are shown to everyone who can open an **Individual** requisition. The Store can still correct quantities as before. Every department requisition shows a **Monthly** or **Individual** badge in the lists. Above the list on **Purchase Requisition** and **Requested**, three tabs - **All**, **Monthly** and **Individual** - show each kind on its own, with the number of requisitions in each. The kind is saved with the requisition, so the Category Summary shows Monthly and Individual sheets separately.

**Stock is checked again when the Store receives a requisition.** Stock can change between the day a requisition is raised and the day the Store receives it. When the Store presses **Received**, the system reads today's **Stock in Hand** and **Safety Stock** for every item and works out **To Be Procured** and **Amount** again. Example: 2 requested with 4 in stock showed 0 to buy; if the item is out of stock when the Store receives it, it now shows 2 to buy. Quantity requested and rate do not change. A note under the items says the day the stock was updated.

**Requested By and Raised By.** The requisition list shows **Raised By** first, then **Requested By**, in the order it happens. **Raised By** is the person who entered the requisition in the system. **Requested By** is always the head it then goes to (the section head, or the department head when the section has none).

**Stock Summary on the Purchase Requisition page.** The Store Head and Admin see a Stock Summary card above the list: totals, the filters and a chart of the top items. The item-by-item table is not shown on this page, so the requisition list comes straight after. To see every item, use the card's **Excel** or **PDF** download.

**When can a Monthly requisition be submitted?** A Monthly requisition can be submitted only from the **20th to the end of each month**. A department may raise more than one Monthly requisition for the same month, but each must be submitted in this period. The Store then receives and merges them into that month's Final Requisition. If you press **Submit** outside this period, a message tells you to raise an **Individual** requisition instead. You can still **Save as Draft** on any day. An Individual requisition can be submitted on any day of the month.

Admin and the Store Head can change this period. Open **Purchase Requisition > Monthly Window** (or **Admin > Settings > Monthly Requisition Window**). There you can switch Monthly requisitions on or off, set the opening and closing day (enter 0 to close on the last day of the month) and, if you wish, type your own message.

There are two kinds of sheet.

**1. The department / section sheet (3 steps)**

```
Prepared (section staff) -> Checked by Section / Department Head -> Received by Store
```

1. Section staff open **Purchase Requisition**, fill the items and press **Submit Requisition** (or **Save as Draft** to finish later).
2. The Section Head (or the Department Head if there is no section) finds it under **My Approvals** and presses **Approve**, or **Return** with a note so the requester can correct it and submit again.
3. Any Store user opens the **Requested** tab and presses **Received**.

Store users do not raise sheets for a section. Each section raises its own.

**2. The final sheet (one per month)**

The same items come every month, so the Store joins one month's received sheets into one final sheet.

1. In **Requested**, tick the received sheets of **one month** (marked **Ready to Merge**) and press **Merge into Final Requisition**.
2. Check the list on **Review Before Merge** and press **Create Final Requisition**. The Store user who merges is shown as **Prepared by**.
3. The final sheet then goes through the approvers set by Admin, normally:

```
Store Head -> Finance & Commercial -> Operation Head (final approval)
```

Each approver finds it under **My Approvals** and presses **Approve** or **Return**. The final sheets are listed under the **Final Requisitions** tab.

If a final sheet is deleted or cancelled, its department sheets become free to merge again.

**The Final Requisition by category.** The final sheet lists its items by category, the largest spend first (the same order as the **Category Summary**), with a **Category** column after **Type**. After each category a highlighted **Category Sum** row shows that category's quantity, quantity to buy and amount. **PDF** and **Excel** download the sheet as it reads. **PDF by Category** and **Excel by Category** add one page or sheet per category after it, as the Monthly Report does. Excel is set for A4 landscape, one page wide, with the table heading repeated on every page. The Excel is made for a black-and-white printer: black text on light grey headings, black borders on every cell, and no merged cells, so you can select and copy any cell. The signature boxes at the bottom run from the left edge to the right edge of the page. In the Excel each box has three lines: the caption (for example Prepared by), the name in bold italic, and the designation. The signing date is on the PDF and next to the name in the top block. Item rows are tall enough to read and write on. The **Monthly Report** (PDF and Excel) has the same plain look: light grey headings, black text and borders, no merged cells, and the signature boxes spread edge to edge. To print a requisition straight from the screen, open it and press **Print**; it prints the same document as the **PDF** button, without saving a file first.

The **Category Summary** is built from the same lines, so its category amounts and total are the Final Requisition's figures. The **Monthly Report** recalculates stock across sheets, so its totals can differ slightly.

**Stock Report: Stock by Unit.** A total quantity across Piece, Kg, Carton and Box means nothing, so the Stock Report no longer shows one. The **Stock by Unit** card shows the three units with most items and their stock; **View all** lists every unit. It follows the filters (month, category, supplier, status). A unit written several ways (Pcs, Pieces, Piece) is counted as one, under the spelling most items already use, and new items are saved with that one spelling. The Total row of the table is unchanged.

**Large requisitions.** A Final Requisition can have well over a hundred lines. The form sends the lines as one package, so every line, the quantities and the Submit button always reach the server. The Store Head can prepare a Final Requisition; their own Store Head step is then approved when they submit, and Finance & Commercial Head and Operation Head approve it as usual.

**3. An Individual requisition**

```
Prepared (section staff) -> Section / Department Head -> Received by Store -> Finance & Commercial Head -> Operation Head (final approval)
```

It is never merged. The approvers are set by Admin in **Admin > Requisition Signatures**, under **Individual Requisition**. HR Audit is not part of the Monthly (final) or the Individual steps. An Admin can still add it back in Requisition Signatures. Sheets already on their way keep the steps they started with.

**To Be Procured.** The system works out the quantity to buy for each item: **Qty Requested − Stock in Hand + Safety Stock**, as a round figure. Example: Qty 420, Stock 180, Safety Stock 42.54 (shown as 43) → 420 − 180 + 43 = **283**. If the stock already covers the request and the safety level, To Be Procured is 0. **Individual requisitions do not add Safety Stock**: To Be Procured is Qty Requested − Stock in Hand (the Safety Stock is still shown for reference). Amount = To Be Procured × Rate. All quantities on a requisition (screen, PDF and Excel) are shown as round figures; rates and amounts keep 2 decimals.

**Who sees stock figures.** Hand stock, safety stock and the other stock details on a requisition are shown only to Store, Admin and Management. Other departments see their items and quantities only.

**Admin: who approves.** Admin sets the steps and the person for each step in **Admin > Requisition Signatures**. Nothing in the code needs to change when a person changes.

### 5.1b Editing a long requisition (a Final Requisition with hundreds of lines)

- The page opens in a couple of seconds, however many lines it has. The item list of a line is filled in when you first click that line's **Item Name** box; until then the box simply shows the chosen item.
- Stock, safety stock and last purchase of every line come in one go when the page opens (and again if you change the **Requisition Date**).
- **To Be Procured** = Qty requested − Stock in Hand + Safety Stock (rounded), never below 0, and **Amount** = To Be Procured × Rate. The **Total Amount** under the lines uses exactly this rule, so it is the same figure the saved requisition and its View page show. (It used to leave Safety Stock out, which made the total on the edit page smaller than the saved one.)
- If you change a line's **Category** after choosing its item and the item is not in that category, the item is cleared and you must choose it again.

**"You are the Store Head approver for this sheet" when submitting.** The person who prepares a sheet cannot approve it. The Store Head preparing the Final Requisition is allowed (the Store Head step then counts as approved by them). If Admin set the Store Head box on **Requisition Signatures** to a named person, that person is allowed the same way as long as they are the Store department head. Any other approver preparing their own sheet is refused: ask another person to prepare it. Admin can give someone that job without changing their role (section 5.1a).

### 5.1a Giving someone the Final Requisition or the Category Summary

Admin can give a person these two Store requisition tasks without changing their role.

**Open it:** **Admin, Users**, open the person, in the permission list find **General Stock**.
- **Purchase Requisition, Prepare Final Requisition**: the person can raise the Final Requisition, see the **Requested** queue and every requisition, and merge the departments' sheets, like the Store team. They also see the stock figures on the lines.
- **Category Summary (view + export)**: the person can open **Category Summary** and download its PDF and Excel. Nothing else of the Store's desk opens with it.

In the permission list each section (Items, Receiving, Purchase Requisition and so on) is one line with a labelled box for every action it has, so nothing hides off to the right. **Select all** on a line, or at the top of a module, ticks every box you may change there; it turns into **Clear** when all are ticked. Boxes that come with the role stay locked.

Both start with **Purchase Requisition, View** (they need it to reach the screen). Untick a box to take the task away again. The Store, Admin and Management roles already have them.

### 5.2 Item Master: adding and approving items

1. A Store user adds a new item in **Items**. It shows **Pending Approval** and cannot be used until it is approved.
2. The **Store admin** gets a message (bell icon), opens **Items** and presses **Approve** (or rejects it).
3. Items added by the Store admin or Admin are approved straight away.
4. The Store admin and Admin can **Edit** an item. A change to the item name or unit shows everywhere the item is used (stock, receiving, issue, requisitions, reports), because every screen reads the same item.

### 5.3 General Stock purchasing: Comparative Statement, Internal PO and receiving

This is for the **Supply Chain General** desk: the people who buy the factory's general stock (consumables) after a requisition is approved. It is separate from the buyer-order Supply Chain (BOM, booking, PI, payment request). A Supply Chain General user does not see those screens.

**The flow**

```text
Final or Individual requisition approved
  -> To Purchase (items waiting)
  -> Comparative Statement (CS): compare suppliers, choose one per item
  -> CS checked, then approved
  -> Internal PO (booking with an internal PO number)
  -> PO authorised
  -> Store records the goods "Against PO"
```

**1. To Purchase.** Open **General Stock Purchasing, To Purchase**. It lists every item of an approved Final or Individual requisition that is not on a Comparative Statement yet, with the quantity left to buy and the last rate. Tick the items (or the whole requisition) and press **Make Comparative Statement**. An item you have not ticked stays in the list. If a requisition has no items here, everything on it is already on a statement.

**1a. Long monthly lists (200 to 500+ items).** The list is grouped by **category** (Electrical, Stationery, Cleaning and so on), because each supplier type gets its own Comparative Statement. Use the **Category** filter to look at one, or press **Make CS for [category] only** to start a statement for just that category in one click. You can still tick single items, a whole category (the box in its heading) or **Select all shown**. Items with no category appear under **Uncategorised**.

**2. Make the Comparative Statement.**
1. **Suppliers compared.** Two supplier columns are open. Pick a saved supplier from **From supplier list** (name, phone and address fill in) or type one. Add terms such as "Excluding VAT. One month credit along with work order." Press **Add supplier** for a third or fourth (up to 4). Tick **Recommended supplier** on the one the committee prefers.
2. **Items and rates.** For each item enter the quantity (never more than is left on the requisition), the specification or brand, and each supplier's **unit price**. The lowest price on an item turns green. The circle beside a price chooses that supplier for that item: it is ticked for you when you type the first price, and you can change it. **Choose lowest price for every item** and **Choose recommended supplier** do it for all items at once.
3. **Entering many prices.** Use **Find an item**, the **Category** filter and **Show: Items still missing a price** to work through the list. The line on the right counts how many items each supplier has a price for. Press **Copy item list** and paste into Excel to send suppliers the items in the order shown. When a quotation comes back in that order, copy its price column in Excel, click the first price box under that supplier and press **Ctrl+V**: every price drops into its row (copy two columns to fill two suppliers at once; text such as "Tk 1,040" is read as 1040). **Enter** moves down to the next item. The statement prints with a heading row for each category.
4. Write the **Recommendation from committee members**, then press **Save Draft**, or **Save & Send for Checking**.

Sending for checking needs at least 2 suppliers and a priced supplier chosen on every item. The statement number looks like **CS-26-27/0001** and restarts every financial year (July to June). You can split one requisition item over two statements, but never ask for more than it has left. **Print / PDF** gives the form on A4 landscape with the form code, the supplier columns, the chosen suppliers highlighted, the terms and the three signature boxes: **Jr. Executive - SCM**, **Manager - SCM**, **Approved By**.

**3. Check and approve.** The statement moves **Draft, Waiting for Check, Waiting for Approval, Approved**.
- **Mark as Checked** is the Manager - SCM step. **Approve** is the final step, with an optional approver note (for example "take approval from internal audit before purchasing").
- **Send Back** returns it to the person who made it with a note saying what to fix; they correct it and send it again.
- **Alerts.** Nobody has to ask for a check or approval. When a statement is sent for checking, everyone with the **Comparative Statement: Check** right gets an alert (the **Alerts** button, top right) that opens the statement. When it is checked, everyone with the **Approve** right gets one, and the person who made it is told. They are also told when it is approved or sent back.
- The person who made a statement can never check or approve it. One person may do both the check and the approval if Admin gave them both.
- **Cancel Statement** (with a reason) puts its items back on **To Purchase**. A statement that already has a purchase order cannot be cancelled until the PO is cancelled.

**4. Make the Internal PO.** On an approved statement press **Make Purchase Order**. One PO is made for each chosen supplier, as a draft, with the quantities and rates of the statement. The number looks like **PO-26-27/T-081**, and its last part (**T-081**) is also printed as the PO's Reference No. Open the PO and press **Edit** to change the date, reference, BIN, consignee, terms, remarks and each line's **Due on**. Quantity and rate cannot be changed here: they are what was approved. **Print / PDF** gives the purchase order on A4 portrait with the amount in words and **Checked By** and **Authorised Signatory**. A draft prints with "DRAFT: not yet authorised".

**5. Check and authorise the PO (two approvals).** A new PO is a **Draft**. Approval 1: someone with the checking right, who did not make the PO, presses **Check PO** (the Checked By box); it becomes **Checked**. They can press **Send back** to return it to draft if something is wrong. Approval 2: the Authorised Signatory, who is neither the maker nor the checker, presses **Authorise PO**; it becomes **Issued**. Store can receive only against an Issued PO. When every item of a requisition is on an issued PO, the requisition shows **Purchase Action Taken**; cancelling a PO puts it back to **Approved**. A PO that Store has received goods against cannot be cancelled. The printed PO carries the Humana logo and shows DRAFT or CHECKED until it is authorised.

**6. Store receives against the PO.** On **General Stock, Receiving, Record Receiving** choose **Against PO** (optional). The supplier and one line for every item still to come are filled in, with the quantity left and the PO rate. Change the quantity to what actually arrived and the rate to the invoice rate if it differs, then save as usual. The system refuses a PO from another supplier, an item that is not on the PO, and more than the PO still expects. The PO shows **Partly Received** then **Received**, and its page shows what is still to come. Goods with no PO can still be received as before: leave **Against PO** empty.

**Dashboard.** On **General Stock, Dashboard** anyone who can open Comparative Statements or Internal POs sees a **Purchasing** panel above the stock figures: items waiting to be purchased, statements waiting for check or approval, POs waiting for check or authorising, POs open for receiving, a chart of statements and of POs by stage, the ordered value per month, the waiting items by category (click a category to open it on To Purchase) and the latest POs. Each tile opens the list it counts.

**Checking Receiving without entering it.** A person who has only the **Receiving: View** right opens **General Stock, Receiving** and sees a **Receiving summary** (deliveries, items, value, suppliers and POs awaiting delivery this month) and the full **Purchase History** to check. The **Record Receiving** form, **Import** and drafts are hidden; they appear for people with the **Create / Entry** right (and for the Store, Admin and Management roles).

**Who can do what (Admin).** Admin sets this in **Admin, Users, Portal Access** (or the permission list under **General Stock**):
- The **Supply Chain General** role can see **To Purchase**, make and edit Comparative Statements, and make and edit Internal POs. It cannot check, approve or authorise.
- **Comparative Statement: Check**, **Comparative Statement: Approve** **Internal PO: Check** and **Internal PO: Authorise** are given to the right people one by one. The switches can be on in any mix: for example Approve alone stays on after you save, even though Check and the PO switches are off.
- Store needs no purchasing right to receive against a PO. The form code printed on the CS, the consignee and BIN printed on the PO, the PO number code (T) and the first number of the year (`PURCHASING_PO_FIRST_NO`, so the system carries on after the last hand-booked PO) and the minimum number of suppliers are in `config/purchasing.php`.

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

### 6.4 TnA plans (Time and Action)

A TnA template is a ready list of tasks for an order, for example "Fabric booking 60 days before ship date (Supply Chain)", "PP sample approval 30 days before ship date (Merchandising)".

**Make a template (Admin):** left menu, Admin, **TnA Templates**, **New Template**.
1. Type a **Template name** and choose a **Buyer** (or All buyers).
2. For each task type the **Task**, the **Days** (negative = before, positive = after), **From** (Ship date or Order received) and **Given to role**. **Add Task** adds a row.
3. Tick **Apply automatically every morning** if every style of that buyer should get the plan by itself. Press **Save Template**.

**Critical steps:** on each template task, tick **Critical** for the steps the shipment depends on (fabric in-house, PP approval, cutting start). Tasks made from the template keep the mark.

**Apply it to a style:** open the style, in the **TnA Plan** box choose the template and press **Apply TnA Plan**.
- One follow-up task is made per template task, due on the anchor date plus the days. It goes to someone with that role who can see the buyer: you, if you have the role, otherwise the person with the fewest open tasks.
- Each person gets one message. The tasks appear in **Follow-ups** with the same red / yellow / green lights and the 8:30 reminder.
- Applying again only adds tasks that are not there yet. A task that cannot be made (no ship date yet, nobody with the role) is named in a yellow message.
- The **TnA Plan** box shows every TnA task of the style with its status and "done / total".

**Critical path.** In the style's **TnA Plan** box a green, amber or red line shows where the critical steps stand: **On track**, **Watch** (one is due within 2 days), **At risk** (one is overdue) or **Complete**, with how many are done and the next one due. Critical tasks carry a **Critical** badge.

**Overdue e-mail and escalation (Admin).** On **Admin > TnA Templates**, the **Alerts** box has two settings:
- **E-mail the daily list of overdue tasks:** when ticked, the morning reminder is also sent by e-mail to people who have an e-mail address on their profile. It is off until you tick it, and it needs mail to be set up on the server. If the mail server fails, the in-app reminder still arrives.
- **Tell the supervisor after (days overdue):** a task that stays overdue for this many days is reported **once** to the supervisors (Admin, Management of the buyer, and a Merchandising department head), never to the person who owns it. The task then shows **Escalated**. Type 0 to switch this off. The default is 3 days.

Both run with the 08:30 morning reminder (Dhaka time). Press **Save Alert Settings** after a change.


---

## 7. For IT / Admin

| Task | How |
|---|---|
| After pulling new code | `php artisan migrate`, then `php artisan view:clear` |
| Fill the standard order tables for files that were uploaded before | Done once by itself on the deploy that added the Merchandising dashboard. To run it again by hand: `php artisan bom:sync-tables --dry-run` to see what would happen, then `php artisan bom:sync-tables` |
| Show each buyer only the columns it uses on the workspace | **Admin > Buyers**, **Edit** the buyer, **Choose Columns**. Press **Use Saved Matching** to tick the Merchandising columns from the buyer's saved import matching, or tick by hand, then **Save Columns**. On that buyer's files the workspace says "Showing the N columns … uses"; anyone can press **Show All Columns**. Nothing is deleted. |
| See, rename, copy or delete a buyer's saved import matching, and set how its mother and child styles are worked out | **Admin > Buyers**, **Edit** the buyer, **Choose Columns**, then **Import Profiles**. Change a profile's name, **Mother style comes from** and **Fit words**, press **Save**; **Copy** and **Delete** are on each profile. Files already imported are not changed. To change a matching itself, import a file with the new layout and save it again. |
| Teach the import another name for a column (a buyer calls "Wastage" something new) | **Admin > Excel Headers**, **Edit** the column, type the buyer's name under **Other Names**, **Update Header**. It is matched automatically in every buyer's file from then on. |
| Choose which columns appear in a style's **Order Changes** box | **Admin > Excel Headers**, **Edit** the column, tick **Show in Order Changes**, **Update Header**. Order qty, ship date, contract, colour, BOM qty and consumption start ticked. |
| Let another department edit a column (for example shipment columns for both Merchandising and Commercial) | **Admin > Excel Headers**, press **Edit** on the column, tick the roles under **Also Editable By**, press **Update Header**. The owner role can always edit; untick all for owner only. The list shows "Also editable by …" or "Owner Edit Only". |
| A big import or a PDF export stops with "Something went wrong" (500) | Look in `storage/logs/laravel.log` for "Allowed memory size". The default PHP limit is 128 MB. The import raises its own limit to 512 MB for that request; for PDF exports set `memory_limit = 512M` in the PHP container's `php.ini` and restart the container. |
| Clean up styles left over from a deleted test file | Deleting a file does not delete its styles: the Style Library is shared by all files. Open **Style Library**, choose the buyer and season. A style with a green "N order line(s)" tag is in use, keep it. A style with no tag has no orders: press **Delete** on it. |
| Turn the second (Management) BOM approval or the PO gate on or off | Screen: **BOM Approvals**, Settings box (Admin only). Both are off by default. |
| Give a person the General Stock purchasing sign-offs (check a CS, approve a CS, check a PO, authorise a PO) | **Admin > Users**, open the person, **Portal Access**, switch on **Comparative Statement: Check**, **Comparative Statement: Approve** or **Internal PO: Authorise**. The role **Supply Chain General** (created by the deploy; an existing role with that name is reused) has view and create only. |
| Change the CS form code, the PO consignee / BIN, the PO number code or the minimum suppliers | `config/purchasing.php` (or the `PURCHASING_*` values in `.env`) |
| Switch off automatic refresh of Workspace columns from PO / PRA | `.env`: `SUPPLY_CHAIN_SYNC_WORKSPACE=false` |
| Switch off automatic filling of the standard order tables | `.env`: `BOM_SYNC_TABLES=false` |
| Make TnA tasks from the auto-apply templates by hand | `php artisan tna:apply` (the scheduler runs it every morning at 08:15, Dhaka time) |
| Send the daily follow-up reminders by hand | `php artisan followups:remind` (the scheduler runs it every morning at 08:30, Dhaka time; the server's scheduler must be running) |

On the live server, every push to `master` runs the migrations by itself, and the one-time `bom:sync-tables` fill too. If the Merchandising dashboard stays empty after a deploy, look in the log for "Backfilling order_lines" and run `php artisan bom:sync-tables` by hand.

---

## 8. Common questions

**Who makes the BOM? Is it created by the system after I upload the Excel?** The **merchant** makes it: either on screen with **Create BOM** (section 2.2a) or in an Excel file that is uploaded or imported (section 2.2). The system does **not invent** materials or consumption; they come from the merchant (from the buyer's tech pack or the costing). Once the BOM is in, the system takes over: it arranges every line under the right style, season and colour, **calculates** the formula columns, and passes it on to BOM approval, PO, payment, receiving and issue. Filling the materials from standard figures is built: see the Consumption Master (section 2.2b). Fully automatic BOM, without a merchant checking it, is not built and not planned.

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
| CS | Comparative Statement: supplier rates compared item by item before a purchase |
| Internal PO | The purchase order for general stock, made from an approved CS; Store receives against its number |
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

Fully automatic BOM (the Consumption Master only proposes), the rest of TnA (mobile app, tasks at PO level), planning, commercial (LC / export documents) extensions, shipment documents, SAP FICO-style accounting, HR. These will be added to this manual as they are delivered.
