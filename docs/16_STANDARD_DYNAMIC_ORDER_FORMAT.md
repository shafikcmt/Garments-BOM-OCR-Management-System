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

**Done:** Step 3 — Store and Accounts compared against `booking_pos` / PRA.
- Store receiving already reads PO, vendor, material, qty and PI from `booking_pos` / the worksheet through `BookingPoSourceService` (nothing to retype). Store Ledger already writes back into the sheet.
- Accounts has only a dashboard and the shared workspace; PRA is approved on the PRA screens. The real gap was the payment step: the last step of the departmental PRA chain says "Payment has been recorded" but kept no payment reference, so Accounts typed Pmt Doc No into every worksheet line by hand before Payment Status could turn to Pmt Done.
- Fix: on the final (payment) step the approve form asks for an optional Payment reference no.; `PraPaymentReferenceWriter` stores it on the PRA (`data.payment_reference`) and writes it to Pmt Doc No on every line of the PRA (activity + change log like a manual edit). `SupplyChainWorkspaceSync` (Step 2) then recalculates, so Payment Status shows Pmt Done. Tests: `PraPaymentReferenceWriterTest`, `PraDepartmentalNotificationTest`.
- Not changed: the legacy (flow 1) PRA has no payment step, so Accounts still enters Pmt Doc No in the workspace for those. The reference is optional by design; making it required is a business decision.

**Done:** Accounts **Payments** screen (`/account/payments`, `Account\PaymentController`). Lists approved/at-payment/completed PRAs (buyer-scoped) as Awaiting payment / Paid with amount, lines and required date. "Paid" is read from the workspace (Pmt Doc No filled on all lines) or the PRA's stored reference, so a reference typed in the sheet counts too. Accounts records a reference once; it goes through `PraPaymentReferenceWriter` (same as the departmental payment step), after checks for the Pmt Doc No column owner role and admin file locks. A departmental PRA still at its payment step is NOT payable from here (link to the PRA) so the approval chain is never bypassed. Tests: `tests/Feature/Account/PaymentsTest.php`.

**Done (2026-10-21):** Phase 2a — `order_lines` + `bom_items` as a **one-way read model** of the workspace (`BomTableSync`, migration `2026_10_21_000001`).
- One workspace row = one `bom_item`; rows sharing style + color + customer PO inside a file share one `order_line` (`line_key`). Order lines also link to Buyer -> Season -> Mother -> Child in the Style Library (created on the fly through `StyleCodeParser`) when the file has a buyer and the row has a season.
- The field map is declared once in `BomTableSync` (`ORDER_LINE_TEXT`, `BOM_ITEM_TEXT`, `BOM_ITEM_NUMBER`) on top of the existing header aliases (`BookingPoSourceService`, `HeaderAliasResolver`), so a buyer's different column names are handled by alias lists, not code. This replaces the planned DB table `workspace_field_map` for now; a DB-backed map only becomes worthwhile when users must edit mappings (Phase 3 wizard).
- Kept in step automatically: after a workspace save (changed rows), after an upload (whole file). Switch: `BOM_SYNC_TABLES=false`. Manual/rebuild: `php artisan bom:sync-tables [--file=ID] [--dry-run]` (dry run = real work inside a rolled-back transaction).
- Checked on the real Denim Lot 1 file: 396 rows -> 9 order lines, 396 bom items; style, PO, ship date, order qty, material, SAP code, consumption all read correctly.
- Not built, deliberately: `FieldWriter` / table -> sheet writing. There is no screen that edits these tables yet, and the sheet is still where users edit, so a second write path would only add risk. It becomes necessary with the first table-edit screen.
- Known gap: the real lot files carry no Season column (season lives in the folder name, e.g. OCR_SP27), so their lines stay unlinked to the Style Library until Phase 3, where the upload wizard asks for buyer + season and stores them on the file.
- `custom_values` JSON columns exist on both tables for buyer-only extra columns; nothing fills them yet.

**Done (2026-10-21):** Phase 3 — **Import Buyer Excel** wizard (`Merchant\ImportWizardController`, `ImportColumnMatcher`; entry: Workspace -> Import Buyer Excel). The strict upload (`ExcelUploadController`) is untouched and still serves files in the merchant format; the wizard is a second path for everything else.
- Step 1 Choose: buyer (buyer-scoped, upload permission required), season (free text, e.g. SP27), file. Step 2 Match columns: the data sheet and title row are found automatically (every sheet is scored by how many titles it recognises; real Denim lot: picked `Database`, row 4, out of 6 sheets), each column is matched by name through the existing header aliases, the user fixes the yellow ones from a dropdown or skips them, and can reload with another sheet / title row / saved profile. Step 3 Preview: rows, columns matched/skipped, styles found (mother/child), warnings for an unmatched Style column or required columns, first rows; then Import.
- Only Merchandising input headers are offered (same rule as the plain upload); each system column can be used once; columns left on Skip (other departments) are ignored.
- Saved per buyer as a profile (`buyer_import_profiles`, `buyer_import_profile_columns`): sheet, title row and every column decision, including deliberate skips. The next file of that buyer is matched automatically when at least 70% of the profile's columns are present.
- The season is stored on the file (`excel_files.buyer_season_id`); `BomTableSync` uses it when a row has no Season cell, which closes the Phase 2 gap, so wizard imports link to the Style Library.
- An imported file is stored, calculated (`recalculateFile`), synced to `order_lines` / `bom_items` and announced exactly like an uploaded one.
- **Formulas are never evaluated by the wizard.** Real sheets carry totals such as `SUBTOTAL(9,AI5:AI1048576)` that hang the calculation engine; the value Excel saved is read instead (`extractUploadedCellValue` override).
- Real Denim Lot 1: 1002 data rows imported, ~6 s preview, ~16 s import. Temporary wizard files live in `storage/app/private/excel_imports/tmp` and are removed on import or cancel; abandoned ones are not cleaned automatically yet.
- Visibility only was changed in `ExcelUploadController` (five helpers made `protected` so the wizard can reuse them); behaviour is identical.

**Done (2026-10-21):** Style roll-up (`StyleRollup`, `Merchant\StyleLibraryController::show`, Style Library -> View Status). Per mother style (optionally one child): order lines (color x customer PO, ship date, order qty) and every material with PO, vendor, ordered qty, PI / payment status, received qty and a **stage** (Not ordered -> Ordered -> PI received -> Paid -> Received -> Issued, the furthest reached). Read from `order_lines` / `bom_items` plus the status cells the departments fill in the workspace, so there is no second copy. Light (Late red / Due soon amber, 14 days) applies only to material not yet ordered, from its `order_to_be_placed_by` date. Buyer-scoped. Tests: `StyleRollupTest`. Also started `docs/17_USER_MANUAL.md` as a living manual (see CLAUDE.md rule).
- Limits: stage depends on the workspace columns being named as the aliases expect (PO no, PI Status, Payment Status, Receipt Qty, Bulk Issued); a column the buyer's sheet lacks simply leaves that stage empty. Quantities are not summed across materials (units differ); counts are used.

**Done (2026-10-21):** Season / buyer roll-up — **Style Status** (`Merchant\StatusController`, `StyleRollup::forSeason`). `/merchant/status` lists the seasons of a buyer (or all allowed buyers) with totals, ordered / received bars and late count; `/merchant/status/seasons/{season}` lists every style of a season with its five progress bars, late first; `/merchant/status/styles/{mother}` is the style page again. Read-only and shared by Merchant, Admin and Management (the editable Style Library stays merchant-only); buyer-confined users only see their buyers. One query for the lines and one for the status cells per season, however many styles. The style page and the season page share one `build()`, so their numbers cannot differ. Tests: `SeasonStatusTest`. Manual: section 2.4.

**Done (2026-10-22):** Late-material follow-up, modelled on the Kandaree / garments-ERP **TnA (Time and Action)** standard: task per cost centre (style), user-wise assignee, due date, traffic light, to-do list, auto notification of today's and overdue tasks. Table `follow_up_tasks`; `FollowUps` service (create / finish / reopen / daily reminders), `FollowUpController`, command `followups:remind` scheduled daily 08:30 Asia/Dhaka; screens: style page "Follow-up" card (Merchant / Admin / Management create), `/follow-ups` to-do list (mine, given by me, everyone for Admin / Management), open count per style on the season page, sidebar link with a due-today badge. Rules: assignee must be able to see the buyer; no second open task for the same person and style; change only by assignee, giver or admin; one reminder per person per day. Tests: `FollowUpTest`. Manual: section 6.
- Not built (Kandaree TnA has them): task templates by lead time, tasks created automatically from rules, e-mail reminders (in-app only), mobile app, supervisor escalation, "direct call" to assignee.

**Done (2026-10-23):** BOM approval (docs/08 Phase 6 for the BOM; Kandaree "approval layer"). Per mother style: `bom_approvals` + `bom_approval_steps`; `BomApprovals` service; `BomApprovalController`; `/bom-approvals` queue; style-page card; BOM column on the season page; sidebar link with waiting badge. Flow: merchant submits -> level 1 Merchandising Head (department admins with access to the buyer, plus admin) -> optional level 2 Management (admin switch) -> approved; reviewer can return with a mandatory reason; every step is logged. Rules: the BOM is a **fingerprint** of order lines + material lines taken at submission; an approved BOM that later differs shows as **Changed after approval** and is notified once; a BOM that changed since submission cannot be approved; no self-approval unless no one else could. Hand-off: approval notifies the submitter and Supply Chain. **PO gate** (admin switch, OFF by default): `BookingController::generate`, `bulkGenerate` and the multi-row batch refuse an unapproved style; rows with no style link are never blocked. Uses existing roles / `is_department_admin` / `hasBuyerAccess`, no new permission mechanism. Tests: `BomApprovalTest`. Manual: section 2.6.
- Not built: approval per material or per supplier (Kandaree "item-wise user approvals"), more than two levels, approver chosen per buyer in a screen (today: department admins of that buyer), e-mail notifications (in-app only), pre-costing / order approval (same pattern can be reused).

**Done (2026-10-24):** Pre-costing (docs/08 Phase 2; Kandaree "initial / revision-wise pre-costing, buyer and in-house mode, costing by head"). Tables `costings` + `costing_lines`; `Costings` service; `CostingController`; `/costing/styles/{mother}`; style-page card; Margin column on the season page. A costing is one sheet in one **mode** (buyer / in-house) and one numbered **revision**; lines sit under a cost head (`Costings::HEADS`: Fabric, Trims, Embroidery/print, Washing, Testing/other, CM, Overhead, Commission; add a head = add a row to that array); amount = qty x (1 + wastage %) x rate, always computed on the server; total, profit and margin measured per piece or per dozen (same ratio). A draft is editable; **finalize locks it** and a change is a new revision that starts as a copy (one open draft at a time). Merchant and Admin edit, Management reads, buyer-scoped. Form JavaScript (live amounts, add / remove line) checked in a real headless browser. Tests: `CostingTest`. Manual: section 2.7 and the FAQ "Who makes the BOM?".
- Not built: size- and colour-wise costing, costing approval (the BOM-approval pattern can be reused), comparison of pre-costing with the BOM (Kandaree "comparative costing"), linking the costing to `StyleBudget` (that table matches styles by free text, so a link needs a safe key first), generating a BOM draft from a costing, a currency rate table.

**Done (2026-10-25):** BOM builder — the missing way to make a BOM without Excel (`BomBuilder` service, `Merchant\BomBuilderController`, `/merchant/bom/styles/{mother}`, "Create BOM / Add to BOM" on the style page). Order lines (colour, customer PO, ship date, garment qty, optional child style) x materials (type, description, SAP code, colour, unit, consumption, wastage %). **It writes WORKSPACE rows**, one per order line x material, into one "created in the system" file per buyer and season (`excel_files.file_path = 'manual-bom'`), because the workspace is what PO, approval, status and the calculated columns read. Then `recalculateFile`, `BomTableSync::syncRows` and `BomApprovals::afterRowsChanged` run, so the formulas, standard tables, Style Library link and "changed after approval" all work unchanged (verified: 1.43 x 1.05 x 1200 -> Materials to be Ordered 1802). Details: input columns only (Customer Contract Quantity and GMNTS PO Number are calculated and never targets); the garment qty goes into BOM Quantity; wastage is stored as a fraction (5% -> 0.05) because the workspace reads values <= 1 as fractions; header ownership is respected (columns the role cannot edit are skipped and reported); a material already on an order line is never added twice (key = order-line key + description + SAP code); limits 30 lines, 100 materials, 500 rows. `BomTableSync` order quantity now falls back to BOM Quantity when a sheet has no Customer Contract Quantity column. Form JavaScript checked in a headless browser. Tests: `BomBuilderTest`. Manual: section 2.2a.
- Not built: editing or deleting existing BOM lines on this screen (use the workspace), copying a BOM from another style or season, materials from a library / consumption master, per-size quantities, automatic BOM from an order (Kandaree "auto BOM generation").

**Done (2026-10-26):** Copy BOM and material library (`BomMaterialLibrary`; routes `merchant.bom.create?from=&line=` and `merchant.bom.suggest`). **The library is learned, not maintained:** every material that has been on a BOM of a buyer (builder or Excel import) lives in `bom_items` and is offered to the next BOM, newest figures first, one per material (key = description + SAP code), matched with `whereLike` on description or SAP code (PostgreSQL-safe; `%` and `_` typed by the user are escaped). **Copy:** the builder's "Start from an earlier BOM" takes the materials of another style of the SAME buyer (optionally one order line; duplicates merged by description, SAP code, type, colour, unit, consumption, wastage) and pre-fills the Materials table, writing nothing until Create BOM. Both stay within one buyer (SAP codes and specs are the buyer's). Form JavaScript (line list follows the style, debounced suggestions through a shared `<datalist>`, fill only empty boxes) checked in a headless browser. Tests: `BomBuilderTest`.
- Fixes found on the way: `BomBuilder` no longer fails when an optional field (ship date, type, SAP code...) is not sent; `BomTableSync` reads the **Material Color** column for a material's colour when the sheet has one (it used to answer with the GMTS colour, which also matches that group).
- Not built: copying a whole BOM including order lines and quantities, a curated library screen (add / edit / retire a material, default consumption per buyer), copying across buyers, material images or specifications.

**Next:** TnA depth (templates by lead time, tasks created from rules, e-mail), then the merchandiser interview decides sampling and the buyer PO screen (docs/18).

Phase 2 `order_lines` + `bom_items` tables, field registry, `FieldWriter`, Merchandising columns table-backed with two-way sync and backfill -> Phase 3 import profile + 3-step wizard writing through `FieldWriter` -> Phase 4 `material_orders` (Procurement) -> Phase 5 `receipts` + `issues` (Store) -> Phase 6 `proforma_invoices`, `payments`, `shipments` (Commercial/Accounts) -> Phase 7 pre-costing with revisions, BOM approval -> Phase 8 style-wise material status and traffic light -> TnA -> Sample -> Planning -> SAP FICO-style Accounts integration.
