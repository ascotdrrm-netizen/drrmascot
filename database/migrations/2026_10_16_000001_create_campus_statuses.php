<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campus_statuses', function (Blueprint $table) {
            $table->foreignId('campus_id')->primary()->constrained('campuses', 'campus_id')->cascadeOnDelete();
            $table->string('status', 20)->default('normal');
            $table->string('detail', 200)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('personnel', 'personnel_id')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campus_statuses');
    }
};
