<?php

/*
|--------------------------------------------------------------------------
| Quotation PDF
|--------------------------------------------------------------------------
|
| The standard heading and defaults of the printed quotation (Inquiries >
| Download Quotation PDF). Payment terms, delivery terms, validity and the
| remark are typed on each inquiry; the values here are only what is printed
| when the inquiry has none. Put the company logo in public/ and set 'logo' to
| its path (for example 'images/logo.png') to print it.
|
*/
return [
    'company_name' => env('QUOTATION_COMPANY_NAME', config('stock.company_name', 'Company')),
    'address' => array_values(array_filter(explode('|', (string) env('QUOTATION_COMPANY_ADDRESS', '')))),
    'logo' => env('QUOTATION_LOGO'),
    'validity_days' => 30,
    'payment_terms' => 'To be agreed',
    'delivery_terms' => 'To be agreed',
];
