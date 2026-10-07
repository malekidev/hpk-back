<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('imei', 15)->unique();
            $table->string('name');
            $table->unsignedSmallInteger('settings_revision')->default(0);
            $table->unsignedSmallInteger('reported_revision')->default(0);
            $table->unsignedInteger('last_command_sequence')->default(0);
            $table->unsignedTinyInteger('combined_alert_mode')->nullable();
            $table->unsignedTinyInteger('acc_alert_mode')->nullable();
            $table->unsignedTinyInteger('shock_level')->nullable();
            $table->boolean('service_enabled')->nullable();
            $table->boolean('power')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->timestamp('last_fix_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });
        Schema::create('device_commands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->char('action', 1);
            $table->string('status', 16)->default('queued');
            $table->string('request_key', 64);
            $table->boolean('result')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['device_id', 'sequence']);
            $table->unique(['device_id', 'request_key']);
            $table->index(['device_id', 'status', 'id']);
        });
        Schema::create('tracker_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->json('payload');
            $table->string('response', 63);
            $table->timestamp('received_at');
            $table->index(['device_id', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracker_reports');
        Schema::dropIfExists('device_commands');
        Schema::dropIfExists('devices');
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('users');
    }
};
