> **Superseded in direction by `docs/16_STANDARD_DYNAMIC_ORDER_FORMAT.md`.** The Ann Taylor examples below only illustrate why a buyer-independent standard is needed; do not model the system on that layout.

# Buyer -> Season -> Mother Style -> Child Style (Dynamic Buyer Orders)

Reference for every future change to Merchandising order handling. Read before touching styles, BOM import, or buyer-wise screens.

## Why

Each buyer keeps orders in its own Excel layout and its own folder tree. Example (Macy's & Ann Taylor OneDrive):

```
Buyer            Ann Taylor
 Season          OCR_SP27            (also WI25, SP26, SU26, FA-26, WI26 ...)
  Product group  DENIM / WOVEN
   Lot file      "Denim Lot 1 Style-882635,720193(PETITE),813132 ..."  (many styles per file)
    Mother style 748268
     Child style 866317 (linked no.), PETITE / CURVY (fit variant)
```

Real sample files (3 Denim lots) all share one `Database` sheet (82 columns: Style, GMTS Color, PO, Material, Vendor, PI, Payment, Shipment, Inhouse, Issue, Liability ...) with style cells like `720193(PETITE)` and `748268 (866317 )`. Software must absorb a new buyer's layout by CONFIGURATION, never by new code per buyer.

## Rules (do not break)

1. **No buyer-specific code.** Never `if ($buyer->name === 'Ann Taylor')`. Differences live in data: season code, product group, import profile.
2. **One structure for all buyers:** `buyers -> buyer_seasons -> mother_styles -> child_styles` (migration `2026_10_20_000001`). Add fields additively and nullable.
3. **Style codes are parsed, not typed twice.** `App\Support\StyleCodeParser` turns `720193(PETITE)` into mother 720193 + child PETITE (variant) and `748268 (866317)` into mother 748268 + child 866317 (linked). Extend the parser for new buyer patterns; add a test for each.
4. **Buyer scope applies everywhere.** Use `isBuyerConfined()` / `merchantBuyerIds()` / `hasBuyerAccess()`. Untagged legacy data stays visible.
5. **Existing BOM upload is untouched.** `ExcelFile / ExcelHeader / ExcelRow / ExcelCell` keep working; new work links to them, never replaces them.
6. Users manage everything from screens: Merchant -> **Style Library**. No developer step to add a buyer's season or style.

## Built (Phase 1)

- Style Library screen (`/merchant/styles`): buyer list, season chips, bulk "paste style codes" add, add/delete child style.
- Dashboard + sidebar entry for Merchandising.
- Tests: `tests/Feature/Merchant/StyleLibraryTest.php`.

## Next phases (one task each; inspect first)

| Phase | Work | Risk |
|---|---|---|
| 2 | **Buyer import profile**: per buyer, a saved mapping (sheet name, header row, Excel column -> system header, style column format). Upload picks the profile; "map columns" screen for a new layout, saved for next time. | Must reuse `ExcelUploadController` header matching; do not fork it. |
| 3 | Link `excel_files` / `excel_rows` to mother/child style (nullable `mother_style_id`), auto-detect styles from the Style column on upload. | Additive nullable columns only. |
| 4 | Style detail page: PO/color/size, material status (Required / Ordered / In-house / Issued / Liability) rolled up Mother -> Child, like the Kandaree "Status of Material" view. | Read-only roll-ups first. |
| 5 | Lot/summary views matching the Excel sheets (Style Status, Payment Summary, Material Summary, Contract Summary). | Match the Excel layout exactly. |
| 6 | Read-only Style Library for Admin / Management; season-wise and buyer-wise reports. | Role checks. |
| 7 | Pre-costing, Sample, TnA, Planning per Kandaree / `docs/09_FUTURE_ERP_ROADMAP.md`. | Large; one module at a time. |

## Reference products

- **Kandaree Apparel ERP** (process diagram: Merchandising -> Procurement -> Planning -> Commercial -> Inventory -> Sample -> TnA -> Accounting). Follow its flow: data entered once, shared by every department.
- **SAP FICO** is the reference for the Accounts/Finance side later (chart of accounts, cost centers, auto journal from operations, approval, ledger/reports). The BOM already carries an `SAP Code` column; keep material and vendor codes clean so a future SAP/accounts integration can map them. Do not build FICO now.

## Checklist for any change here

- Inspect routes, controllers, models, migrations, views first.
- Additive migration, MySQL + PostgreSQL safe (`whereLike`, no raw date functions).
- Role + buyer scope tested (merchant, confined merchant, admin, management).
- UI: professional English, labelled buttons (no icon-only).
- Test with the real Denim lot files (long style lists, `(PETITE)` / `(CURVY)` / `(791366 )` codes).
