<?php

use App\Support\BuyerScope;
use App\Support\BuyerScopeModeBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "All buyers" or "specific buyers", said out loud instead of inferred.
 *
 * Until now a user was confined exactly when their buyer set was non-empty.
 * That works while one department uses it, but it cannot express "assign this
 * person to no buyer in particular" as distinct from "this person has not been
 * set up yet", and it makes an empty set mean UNRESTRICTED — the most
 * dangerous default in this feature, because a bug that empties the set then
 * widens access instead of narrowing it.
 *
 * ADDITIVE ONLY. One column with a default; nothing is dropped, nothing is
 * renamed, and `users.buyer_id` and the `buyer_user` pivot are untouched.
 * Undoes by dropping the column.
 *
 * THE BACKFILL IS THE POINT OF THE FILE. Defaulting every row to 'all' and
 * stopping there would unconfine every scoped merchant the moment
 * User::isBuyerConfined() reads this column. BuyerScopeModeBackfill writes
 * 'specific' over everyone confined today; see that class for why it is not
 * inline here (dev holds no such rows, so inline code could not be tested).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('buyer_scope_mode', 16)
                ->default(BuyerScope::MODE_ALL)
                ->after('buyer_id');
        });

        (new BuyerScopeModeBackfill())->run();
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('buyer_scope_mode');
        });
    }
};
