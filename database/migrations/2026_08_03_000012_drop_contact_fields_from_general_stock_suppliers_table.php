<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// General Stock (module A) — reduce the supplier list to just a name.
//
// The store only ever needs to say which vendor a challan came from. Contact
// details were never asked for, are not read by the Record Purchase screen, and
// an optional field nobody fills is just another empty box on the form.
//
// Written as its own migration rather than editing the original, which has
// already run wherever this is deployed. Safe here because the table holds no
// rows at all — verified before writing this. Check that before migrating any
// install that does hold supplier data, since the values cannot be recovered.
//
// `is_active` stays: the Record Purchase dropdown filters on it, so removing it
// would drop the ability to retire a supplier without deleting it.
return new class extends Migration
{
    /** @var list<string> */
    private const COLUMNS = ['contact_person', 'phone', 'email', 'address', 'remarks'];

    public function up(): void
    {
        Schema::table('general_stock_suppliers', function (Blueprint $table) {
            $table->dropColumn(self::COLUMNS);
        });
    }

    public function down(): void
    {
        Schema::table('general_stock_suppliers', function (Blueprint $table) {
            $table->string('contact_person')->nullable()->after('name');
            $table->string('phone', 50)->nullable()->after('contact_person');
            $table->string('email')->nullable()->after('phone');
            $table->text('address')->nullable()->after('email');
            $table->text('remarks')->nullable()->after('is_active');
        });
    }
};
