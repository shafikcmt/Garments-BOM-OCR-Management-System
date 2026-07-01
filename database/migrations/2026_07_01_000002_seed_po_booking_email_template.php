<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('email_templates')->where('type', 'po_booking')->exists()) {
            return;
        }

        $now = now();
        $body = <<<'HTML'
<p>Dear {{supplier_name}},</p>
<p>Please find attached our Purchase Order / Booking <strong>{{po_number}}</strong> for your kind processing.</p>
<p>
Buyer: {{buyer}}<br>
Season: {{season}}<br>
Style / Order No: {{style_no}}<br>
PO / Booking No: {{po_number}}
</p>
<p>Kindly confirm receipt and share the Proforma Invoice at the earliest.</p>
<p>Kind regards,<br>{{sender_name}}<br>{{company_name}}</p>
HTML;

        DB::table('email_templates')->insert([
            'type' => 'po_booking',
            'name' => 'PO Booking to Supplier',
            'subject' => 'PO Booking {{po_number}} - {{buyer}} {{season}}',
            'body' => $body,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        DB::table('email_templates')->where('type', 'po_booking')->delete();
    }
};
