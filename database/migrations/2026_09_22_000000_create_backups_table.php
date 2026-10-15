<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('backups', function (Blueprint $table) {
            $table->id('backup_id');
            $table->string('filename', 150);
            $table->string('storage_path', 255);
            $table->foreignId('created_by')->nullable()
                ->constrained('personnel', 'personnel_id')->nullOnDelete();
            $table->unsignedBigInteger('file_size');
            $table->timestamp('created_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('backups');
    }
};
