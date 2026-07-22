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

];
