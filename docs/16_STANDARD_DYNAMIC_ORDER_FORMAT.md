# Standard Dynamic Order Format (buyer-independent)

Decision (2026-10-20, owner): **do not copy any one buyer's Excel or folder layout.** Ann Taylor / Macy's files were only examples of how different buyers work. The system has ONE standard format; each buyer's Excel is converted into it by a saved, user-managed *import profile*. Process and UI follow the Kandaree Apparel ERP standard: data entered once, shared by every department.

## 1. Golden rules

1. **Standard first, buyer second.** Tables, screens, reports and exports speak the standard names below. Buyer wording lives only in import profiles and column synonyms.
2. **No buyer-specific code or screens.** A new buyer must be onboardable by a non-developer in minutes: add buyer -> add season -> upload Excel -> confirm column mapping once -> done.
3. **Enter once, share everywhere.** Style, PO, color/size and material data entered by Merchandising must appear in Purchase, Store, Commercial, Accounts and Management without re-typing.
4. **Reuse what exists.** `ExcelHeader` (dynamic columns, owner role, formula fields), `ExcelFile/Row/Cell`, buyer scoping, PRA, Store, Commercial and Accounts screens stay. Extend by additive migrations; never fork the upload logic per buyer.
5. **Preview before save.** Every import shows what will be created/updated, flags problems in plain English, and never half-saves.

## 2. Standard flow (Kandaree-aligned) and where the system stands

| # | Stage | Standard content | Status in this system |
|---|---|---|---|
| 1 | Buyer / Season | buyer master, season, product group | Buyer master exists; **Season added (Style Library)** |
| 2 | Style | Mother style -> Child style (fit variant / linked no.) | **Added (Style Library)** |
| 3 | Order / PO | buyer PO, color, size, destination, qty, ship date | Rows exist inside BOM upload; no standard PO table yet |
| 4 | Pre-costing | cost heads: fabric, trims, CM, overhead, commission; revisions | Missing |
| 5 | BOM | per style/color/size: material, consumption, wastage, requirement | BOM upload + workspace exist (header-driven) |
| 6 | Approval | multi-stage approval for costing / BOM | PRA approvals exist for requisition; BOM approval missing |
| 7 | Purchase | BOM-based PO/PI, vendor, MOQ, within budget | Booking / PO generate / PRA exist |
| 8 | Receive & Store | GRN, QC, stock, location, issue vs BOM | Store module exists |
| 9 | Commercial | contract/LC, BBLC, export invoice, documents | Commercial module exists |
| 10 | Shipment | booking, docs, ETD/ETA, completion | Partly in BOM columns |
| 11 | Accounts | payment, ledger; later SAP FICO-style journals | Account module exists |
| 12 | Management | status by buyer/season/style, profit, delays | Dashboards exist; style-wise roll-up missing |

Always report which stage a change belongs to, and keep upstream data flowing downstream.

## 3. Standard data model: real tables + workspace sheet as a synced view

Decision (2026-10-20, owner): the 82-column BOM sheet is too complicated to keep as one flat set of custom headers. Split it into **proper tables, one per business area**. The Merchant Workspace sheet stays (users like it), but it becomes a *view* of those tables, and editing in either place updates the other.

### 3.1 Tables (each owned by one department)

| Table | One row is | Owner | Sheet columns it feeds |
|---|---|---|---|
| `order_lines` | child style x color x PO | Merchandising | style, color, PO no, ship date, order qty |
| `bom_items` | one material requirement of an order line | Merchandising | material type/description/code/color, BOM qty, consumption, wastage %, requirement |
| `material_orders` = **existing `booking_pos`** (do not create a second PO table) | one PO / booking | **Supply Chain** | vendor, currency, material PO no/date, ordered qty, rate |
| PI / payment = **existing `payment_requests` + `payment_request_items`** (PRA, check -> approve) | one PI / payment request line | Supply Chain (raises) / Accounts (approves) | PI no/status/amount, HS code, payment term, payment doc/status |
| `shipments` | one shipment leg of a material order | Commercial | ship mode, forwarder, BL/AWB, port, vessel, ETD/ETA (committed vs actual) |
| `receipts` | one receiving against a material order | Store | inhouse dates, receipt qty, invoice no/qty/amount |
| `issues` | one issue/return against a bom_item | Store | bulk issue, sample issue, return, liability |

Chain: `buyer -> season -> mother_style -> child_style -> order_line -> bom_item -> material_order -> (PI, payment, shipment, receipt)`; `issues` hang on `bom_item`.

Why this is better than one flat sheet: partial deliveries, several POs or several shipments for one material become normal rows instead of squeezed cells; each department edits only its own table (matches roles and buyer scope); reports and the Kandaree-style "Status of Material" are plain queries; a new buyer's extra columns attach to the right table as custom fields instead of widening one sheet.

### 3.2 Field registry (the bridge)

One table `workspace_field_map`: `excel_header_id` -> (`table`, `column`, `aggregate`). `aggregate` says how a many-to-one table shows in one sheet cell: `latest`, `sum`, `min`, `max`, or `first`. Example: Ordered Qty = `sum(material_orders.qty)`, Actual Inhouse = `max(receipts.received_on)`, Receipt Qty = `sum(receipts.qty)`.

Headers with no mapping stay **sheet-only** exactly as today, so migration can be done department by department and nothing breaks half-way. Computed columns (requirement, excess/short, liability, status) stay formulas (`formula_key`) and are never stored in the tables.

### 3.2b Supply Chain comparison (done 2026-10-20)

PO/Booking (`BookingController`, `booking_pos`), PI/PRA (`PaymentRequestController`) and `BookingPoSourceService` already behave as the Procurement tables of this model, and `MaterialStockLedgerCellSyncService` is the existing precedent for "table is the source, workspace cells follow" (never blanks a cell, skips locked files, writes as the system user, config switch). Extend these; do not duplicate them. Supply Chain has priority; every Merchant change must reach the departments that build on it.

Known limitation: a PO's change detection compares only the leader BOM row (`booking_pos.excel_row_id`); edits to sibling rows of a batch PO are not detected yet.

### 3.3 Two-way sync without loops

- ONE write path: `FieldWriter::set(bom_item, header, value, user)`. Every screen (table screens AND the workspace sheet) calls it.
- Sheet edit: `FieldWriter` finds the mapping, writes the table, then refreshes the cell. Table-screen edit: writes the table, then refreshes the affected sheet cells. The cell is never the source for a mapped field.
- Where one sheet cell maps to many table rows (sum/latest), an edit in the sheet creates or adjusts a row (for example "Receipt Qty 500" creates a receipt of the difference) and the user sees what was created. Ambiguous edits are refused with a plain message that points to the table screen.
- Every write records user, old value, new value, source (sheet/table/import) in the existing change log; `excel_rows.origin` is reused to prevent echo.
- Permissions: the existing header owner role decides who may edit a mapped field; buyer scope applies to every table through `buyer_id` on `order_lines`.

### 3.4 Migration plan (safe, additive)

1. New tables and `workspace_field_map` only. Nothing existing is altered.
2. Backfill command (dry-run first): read existing `excel_rows/cells` through the field map into tables; report rows it cannot place. Idempotent, can be re-run.
3. Switch one department at a time to table-backed (Merchandising: `order_lines` + `bom_items` first), behind a setting, keeping the old cells as a fallback.
4. After a department is stable, hide nothing from users: the workspace sheet looks the same, only the source changed.

Old standard field groups (Order, Material, Sourcing, Proforma & Payment, Shipment, Receive, Issue & Balance) remain the grouping of sheet columns and map 1:1 to the tables above. Buyer-only extra columns become *custom fields* on the matching table (`custom_values` JSON column), never a new table per buyer.

## 4. Import profile (Phase 2 spec)

Table `buyer_import_profiles`: `buyer_id`, `name`, `sheet_name` (or "first sheet"), `header_row`, `style_column`, `created_by`, plus `buyer_import_profile_columns`: `profile_id`, `source_header` (text in buyer file), `excel_header_id` (standard field), `ignore` flag.

Upload wizard (3 steps, plain English):
1. **Choose** buyer, season, file. Profile auto-picked if the file's headers match a saved one.
2. **Match columns**: system auto-matches by name/synonym; user only fixes the red ones (dropdown of standard fields, or "Skip", or "Add as custom column"). One click "Save for next time".
3. **Preview**: counts of new/updated/skipped rows, styles detected (mother/child via `StyleCodeParser`), errors listed by row. Confirm to save.

Rules: reuse the matching helpers already in `ExcelUploadController` (header normalisation, duplicate detection); detect styles into the Style Library automatically; multi-sheet files import the sheet named in the profile; one file may hold many styles (lot files) and must be split by style on read; never overwrite another department's columns.

## 5. Standard status vocabulary

Use the same words on every screen and report:
`Draft -> Submitted -> Approved -> Ordered -> Shipped -> Received -> Issued -> Closed`, plus `On Hold` / `Cancelled`. Material view per style: Required / Ordered / In-house / Issued / Balance, with a traffic light (green on track, amber due soon, red late) like Kandaree TnA.

## 6. User-friendly UI and system rules

- One screen per job; buyer and season are always the first two filters; remember the last selection.
- Plain English labels, short messages that say what to do next. No technical words (header_key, excel_row, profile_id) on screens.
- Every button has a text label (icon-only is not allowed).
- Tables: search, sort, sticky header, export to Excel/PDF with the same data as on screen.
- Errors are fixable on the spot (inline, per row); no dead ends.
- Management view = counts and colours first, details on click.
- Role-wise: each role sees its own columns first; scope by buyer using existing helpers.

## 7. Procedure for every future task in this area

1. Read this file and `docs/15_...`; name the stage (section 2) the task belongs to.
2. Inspect existing routes/controllers/models/migrations/views before designing.
3. Keep it buyer-independent; if you catch yourself naming a buyer in code, stop and move it to data.
4. Additive migration (MySQL + PostgreSQL safe), nullable fields, tests for role + buyer scope.
5. Test with at least two different buyer layouts (the three Ann Taylor Denim lots plus one differently-shaped file).
6. Update the status table in section 2 and the phase table in docs/15 in the same change.
7. Report: summary, changed files, how to test, risks.

## 8. Backlog order (one phase per task)

**Done:** Step 1 — Merchant -> Supply Chain: a workspace save re-checks the POs built from the edited rows (`BookingController::refreshSourceControlForRows`), flags them "Source changed", and `BookingPoChangeNotifier` sends Supply Chain one notification naming the PO, the fields and any payment request already holding it (once per new change set). Tests: `tests/Feature/SupplyChain/BookingPoChangeNotifierTest.php`.

**Done:** Step 2 — Supply Chain -> Merchant/Store/Accounts. Finding: PO no / PO date are already written to the workspace at PO generation, and PI Status, PI Amount, Payment Required Date, Payment Status and order-status cells are already calculated from the PO and PRA by `ExcelFileController::recalculateFile` — but only when someone next saved the workspace. `SupplyChainWorkspaceSync` (observers on `BookingPo`, `PaymentRequest`, `PaymentRequestItem`) now queues the file whenever a real (non-preview) PO or PRA is created, changed (po_no/status/vendor/qty; PRA status) or removed, and recalculates it once per request after the response is sent. Whole file, not rows: the formulas depend on row order. Switch: `SUPPLY_CHAIN_SYNC_WORKSPACE=false` restores refresh-on-save. Tests: `SupplyChainWorkspaceSyncTest.php`.

Not done on purpose: a PO edit of vendor/qty is NOT written back into the Merchant-owned Vendor / BOM cells (that would overwrite Merchandising's data; the PO and the BOM may legitimately differ and Step 1 flags the other direction). A PRA approval writes no payment document number — that is entered by Accounts.

**Next:** Step 3 — Store and Accounts read the same PO/PI/payment data without re-typing (check Store receiving and Accounts screens against `booking_pos` / PRA), then the phases below.

Phase 2 `order_lines` + `bom_items` tables, field registry, `FieldWriter`, Merchandising columns table-backed with two-way sync and backfill -> Phase 3 import profile + 3-step wizard writing through `FieldWriter` -> Phase 4 `material_orders` (Procurement) -> Phase 5 `receipts` + `issues` (Store) -> Phase 6 `proforma_invoices`, `payments`, `shipments` (Commercial/Accounts) -> Phase 7 pre-costing with revisions, BOM approval -> Phase 8 style-wise material status and traffic light -> TnA -> Sample -> Planning -> SAP FICO-style Accounts integration.
