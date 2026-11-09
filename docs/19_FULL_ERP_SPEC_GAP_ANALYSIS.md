# Full Garments ERP Spec vs This Project — Gap Analysis and Development Guide

> Source: "Full Garments ERP — Module Structure & Feature Specification"
> (19 modules, Word file kept by the owner). Compared with the code on
> 2026-11-09. This file is the **checklist for future development**: it says,
> module by module, what is built, what is pending, and what to build next.
>
> Related files: `09_FUTURE_ERP_ROADMAP.md` (target), `12_ERP_IMPLEMENTATION_GUIDE.md`
> (route, owner decisions, month plan), `18_MERCHANDISER_WORKFLOW_GUIDE.md`
> (merchant detail), `17_USER_MANUAL.md` (living manual).
> Update this file when a slice ships. Legend: **Done** / **Partial** / **None**.

## 1. Spec principles vs this project

| Spec principle | This project |
|---|---|
| Single source of truth, no double entry | **Partial.** Style > Order line > BOM line is structured, but the Excel workspace (`excel_rows/cells`) is still the source of truth; `order_lines`/`bom_items` are filled from it (one way). Each department still types into workspace columns |
| Modules integrated in real time | **Partial.** Merchant > Supply Chain > Accounts > Store flow through the workspace. No link to Production, Shipping, Accounts GL |
| Transaction-driven data capture | **Partial.** Booking PO, PRA, receiving, issue are transactions. Shipment, QC, production are not |
| Approval layer + audit trail on every step | **Partial.** Approvals: BOM, costing, PRA, requisition, CS/PO. Activity log exists. No approval on order, sample, shipment |

## 2. Module-by-module status

| # | Spec module | Status | Built today | Pending (from the spec) |
|---|---|---|---|---|
| 1 | Merchandising | **Partial (~70%)** | Buyer > Season > Mother/Child style; Import Buyer Excel with per-buyer profiles; Create BOM, copy BOM, material library; BOM approval; colour x size breakdown; order change log; Style Status; Merchandising dashboard | Buyer **inquiry** and quotation; standalone **buyer PO / order booking** screen; **auto BOM** (colour/size/destination-wise); size-wise costing; multi-stage approval on the **order** itself; meeting tracker and **document bank**; auto e-mail/SMS to departments; revenue / margin / capacity analytics |
| 2 | Sample Development | **Partial (~40%)** | Samples per style: type, colour, size, needed-by, sent/AWB, buyer verdict and comment, late flag; sample types kept on screen | Sample **requisition** (colour/size-wise); material need and ETA for the sample room; **Tag ID** per sample; sample costing and SAM feedback; rework record; sample-to-bulk lessons bank |
| 3 | Costing (Pre and Post) | **Partial (~40%)** | Style Budget; Pre-costing with cost heads, Buyer/In-house mode, revisions, margin, approval, comparison with BOM | Costing groups/templates by product category; size/colour costing; **post-shipment costing** (HB "OCR" order-closing sheet); fixed-cost allocation; profitability by buyer/style/season |
| 4 | TnA | **Partial (~55%)** | Follow-up tasks, to-do list, reminders, late-material lights, TnA templates by lead time, applied by hand or daily automatically, traffic light | **Critical path**; supervisor **escalation**; tasks at PO/PR level (cost-centre); overdue **e-mail alerts**; mobile updates; consolidated to-do per department |
| 5 | Procurement | **Partial (~55%)** | Booking PO, PO control, Payment Request (PRA) multi-approval; General Stock requisition > To Purchase > Comparative Statement > Internal PO > receive against PO | **Booking from BOM "to be ordered" lines** (S1); **PI register** (S2); budget control at PO; MOQ; supplier PO revision; preferred/nominated supplier map; service work order; IOU; **landed cost**; one supplier master (S4) |
| 6 | Store and Inventory | **Partial (~60%, strongest)** | General Stock (item master, SKU, receiving, issue, ledger, reports); Buyer/Style stock (receiving, bulk issue, closing, liability/dead) | **GRN against Booking PO/PI**; **lot / shade / roll**; **QC gate** (only approved stock usable); rack/bin; FIFO / weighted-average costing; return to store; serial/capital tracking; auto financial posting |
| 7 | Production Planning | **None** | — | Everything: master plan, what-if, line calendar, loading, material availability on board, KPI |
| 8 | Industrial Engineering | **None** | — | Operation bulletin, SAM, line balancing, hourly efficiency |
| 9 | QA / QC | **None** | — | Incoming material QC, inline / end-line AQL, final and pre-shipment inspection, defect trends, buyer audit |
| 10 | Subcontract | **None** | — | Work orders for knit/dye/print/embroidery/wash; in/out movement; performance |
| 11 | Commercial (L/C) | **Minimal** | Commercial workspace and reports | Master/BTB L/C, limit monitoring, sales contract, amendments, export docs, FDBP, realization, margin account, cash incentive, **inbound shipment (C1)**, clearance (C2) |
| 12 | Shipping and Logistics | **None** | Shipment dates only in workspace | Booking, packing list (colour x size), commercial invoice, B/L, container plan, status vs TnA |
| 13 | Financial Accounting | **Minimal** | Accounts workspace, payment approvals | GL, AP, AR, chart of accounts, auto journals, aging, P&L / balance sheet. **Integrate**, do not build a full GL unless the owner decides |
| 14 | HR and Payroll | **None** | Thin `employees` table (login) | PIMS, attendance, payroll, compliance. **Integrate** (Bangladesh labour law, tax) |
| 15 | Fixed Assets | **None** | — | Asset register, depreciation, transfer, disposal |
| 16 | Maintenance | **None** | — | Machine master, preventive schedule, breakdown tickets, spares link to General Stock |
| 17 | CRM / Buyer | **Partial (~30%)** | Buyer master, buyer assignment, import profiles | Contacts, communication log, complaints/feedback, order history and performance per buyer |
| 18 | Reporting and BI | **Partial (~50%)** | Role-wise dashboards, Merchandising dashboard, Style Status, OCR Summary + OCR Excel, store/merchant/commercial reports | Management dashboard (R3: on-time booking %, in-house %, liability, payment due); OTD %, profitability, aging; drill-down; BI embed |
| 19 | System Admin | **Strong (~80%)** | Users, roles, permissions (matrix), departments, approvers, e-mail templates, alerts, activity log, column rights per role | Company / branch / factory setup; SMS settings; audit-log screen filters |

## 3. Cross-module flow in the spec vs reality

```
Inquiry (NONE) -> Pre-costing (done) -> Sample (partial) -> Order confirm (partial: via BOM file)
 -> BOM (done) -> Procurement (partial) -> Store receive (partial, no QC gate)
 -> Production + IE (NONE) -> QA/QC (NONE) -> Shipping (NONE) -> Commercial/L-C (minimal)
 -> every step posts to Accounts (NONE: manual) ; TnA runs through (partial)
```

The project covers the **front half** well (inquiry-to-store). Everything after
"material in store" is missing. That is the right order (spec Phase 1), but
the factory floor and shipment half is where the owner's next big value is.

## 4. Where this project is **ahead of** the spec (keep, do not lose)

- Buyer-format-independent **Import Buyer Excel** with saved profiles; the spec has nothing like it.
- **OCR Summary / OCR Excel** generated from data (Contract, Material, Payment, Clearance sheets).
- Per-column, per-role editing rights (Excel Header Control, Also Editable By) and per-buyer column view.
- Requisition signature blocks, maker/checker on CS and PO, printed forms matching the factory's paper.

## 5. Suggested priority (agrees with `12_ERP_IMPLEMENTATION_GUIDE.md` §11.3)

| Order | Slice | Why now |
|---|---|---|
| 1 | **Management dashboard R3** (overall + own department first) | Read-only, no risk, management sees value at once |
| 2 | **Inbound shipment + clearance (C1, C2)** | Commercial has no structured screen; about 15 OCR columns come from it |
| 3 | **Booking from BOM lines + PI register (S1, S2)** | Removes the most workspace typing; opens landed cost, MOQ, budget control |
| 4 | **GRN against booking + QC gate (St1, St2)** | Spec's rule: only QC-approved stock is usable |
| 5 | **Payment calendar + supplier ledger (A1, A2)** | Accounts gets its own screen |
| 6 | **Order / buyer PO screen** with order-level approval | Opens auto BOM, size-wise costing, packing list |
| 7 | **Shipping documents** (packing list, invoice) | Needs 6 |
| 8 | **Post-costing** (HB order-closing sheet) | Needs actual cost from store and shipping |
| 9 | Production tracking, QC, then planning / IE | Largest; only after the front half is closed |
| 10 | HR / Accounting: integrate | Do not rebuild |

Rule while building: one slice per release, additive nullable migrations,
every new screen also writes the matching workspace cells until the workspace
can become read-only for that column group, manual section in the same change.

## 6. Better functionality to add to this kind of software (practical advice)

These are features that garments ERPs (Kandaree, FastReact, PLM tools) are
valued for, ordered by value for a factory this size.

1. **One "Order 360" page.** Everything for an order on one screen: BOM, booking, PI, payment, shipment, receipt, issue, TnA, samples, cost. A merchant should never open six screens to answer "where is this order?". The style page is the start; add shipment and cost blocks as modules ship.
2. **Exception-first dashboards.** Show only what is wrong today: late materials, PI missing, payment overdue, TnA overdue, ship date at risk. Counts are clickable and open the filtered list. Management reads exceptions, not tables.
3. **Traffic light everywhere** (green / amber / red) with one definition of "late" kept in Admin settings.
4. **Notifications that act.** In-app plus e-mail (later WhatsApp / SMS) with a link that opens the exact record: "Fabric for style 748268 is 3 days late". Daily digest per person, not one mail per event.
5. **Approval everywhere, configured on screen.** Order, sample, costing, BOM, PO, PRA, shipment release. Same stage engine, approvers picked in Admin; return-with-comment and full history.
6. **Document bank per style / order.** Tech pack, approved lab dip, PP comments, PI copy, test report, BL. Upload once, visible to every department by role.
7. **Version history of the order.** Quantity / date / material changes with who and when (log exists); push to Supply Chain and Store as notifications so nobody works on an old number.
8. **Material readiness view.** For each order: required, booked, in-transit, received, issued, short. It becomes the "can we start cutting?" answer for planning.
9. **Consumption master and auto BOM.** Standard consumption per product group / size; the system proposes BOM quantity from order qty x consumption x wastage. The merchant corrects, not types.
10. **Costing that learns.** After shipment, compare quoted vs actual cost and show the gap on the next quotation for the same buyer / style type.
11. **Supplier scorecard.** On-time delivery %, rejection %, PI-vs-PO mismatch, from data the system already holds. Feeds nominated / preferred supplier choice.
12. **Mobile-friendly floor screens.** Production output, QC defects and TnA updates will be entered on phones; design the UI cards responsive from the start.
13. **Data safety.** Daily database backup with restore test, soft delete for orders / BOM / POs, audit log screen with filters, permission review every quarter. Live is PostgreSQL; keep every query dual-engine safe.
14. **Performance.** Workspace tables are cell-based; as files grow, keep counting / summing in the database (the recent save fix) and paginate lists.
15. **Master-data hygiene.** One supplier master (S4), duplicate-item warning, item codification (doc 12 §6) before the Material Item Master.

## 7. Merchant side — pending list and next steps

Status after checking the code and `docs/18_MERCHANDISER_WORKFLOW_GUIDE.md` (its §4 table is current):

### 7.1 Built

Style Library (Buyer > Season > Mother > Child), Import Buyer Excel wizard with
profiles, Create BOM, copy BOM, material library, BOM approval, colour x size
breakdown, order change log, Pre-costing with approval and BOM comparison,
Samples, TnA templates (manual + daily automatic), follow-up tasks, Style
Status, Merchandising dashboard, OCR Summary, OCR Excel.

### 7.2 Pending (in suggested order)

| # | Pending item | Spec ref | Size | Needs first |
|---|---|---|---|---|
| M-1 | **Order change > Store notification**: **Done 2026-11-09** (`OrderChangeNotifier`: Store users are told once per style per save when a tracked order column changes) | Merch: auto notifications | Small | — |
| M-2 | **Order / buyer PO screen**: **Slice 1 done 2026-11-09** (`buyer_orders`, Buyer Orders page: per-PO destination, contract ref, quotation link, remark, order approval, Changed-after-approval flag). **Approval gate done 2026-11-09** (Admin switch on BOM Approvals, off by default, blocks PO generation until the customer PO is approved and again when it changes). Still open: create a PO without the workspace, push destination / contract to workspace columns, delivery splits per destination | Merch: order booking | Medium | Interview Q5, Q8 |
| M-3 | **Document bank**: **Done at style level 2026-11-09** (`style_documents`, Documents box on the style page). Still open: per-order / per-PO attachment, department-wise visibility, file preview | Merch: document bank | Small-Medium | — |
| M-4 | **Size-wise BOM and costing**: **Slice 1 and 2 done 2026-11-09** (`style_material_sizes`, Size-wise BOM page; Cost by size on the pre-costing page with a pieces-weighted average; read-only to the workspace). Still open: size-specific CM / price, colour-wise costing, writing the size-wise need into Materials To Be Ordered / booking, size-group ranges | Merch + Costing | Medium | M-2 |
| M-5 | **Consumption master + auto BOM**: **Slice 1 done 2026-11-09** (`consumption_masters`, Consumption Master screen, "Fill from Consumption Master" on Create BOM, learn from a style's BOM; it proposes only, the merchant checks and saves). Still open: consumption by size / fabric width, apply to many styles at once, rules by order quantity or colour, auto-propose on import | Merch: auto BOM | Medium-Large | M-2, M-4 |
| M-6 | **TnA depth**: **Slice 1 done 2026-11-09** (Critical mark on template tasks + critical-path state on the style page; overdue e-mail digest, Admin on/off; escalation to the supervisor after N days, once per task; `followups:remind` runs it). Still open: tasks at PO / PR level (cost-centre), dependencies between steps and a true critical-path calculation, per-user escalation chains, mobile updates, WhatsApp / SMS | TnA | Medium | — |
| M-7 | **Sample depth**: sample requisition with materials, Tag ID, rework, sample costing / SAM, lessons bank | Sample | Medium | Interview Q4 |
| M-8 | **Inquiry and quotation**: **Slice 1 done 2026-11-09** (`inquiries`, Inquiries screen: KPIs incl. win rate, Start Costing makes the style and buyer pre-costing, Record Quotation prefilled from costing, Won / Lost with reason, Cancel / Reopen). **Quotation PDF done 2026-11-10** in a standard format (not yet matched to the company's own quotation; needs a sample). Still open: matching the company's real quotation layout and logo, quotation revisions history, one inquiry for several styles, convert a won inquiry into an order (M-2), e-mail / TnA reminder for a late quote, quoted-vs-actual feed from post-costing | Merch: inquiry | Medium | — |
| M-9 | **Merchandiser analytics**: **Slice 1 done 2026-11-09** (Analytics screen: order value, cost, profit, margin by buyer or season, lowest-margin styles, inquiry win rate; estimates from the final buyer pre-costing, currencies kept apart). Still open: **capacity check** (needs line / machine capacity data, none exists), actual margin from post-costing, revenue by month / ship date, export to Excel / PDF, drill-down by PO | Merch analytics | Medium | M-2, post-costing |
| M-10 | **Costing templates** by product category; **post-costing**: **Templates done 2026-11-09** (`costing_templates`, Costing Templates screen, best-fit template when starting a costing, Save as Template from any costing). **Post-costing still open**: needs actuals (shipped qty, actual material issue / PI cost, actual CM and overhead, claims) that Store, Shipping and Production do not yet hold; first useful step is actual material cost from receipts / issues per style vs the final costing | Costing | Large | Store, Shipping actuals |
| M-11 | **Order progress meeting tracker**: **Slice 1 done 2026-11-09** (`meetings`, Meetings screen: minutes, attendees, next meeting, action items made as follow-up tasks with owner and date, so they reach the to-do list, reminders and escalation). Still open: meeting agenda from late items, attach files (document bank), recurring meetings, e-mail the minutes to attendees, meetings not tied to a buyer | Merch | Small | — |
| M-12 | Open item from doc 12 §12.3: list of file columns with no header; master-data import (vendors from "Dropdown" sheet) | Import | Small | — |

M-1 and M-3 shipped together on 2026-11-09. M-2 slice 1 shipped the same day. M-4 slice 1 (size-wise BOM) and M-5 slice 1 (consumption master) shipped the same day. M-6 slice 1 (TnA alerts and critical path) shipped the same day. M-8 slice 1 (inquiries and quotations) shipped the same day. M-4 slice 2 (cost by size) shipped the same day. Next: the quotation PDF, the open items of M-2, or M-9 (merchant analytics).

### 7.3 Ask the merchandiser before M-2 to M-8

Use the list in `docs/18` §6. The ones that change the build:

- Q5 PO size breakdown on every buyer? (decides M-2 / M-4)
- Q4 Which samples do you track? (decides M-7)
- Q2 BOM source and time per style (decides M-5 value)
- Q7 Top five daily chasing questions (become dashboard blocks)
- Q10 What goes wrong most (re-orders this table)

## 8. How to use this file

- Before any new module: find its row in §2, check "Pending", and confirm the prerequisite in §5 / §7.
- When a slice ships: change its Status here, tick it in `12_ERP_IMPLEMENTATION_GUIDE.md` §2 / §11, and add the section to `17_USER_MANUAL.md`.
- If the owner's request skips a prerequisite (for example Production before the Order screen), say so and propose the smaller step first.
