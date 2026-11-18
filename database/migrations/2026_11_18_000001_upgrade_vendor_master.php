<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Vendor Master upgrade.
 *
 * The Vendor Master IS the existing `suppliers` table (Admin > Vendors, Merchant >
 * Vendors, PO booking). It is extended in place - no second vendor table.
 * Every change is additive and nullable/defaulted, so nothing already saved is lost.
 *
 *  - suppliers: type, category, status, approval status, tax ids, defaults, validity,
 *    ERP code, authorship. Existing vendors are backfilled as APPROVED so purchasing
 *    carries on exactly as before; vendor_status follows the old is_active flag.
 *  - children: contacts, addresses, item categories, bank accounts (+ change
 *    requests), BU mapping, vendor-item terms, audit log.
 *  - booking_pos: supplier_id + a frozen vendor snapshot, so a later vendor edit
 *    never rewrites a PO that was already generated.
 *
 * Legacy single-value columns (contact_person, email, bank_* ...) stay in place
 * and are copied into the new tables, so old screens/exports keep working.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('vendor_type', 20)->default('local')->after('legal_name');
            $table->string('vendor_category', 100)->nullable()->after('vendor_type');
            $table->string('vendor_status', 20)->default('active')->after('vendor_category');
            $table->string('approval_status', 20)->default('draft')->after('vendor_status');
            $table->string('tin', 100)->nullable();
            $table->string('vat_bin_number', 100)->nullable();
            $table->string('business_reg_number', 100)->nullable();
            $table->string('default_currency', 10)->nullable();
            $table->string('default_payment_terms', 150)->nullable();
            $table->unsignedSmallInteger('lead_time_days')->nullable();
            $table->decimal('moq', 14, 3)->nullable();
            $table->date('effective_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->string('external_erp_code', 100)->nullable();
            $table->text('remarks')->nullable();
            $table->text('status_reason')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->index(['approval_status', 'vendor_status'], 'suppliers_workflow_idx');
        });

        // Vendors that already exist were being used for purchasing: keep them usable.
        DB::table('suppliers')->update(['approval_status' => 'approved', 'approved_at' => now()]);
        DB::table('suppliers')->where('is_active', true)->update(['vendor_status' => 'active']);
        DB::table('suppliers')->where('is_active', false)->update(['vendor_status' => 'inactive']);

        Schema::create('business_units', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('supplier_business_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();
            $table->foreignId('business_unit_id')->constrained('business_units')->cascadeOnDelete();
            $table->string('status', 20)->default('active');
            $table->date('effective_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->timestamps();
            $table->unique(['supplier_id', 'business_unit_id'], 'supplier_bu_unique');
        });

        Schema::create('supplier_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();
            $table->string('name');
            $table->string('designation', 100)->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('contact_type', 30)->default('general'); // general, sales, accounts, shipping, technical
            $table->boolean('is_primary')->default(false);
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->index(['supplier_id', 'is_primary']);
        });

        Schema::create('supplier_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();
            $table->string('address_type', 30)->default('office'); // registered, office, factory, warehouse
            $table->text('address');
            $table->string('city', 100)->nullable();
            $table->string('state', 100)->nullable();
            $table->string('postal_code', 30)->nullable();
            $table->string('country', 100)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
            $table->index(['supplier_id', 'is_primary']);
        });

        Schema::create('supplier_item_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();
            // Optional link to the existing category master (Issue Setup categories).
            $table->unsignedBigInteger('item_category_id')->nullable()->index();
            $table->string('category_name', 120);
            $table->string('item_type', 30)->default('material'); // material, accessory, general
            $table->unsignedSmallInteger('lead_time_days')->nullable();
            $table->decimal('moq', 14, 3)->nullable();
            $table->decimal('tolerance_percent', 5, 2)->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->unique(['supplier_id', 'category_name', 'item_type'], 'supplier_category_unique');
        });

        Schema::create('supplier_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();
            $table->unsignedBigInteger('stock_item_id')->nullable()->index();
            $table->string('item_type', 30)->default('material'); // material, accessory
            $table->string('item_name')->nullable();
            $table->string('supplier_item_code', 100)->nullable();
            $table->unsignedSmallInteger('lead_time_days')->nullable();
            $table->decimal('moq', 14, 3)->nullable();
            $table->boolean('is_preferred')->default(false);
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->index(['supplier_id', 'status']);
        });

        Schema::create('supplier_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();
            $table->string('bank_name');
            $table->string('bank_branch')->nullable();
            $table->string('account_name');
            $table->text('account_number');                 // encrypted by the model cast
            $table->string('account_number_last4', 4)->nullable();
            $table->string('account_number_hash', 64)->nullable()->index(); // duplicate check, not reversible
            $table->text('routing_number')->nullable();     // encrypted
            $table->string('swift_code', 50)->nullable();
            $table->text('iban')->nullable();               // encrypted
            $table->string('currency', 10)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->string('verification_status', 20)->default('pending'); // pending, approved, rejected
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->index(['supplier_id', 'is_primary']);
        });

        Schema::create('supplier_bank_change_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();
            $table->unsignedBigInteger('supplier_bank_account_id')->nullable();
            $table->string('action', 20); // create, update, deactivate
            $table->text('payload')->nullable();      // encrypted JSON of the proposed values
            $table->string('summary', 255)->nullable(); // masked, safe to show and log
            $table->string('status', 20)->default('pending'); // pending, approved, rejected, cancelled
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_remarks')->nullable();
            $table->timestamps();
            $table->index(['supplier_id', 'status']);
        });

        Schema::create('supplier_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();
            $table->string('entity', 40)->default('vendor');
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('action', 40);
            $table->string('field', 80)->nullable();
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->string('approval_status', 20)->nullable();
            $table->unsignedBigInteger('changed_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();
            $table->index(['supplier_id', 'created_at']);
        });

        Schema::table('booking_pos', function (Blueprint $table) {
            $table->unsignedBigInteger('supplier_id')->nullable()->index();
            $table->json('vendor_snapshot')->nullable();
            $table->string('incoterm', 50)->nullable();
            $table->string('ship_mode', 50)->nullable();
            $table->decimal('tolerance_percent', 5, 2)->nullable();
        });

        $this->backfillChildren();
    }

    /** Copy the single contact / address / bank already on each vendor into the new tables. */
    private function backfillChildren(): void
    {
        $now = now();

        DB::table('suppliers')->orderBy('id')->each(function ($s) use ($now) {
            if (filled($s->contact_person) || filled($s->email) || filled($s->phone)) {
                DB::table('supplier_contacts')->insert([
                    'supplier_id' => $s->id, 'name' => $s->contact_person ?: ($s->supplier_name ?: 'Contact'),
                    'email' => $s->email, 'phone' => $s->phone, 'contact_type' => 'general',
                    'is_primary' => true, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
                ]);
            }

            if (filled($s->address)) {
                DB::table('supplier_addresses')->insert([
                    'supplier_id' => $s->id, 'address_type' => 'office', 'address' => $s->address,
                    'city' => $s->city, 'country' => $s->country, 'is_primary' => true,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }

            if (filled($s->item_type)) {
                DB::table('supplier_item_categories')->insert([
                    'supplier_id' => $s->id, 'category_name' => $s->item_type, 'item_type' => 'material',
                    'tolerance_percent' => $s->tolerance_percent, 'status' => 'active',
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }

            if (filled($s->bank_name) && filled($s->bank_account_number)) {
                $number = preg_replace('/\s+/', '', (string) $s->bank_account_number);
                DB::table('supplier_bank_accounts')->insert([
                    'supplier_id' => $s->id, 'bank_name' => $s->bank_name, 'bank_branch' => $s->bank_branch,
                    'account_name' => $s->bank_account_name ?: $s->supplier_name,
                    'account_number' => encrypt($number, false),
                    'account_number_last4' => substr($number, -4),
                    'account_number_hash' => hash_hmac('sha256', $number, (string) config('app.key')),
                    'routing_number' => filled($s->bank_routing_number) ? encrypt($s->bank_routing_number, false) : null,
                    'swift_code' => $s->bank_swift_code, 'is_primary' => true,
                    'verification_status' => 'approved', 'approved_at' => $now, 'status' => 'active',
                    'created_at' => $now, 'updated_at' => $now,
                ]);

                // The number now lives encrypted in supplier_bank_accounts; clear the plain-text copy.
                DB::table('suppliers')->where('id', $s->id)->update(['bank_account_number' => null, 'bank_routing_number' => null]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('booking_pos', fn (Blueprint $t) => $t->dropColumn(['supplier_id', 'vendor_snapshot', 'incoterm', 'ship_mode', 'tolerance_percent']));

        foreach ([
            'supplier_audit_logs', 'supplier_bank_change_requests', 'supplier_bank_accounts', 'supplier_items',
            'supplier_item_categories', 'supplier_addresses', 'supplier_contacts', 'supplier_business_units', 'business_units',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropIndex('suppliers_workflow_idx');
            $table->dropColumn([
                'vendor_type', 'vendor_category', 'vendor_status', 'approval_status', 'tin', 'vat_bin_number',
                'business_reg_number', 'default_currency', 'default_payment_terms', 'lead_time_days', 'moq',
                'effective_date', 'expiry_date', 'external_erp_code', 'remarks', 'status_reason',
                'created_by', 'updated_by', 'approved_by', 'submitted_at', 'approved_at',
            ]);
        });
    }
};
