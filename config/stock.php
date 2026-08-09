<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Sync Material Stock Ledger -> BOM Workspace cells
    |--------------------------------------------------------------------------
    |
    | When true, the Material Stock Ledger is the single source of truth for the
    | overlapping Store fields in the BOM Workspace: after any ledger recompute
    | the matching excel_cells are auto-filled, and recalculateFile() yields
    | those cells (stops writing its own Excel-formula value) for any row that
    | has a ledger entry.
    |
    | Set to false (STOCK_SYNC_WORKSPACE_CELLS=false) to fully disable the sync
    | and revert to the legacy Excel-formula behaviour without touching code.
    |
    */
    'sync_workspace_cells' => env('STOCK_SYNC_WORKSPACE_CELLS', true),

    /*
    | Login-less user that the sync attributes excel_cells.updated_by to, so
    | auto-writes are never blamed on whichever human triggered the recompute.
    */
    'system_user_email' => 'system@garments-ocr.local',
    'system_user_name' => 'System (Auto Sync)',

    /*
    | Canonical Store header keys the ledger owns. Drives both the sync writes
    | and the recalculateFile() yield-guard. These are all owner_role = store.
    */
    'ledger_owned_store_header_keys' => [
        'liability',
        'dead_stock_quantity',
        'liability_stock_value',
        'receipt_qty',
        'invoiced_qty_store',
        'invoiced_rate_store',
        'invoiced_amount_store',
    ],

    /*
    | Subset that recalculateFile() actively WRITES today (the others it only
    | reads as inputs). Only these need to be yielded to the ledger.
    */
    'recalc_yield_store_header_keys' => [
        'liability',
        'dead_stock_quantity',
        'liability_stock_value',
        'invoiced_amount_store',
    ],

    /*
    |--------------------------------------------------------------------------
    | Indent Sections (Bulk Issuing)
    |--------------------------------------------------------------------------
    |
    | Production sections a Bulk Issue can be indented for. There is no section
    | master table, so the standard garments sections live here — edit this list
    | to add/remove one without a migration. Used only to populate the "Indent
    | Section" dropdown; the value stored is the free string itself.
    |
    */
    'indent_sections' => [
        'Cutting',
        'Sewing',
        'Finishing',
        'Sample',
        'Embroidery',
        'Printing',
        'Washing',
        'Store',
    ],

    /*
    |--------------------------------------------------------------------------
    | Buyer-first Bulk Issue entry (multi-PO)
    |--------------------------------------------------------------------------
    |
    | OFF by default. When false the New Bulk Issue screen selects one PO at a
    | time, which is how it has always worked. When true it selects a BUYER and
    | loads every item under all of that buyer's POs into one grid, so a single
    | issue can span several POs.
    |
    | This gates the UI only. store() already accepts a per-row booking_po_id
    | and already refuses rows spanning two buyers, so flipping this flag cannot
    | leave the save path half-migrated — and flipping it back is instant if
    | Store finds the buyer-first screen confusing.
    |
    | Set BULK_ISSUE_MULTI_PO=true to trial it.
    |
    */
    'bulk_issue_multi_po' => env('BULK_ISSUE_MULTI_PO', false),

    /*
    | Cap on the items one buyer can pull into the grid. A buyer with 20 POs of
    | 30 lines would otherwise put 600 rows on screen. Past this the browser is
    | told what was withheld rather than silently truncated.
    */
    'bulk_issue_buyer_item_limit' => 500,

    /*
    |--------------------------------------------------------------------------
    | General Stock report (Consumable Stock Report)
    |--------------------------------------------------------------------------
    |
    | The constants baked into the company's Excel "Stock <Month>" sheet. They
    | are settings, not code, so the store manager can have them changed without
    | a deployment when the factory calendar changes.
    |
    |   Consumption per day = last month's issued qty / working_days_per_month
    |   Safety Stock Level  = Consumption per day x safety_stock_days
    |   Re-order Level      = Safety Stock + (Consumption per day
    |                             x (item lead time + order_placing_days))
    |
    | Original Excel formulas, for reference:
    |   I = SUMIFS(prev month issued)/26
    |   J = I*7
    |   K = IF(I=0,"-", J+(I*(L+3)))
    |
    */
    'general_stock' => [
        // Excel divides last month's consumption by 26 (6-day factory week).
        'working_days_per_month' => 26,

        // "Safety Stock Level (7 days stock)".
        'safety_stock_days' => 7,

        // "time to place order" inside the re-order formula.
        'order_placing_days' => 3,

        // Lead time assumed when an item has none set in the item master.
        // Company standard is 7 days.
        'default_lead_time_days' => 7,
    ],

    /*
    |--------------------------------------------------------------------------
    | Purchase Requisition
    |--------------------------------------------------------------------------
    |
    | Defaults for the Purchase Requisition document, matching the header block
    | of the company's "Month_Of_<Month>.xlsx" workbook. Settings rather than
    | code so the printed heading can be changed without a deployment.
    |
    */
    'company_name' => 'Humana Apparels Pvt. Ltd.',

];
