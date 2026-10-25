# ERP Implementation Guide — How to Grow This System into the Full Garments ERP

> Working guide for Claude and the owner. `09_FUTURE_ERP_ROADMAP.md` is the
> target (what a full garments ERP holds). This file is the route: where the
> system stands today, what must be built first, the agreed order, and the
> decisions still open. Read both before starting any new module, and use
> this file to guide the owner — suggest the next step, ask the open
> questions, and push back when a request would build on the wrong base.
>
> Written 2026-10-07 from a gap analysis of the codebase against the spec;
> §10 (Merchandising & OCR plan) added 2026-10-25.
> Update it when a phase ships or a decision is taken.

## 1. Owner's intent

The owner wants this software to become the full garments ERP in
`09_FUTURE_ERP_ROADMAP.md`, module by module, without breaking the live
workflow. Small controlled releases; every push to `master` is a live deploy
(see CLAUDE.md › Deployment).

## 2. Where the system stands (gap analysis, 2026-10-07)

| # | Spec module | Status | What exists today |
|---|---|---|---|
| 1 | Merchandising | Partial | Excel/BOM upload, Merchant workspace, buyer-wise scope, Style Budget. Orders, styles and BOM are **Excel rows/cells, not structured tables** |
| 2 | Sample | None | — |
| 3 | Costing | Minimal | Style Budget only |
| 4 | TnA | None | — |
| 5 | Procurement | Good part | Booking PO, PO generate control, Payment Request (PRA) multi-approval, General Stock Purchase Requisition (stage engine + month-wise merge) |
| 6 | Store & Inventory | Strongest | General Stock (Item Master, SKU, Receiving, Issue, Ledger, reports) and Buyer/Style stock (Receiving, Bulk Issue, Closing, Liability/Dead). Missing: lot/shade/roll, rack/bin, QC gate, FIFO/avg costing |
| 7–10 | Planning, IE, QA/QC, Subcontract | None | — |
| 11 | Commercial (L/C) | Minimal | Commercial workspace and reports; no structured Master/BTB L/C |
| 12 | Shipping | None | — |
| 13 | Accounting | Minimal | Accounts workspace, payment approvals; no GL/AP/AR |
| 14 | HR & Payroll | None | Thin `employees` table (Employee ID login) — seed of PIMS, extend it, never duplicate it |
| 15–16 | Fixed Assets, Maintenance | None | — |
| 17 | CRM / Buyer | Partial | Buyer master, buyer assignment |
| 18 | Reporting / BI | Partial | Role-wise dashboards, store/merchant/commercial reports |
| 19 | System Admin | Strong | Users, roles, permissions, departments, approval settings, email templates, alerts, activity log |

## 3. The foundation problem — read this first

The system's core is an **Excel workspace**: a BOM is uploaded as Excel and
lives as `excel_files` / `excel_rows` / `excel_cells`. Almost every spec
module (Auto BOM, BOM-based PO, BOM-based issue, costing, planning,
shipping) assumes structured **Buyer → Style → Order/PO → Color/Size →
BOM line** tables.

Therefore the first ERP build is an **Order & BOM Core**, with the Excel
upload kept as an import path into it (merchandisers keep their habit).
Do not build Costing, Planning, TnA or Shipping on top of Excel rows — steer
the owner back to the core if a request would do that.

A **Material Item Master** (fabric, trims) is needed alongside it; Buyer/Style
stock currently has no item master at all.

## 4. Build vs integrate

- **Build here** (garments-specific, poorly served by off-the-shelf tools):
  Merchandising, BOM, Procurement, Store, Commercial, Shipping, TnA,
  Production tracking, QC, Maintenance.
- **Prefer integrating a ready product**: HR & Payroll (Bangladesh labour
  law, tax, attendance devices) and full Financial Accounting (GL, VAT,
  audit). This system feeds them (export / auto journal), it does not
  re-implement them. Confirm with the owner before starting either.

## 5. Agreed phase order

**Phase 1 — Foundation**
1. Order & BOM Core: Style / Order / PO master, color-size breakdown,
   structured BOM; Excel upload imports into it. Detailed as steps O1–O6
   in §10 (Merchandising & OCR plan).
2. Material Item Master + codification (see §6).
3. Procurement link: BOM line → material requisition → Booking PO.
4. Store upgrade: BOM-based receive/issue for Buyer stock, lot/shade/roll,
   rack/bin; General Stock attribute-based SKU (Needle / Spare Part pilot).

**Phase 2 — Control & visibility**
5. TnA (task templates, traffic light, overdue email — reuse notifications).
6. Costing: pre-costing → BOM costing → post-shipment actual (extends Style Budget).
7. Commercial: Master L/C, BTB L/C, limit monitoring, export docs, realization.
8. Shipping: packing list, commercial invoice, color/size export invoice.

**Phase 3 — Production**
9. Production tracking (cutting / sewing / finishing / packing output, WIP).
10. QC: incoming material QC (store QC gate), inline / end-line / final AQL.
11. Planning board and IE (SAM, line balancing) — largest, last in phase.
12. Subcontract (wash / print / embroidery job out-in).

**Phase 4 — Support**
13. Maintenance + Fixed Assets, linked to General Stock spare parts.
14. Sample Management.
15. HR/Payroll and Accounting integrations.

**Phase 5 — Intelligence**
16. Management BI: OTD %, order profitability, stock aging, L/C exposure.

## 6. Item codification (DATATEX reference, received 2026-10-07)

The owner shared five DATATEX "Finalized Confirmation Item Codification"
workbooks (from another group's ERP — Indonesian descriptions, "Eratex"/"UGG"
sheets). Structure: 3-letter Item Type + sub-code segments (each a coded
master list, ~5 chars) + auto sequence + technical specs + Basic/Purchase UOM.

| File | Item Type | Belongs to |
|---|---|---|
| Spare Part | `SPR` — Part, Brand, Machine Category, Machine Type, Grade, Seq | General Stock |
| Needle | `NDL` — Type, Brand, Specialization, Seq, Point, Size | General Stock |
| Asset | `NFA` / `AST` / `NCM` — Group, Area, Product Group, Brand, Type, Seq | General Stock / asset register |
| Chemical & Dyes | `CHE` / `PCM` — Group, Function, Product, Type, Seq | General Stock |
| Production Material | `FAB`, `INS`, `TRM` — fabric/trim attributes, Buyer ID# | Buyer/Style material stock |

Decision so far: do **not** copy DATATEX wholesale (codes too long, huge
masters). Adopt the idea: 3-letter item type, attribute masters per category,
dependent dropdowns, duplicate-item warning, short SKU plus structured
attributes. Existing SKUs (`StockItemSku`, default `{CAT:2}-{NAME:3}-{SEQ:4}`)
never change; a new format applies to new items only. Pilot on Needle, then
Spare Part. Production Material waits for the Phase 1 Material Item Master.

## 7. Business rules already settled (do not re-litigate)

- **Purchase Requisition (General Stock):** department sheet = Prepared →
  Section/Department Head → Received by Store (any Store user). The Store
  merges ONE month's received sheets into a final sheet; the merging Store
  user is "Prepared by"; final = Store Head → HR Audit → Finance & Commercial
  → Operation Head (final approval). Approvers are maintained in Admin >
  Requisition Signatures. The same items repeat monthly, so reports are
  month-based and merged demand counts once in its own month.
- Stock figures (in hand, safety, consumption, last purchase) are visible to
  Store, Admin and Management only.
- Store users do not raise department sheets on a section's behalf (owner
  declined this, 2026-10-07).
- Reusable primitives to build on: Department / Section / Head hierarchy,
  `RequisitionReviewChain`, the stage engine (`RequisitionWorkflow`),
  `AppNotification`, activity log, PDF/Excel export pattern.

## 8. Open questions to ask the owner before Phase 1

1. Is the factory cut-and-sew only, or also knitting / dyeing / washing?
   (Decides Production, Subcontract, Chemical scope.)
2. Does the BOM always arrive in the buyer's Excel format, and does the
   format differ by buyer? (Decides the import design.)
3. Which software runs HR/Payroll and Accounting today (Tally or other)?
4. Will the system ever exchange codes with a DATATEX ERP? (If yes, item
   codes must match exactly.)
5. Is the full OCR uploaded today, or only the merchant's columns (the code
   uploads a "Merchant Input" template)? Which file is uploaded in practice?
6. Is a size breakdown (XS–XXL) present on every buyer's PO (HB's "PO
   Summary" has it)?
7. Does management read the OCR summary sheets (Contract / Payment /
   Material Summary, Style Status) regularly? If yes, O5 can move earlier.
8. Is the HB-style "OCR" order-closing cost sheet made for every buyer, or
   only HB?

## 9. How Claude should guide the owner

- At the start of ERP work, state which phase/step the request belongs to
  and whether its prerequisites exist; if not, say what must come first.
- Propose the smallest shippable slice of a step, with screens, tables
  (additive, nullable migrations) and approval/audit points.
- Keep MySQL/PostgreSQL compatibility, role safety and export rules from
  CLAUDE.md for every module.
- After a phase step ships, update §2 and §5 of this file.

## 10. Merchandising & OCR plan (analysed 2026-10-25)

### What the OCR is

The factory calls its order-wise material control workbook the **OCR**.
Samples analysed: `03. OCR - ANNTAYLOR SU-26`, `04. OCR - MACYS SP26`,
`OCR -HB-FA-26`.

- The main **"Database"** sheet is the same company format for every buyer,
  not a different format per buyer: ANN TAYLOR and MACY'S have the identical
  74 columns; HB has the same 74 plus Label, INV No, Value, Dead Stock
  Qty/Value. The header sits on row 3 (row 4 in HB).
- One row = one material of one style-color-contract, carrying its whole
  life: order → BOM → booking → PI → payment → inbound shipment → receipt →
  issue → liability. Five or six departments fill the same row.
- What changes between buyers/seasons/years is small: column names
  ("Style Name" ↔ "Style", "For Formula" ↔ "For Formulea"), header row
  position, a few added columns.
- Extra sheets (Contract Summary, Payment Summary, Material Summary, Style
  Status, Clearance Summary, PO Summary) are hand-made pivots the system can
  generate. HB's PO Summary holds the color × size (XS–XXL) PO quantities.
- HB's **"OCR" sheet** is a different report: Order → Cut → Sew → Finish →
  Ship quantities and budget vs actual cost, CM and efficiency — an order
  closing / post-costing report (spec Module 3, post-costing).
- HB's Database sheet reports ~1,048,000 rows because formatting runs to the
  end of the sheet; an import must stop at the last row with data.

Today the system stores this as the Excel workspace (`excel_files` /
`excel_rows` / `excel_cells`, role-owned `excel_headers`). The merchant
downloads a "Merchant Input" template of their own columns and uploads it
(header on row 1, matched by name); other departments fill their columns
on screen. `BookingPo` and the material stock ledger read from those rows.

### Where the 74 columns belong

| OCR columns | Structured record | Entered by | Exists today |
|---|---|---|---|
| Style, GMTS Color, Contract No, Contract Shipment Date, Contract Qty, PCD, Order-by date, GMTS PO No/Qty, size breakdown | **Order** (Style → Contract/PO → Color × Size) | Merchandiser | No |
| Material Type, Description, SAP Code, Material Color, BOM Qty, Costing YY, Initial Consumption, Wastage %, Consumption incl. YY, Materials to be Ordered | **BOM line** — "to be ordered" calculated by the system | Merchandiser | No (row text only) |
| Vendor, Vendor Type, Consolidator, Materials Ordered, (Short)/Excess, Order Status, PO No/Date, PI No/Status, HS Code, PI Rate/Amount, Committed Ex-Mill | **Booking PO / PI** | Supply Chain | `BookingPo` |
| Delivery Term, Payment Term, Pmt Doc No, Payment Status | **Payment** | Accounts / SC | PRA |
| Ship Mode, Container, Forwarder, BL/AWB, BL Status, COO, Port, Vessel, Container No, ETD/ATD/ETA/ATA, Arrival Status, Committed/Actual In-house | **Inbound Shipment** | Commercial | No (columns only) |
| Invoiced Qty/Rate/Amount, Receipt Qty, Garment Cut Qty, Issued, Return to Store, Production Consumption/Wastage, Requirement, Excess/Shortage, Liability, Dead Stock | **Store receive/issue + calculated liability** | Store | Material stock |

### How format changes are absorbed

1. A fixed internal field list (canonical fields) — buyer files never rename
   what the system stores.
2. **Import mapping profiles** per buyer/season (Admin screen): on first
   upload the file's headers are shown and matched to canonical fields;
   the mapping is saved and reused.
3. Synonyms per field, so known variants match automatically.
4. Header row detected automatically (row 1, 3, 4…); import stops at the
   last row holding data.
5. An unknown column never blocks the upload: it is reported ("3 new
   columns not mapped") and either mapped or kept as an extra field.
6. Columns the system calculates (to be ordered, short/excess, requirement)
   are compared with the file's values and mismatches flagged.

### Steps (replace the generic phases in `08_MERCHANDISING_MODULE_IMPLEMENTATION.md`)

| Step | Work | Why |
|---|---|---|
| **O1** | Season + Style + Order masters, created from existing workspace data; **Style 360° page** (rows, Booking POs, budget, receipt/issue status) | Low risk, new tables only; immediate management value |
| **O2** | **Smart OCR import**: mapping profiles, synonyms, header detection, preview + validation before saving | Ends the format-change confusion |
| **O3** | **Structured BOM line**, system-calculated to-be-ordered, short/excess, requirement; workspace rows link to BOM lines (nullable) | Formula errors stop |
| **O4** | Department screens (Merchandiser / Supply Chain / Commercial / Store) over the same line | Replaces one giant sheet |
| **O5** | **Auto reports**: Contract, Material, Payment, Style Status, Clearance Summary | No hand-made pivots |
| **O6** | **OCR export** per buyer/season in the familiar layout | Excel stays available; the system is the source |
| Later | Inbound Shipment module; Order Closing / post-costing (HB "OCR" sheet); pre-costing with revisions | Towards the full ERP |

Principle: data is entered in the system; Excel is for import (at first)
and export (reports). Each step ships, is tested and goes live before the
next; O3 and O4 must keep the existing BookingPo, PRA and material stock
flows working unchanged.

Recommended start: **O1 then O2** — O1 touches no existing flow, O2 solves
the most painful problem. Ask the §8 questions 5–8 before designing O2/O3.
