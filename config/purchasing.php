<?php

/**
 * General Stock purchasing: Comparative Statement (CS) and Internal PO.
 *
 * Everything the printed forms carry that is not typed per document lives here,
 * so a revised form code or a new consignee address is a one-line edit and no
 * code change. Defaults match the forms the factory uses today.
 */
return [

    /*
     * The control box printed at the top right of the Comparative Statement.
     * Change these when the form is re-issued; old CS keep printing the values
     * they were saved with (snapshotted on the CS row).
     */
    'cs_form' => [
        'code' => env('PURCHASING_CS_FORM_CODE', 'HAPL-03-0345'),
        'issue_date' => env('PURCHASING_CS_FORM_ISSUE_DATE', '01-12-2025'),
        'version_no' => env('PURCHASING_CS_FORM_VERSION', '01'),
        'revision_no' => env('PURCHASING_CS_FORM_REVISION', '01'),
    ],

    /*
     * Number formats. CS: CS-26-27/0001. PO: PO-26-27/T-080, where T is the
     * unit code (the same T-080 is the PO's Reference No.). The counter restarts every financial year, which begins in
     * `fy_start_month` (7 = July, the Bangladesh financial year).
     */
    'fy_start_month' => (int) env('PURCHASING_FY_START_MONTH', 7),
    'po_code' => env('PURCHASING_PO_CODE', 'T'),
    'cs_prefix' => 'CS',
    'po_prefix' => 'PO',
    'cs_seq_width' => 4,
    'po_seq_width' => 3,
    /*
     * The first number the system gives in a financial year. The factory already
     * books POs by hand (PO-26-27/T-080 in October), so the system starts after
     * the last manual one. Only used when the year has no counter yet.
     */
    'po_first_no' => (int) env('PURCHASING_PO_FIRST_NO', 1),
    'cs_first_no' => (int) env('PURCHASING_CS_FIRST_NO', 1),

    /*
     * Printed on every new Internal PO unless the user types something else.
     */
    'po_defaults' => [
        'invoice_to' => "Humana Apparels Pvt. Ltd\nMomin Nagor, Gorai,\nMirzapur, Tangail",
        'consignee' => "Humana Apparels Pvt. Ltd\nMomin Nagor, Gorai, Mirzapur, Tangail",
        'destination' => 'Momin Nagor, Gorai, Tangail',
        // Fixed company BIN: printed on every PO and not editable on screen.
        'bin_number' => '0056353810406',
        'dispatched_through' => 'Vendor Transport',
        'payment_terms' => 'One month credit along with work order.',
        'delivery_terms' => "100% quality passed before delivery\nQuantity has to be ensured accurately",
        'remarks' => "Supply to be completed as soon as possible.\nPrice is excluding VAT.",
        'due_on' => 'ASAP',
    ],

    /* A CS needs at least this many suppliers before it can be submitted (comparative by definition). */
    'min_suppliers_to_submit' => (int) env('PURCHASING_MIN_SUPPLIERS', 2),

    /* The most suppliers one CS can compare side by side (the sheet is landscape A4). */
    'max_suppliers' => 4,
];
