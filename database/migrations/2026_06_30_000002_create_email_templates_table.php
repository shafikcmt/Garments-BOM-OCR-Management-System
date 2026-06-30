<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_templates', function (Blueprint $table) {
            $table->id();
            $table->string('type')->unique();
            $table->string('name');
            $table->string('subject');
            $table->longText('body');
            $table->timestamps();
        });

        $now = now();
        $body = <<<'HTML'
<p>Dear Sir/Madam,</p>
<p>Please find attached the Payment Request Approval <strong>{{pr_number}}</strong> for your review and processing.</p>
<p>
Buyer: {{buyer}}<br>
Season: {{season}}<br>
Vendor / Supplier: {{supplier}}<br>
Payment Require Date: {{payment_require_date}}<br>
Total PI Amount: {{total_amount}}
</p>
<p>Kind regards,<br>{{company_name}}</p>
HTML;

        DB::table('email_templates')->insert([
            'type' => 'pra',
            'name' => 'Payment Request Approval (PRA)',
            'subject' => 'Payment Request Approval - {{pr_number}}',
            'body' => $body,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('email_templates');
    }
};
