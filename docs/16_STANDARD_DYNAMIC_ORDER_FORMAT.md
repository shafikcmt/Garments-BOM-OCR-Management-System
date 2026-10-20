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

## 3. Standard field groups (what every buyer's BOM maps into)

Derived from the real 82-column BOM sheet, but named generically. Each is an `ExcelHeader` group, owned by a role.

| Group | Standard fields (examples) | Owner |
|---|---|---|
| Order | buyer, season, style (mother/child), color, PO no, ship date, order qty | Merchandising |
| Material | type, description, item code, material color, BOM qty, consumption, wastage %, requirement | Merchandising |
| Sourcing | vendor, vendor type, currency, material PO no/date, ordered qty, rate | Merchandising / Procurement |
| Proforma & Payment | PI no/status/date/amount, HS code, payment term, payment doc/status | Commercial / Accounts |
| Shipment | ship mode, forwarder, BL/AWB, port, vessel, ETD/ETA (committed vs actual) | Commercial |
| Receive | committed / actual inhouse date, receipt qty, invoice no/qty/amount | Store |
| Issue & Balance | bulk issue, sample issue, return, excess/short, liability | Store |

Buyers differ in names ("PCD Required", "Order to be placed by"...). Extra buyer-only columns become *custom headers* in the matching group, not new tables.

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

Phase 2 import profile + wizard -> Phase 3 link files/rows to style -> Phase 4 standard Order/PO table (color/size/destination) -> Phase 5 pre-costing with revisions -> Phase 6 BOM approval + BOM-based purchase -> Phase 7 style-wise material status and traffic light -> Phase 8 TnA -> Sample -> Planning -> SAP FICO-style Accounts integration.
