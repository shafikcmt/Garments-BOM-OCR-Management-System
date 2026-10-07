# ERP Implementation Guide — How to Grow This System into the Full Garments ERP

> Working guide for Claude and the owner. `09_FUTURE_ERP_ROADMAP.md` is the
> target (what a full garments ERP holds). This file is the route: where the
> system stands today, what must be built first, the agreed order, and the
> decisions still open. Read both before starting any new module, and use
> this file to guide the owner — suggest the next step, ask the open
> questions, and push back when a request would build on the wrong base.
>
> Written 2026-10-07 from a gap analysis of the codebase against the spec.
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
   structured BOM; Excel upload imports into it.
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

## 9. How Claude should guide the owner

- At the start of ERP work, state which phase/step the request belongs to
  and whether its prerequisites exist; if not, say what must come first.
- Propose the smallest shippable slice of a step, with screens, tables
  (additive, nullable migrations) and approval/audit points.
- Keep MySQL/PostgreSQL compatibility, role safety and export rules from
  CLAUDE.md for every module.
- After a phase step ships, update §2 and §5 of this file.
