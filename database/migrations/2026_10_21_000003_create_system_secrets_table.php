<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Server secrets the DRRMO Admin sets in the app (App\Support\DataPrivacyPassword).
 * Kept out of `settings` on purpose: backups skip this table
 * (App\Support\Backup\DataSet::EXCLUDED_TABLES), so a backup file never
 * carries the hash, and restoring an old backup or clearing records keeps
 * the password that is in use now.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_secrets', function (Blueprint $table) {
            $table->string('key', 80)->primary();
            $table->text('value');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_secrets');
    }
};
