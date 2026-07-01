<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->longText('value')->nullable();
            $table->timestamps();
        });

        $now = now();
        DB::table('app_settings')->insert([
            [
                'key' => 'pi_alert_days',
                'value' => '3',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'key' => 'pi_alert_departments',
                'value' => json_encode(['merchant', 'supply_chain', 'commercial']),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'key' => 'pi_alert_mail_enabled',
                'value' => '0',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'key' => 'pi_alert_mail_recipients',
                'value' => 'department_users',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'key' => 'pi_alert_mail_emails',
                'value' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('app_settings');
    }
};
