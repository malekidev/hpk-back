<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->boolean('locked')->nullable()->after('power');
            $table->string('master_number', 14)->nullable()->after('locked');
            $table->string('backup_number', 14)->nullable()->after('master_number');
            $table->unsignedTinyInteger('combined_alert_recipient')->nullable()->after('combined_alert_mode');
            $table->unsignedTinyInteger('acc_alert_recipient')->nullable()->after('acc_alert_mode');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn([
                'locked', 'master_number', 'backup_number',
                'combined_alert_recipient', 'acc_alert_recipient',
            ]);
        });
    }
};
