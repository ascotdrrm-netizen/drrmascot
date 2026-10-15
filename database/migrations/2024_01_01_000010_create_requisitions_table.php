<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('requisitions', function (Blueprint $table) {
            $table->id('requisition_id');
            $table->foreignId('campus_id')->constrained('campuses', 'campus_id')->onDelete('cascade');
            $table->foreignId('supply_id')->constrained('inventory_supplies', 'supply_id')->onDelete('cascade');
            $table->integer('quantity_requested');
            $table->enum('status', ['Pending', 'Approved', 'Dispatched', 'Denied'])->default('Pending');
            $table->date('date_requested');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('requisitions');
    }
};
