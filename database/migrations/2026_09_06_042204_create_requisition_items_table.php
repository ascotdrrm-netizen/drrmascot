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
        Schema::create('requisition_items', function (Blueprint $table) {
            $table->id('requisition_item_id');
            $table->foreignId('requisition_id')->constrained('requisitions', 'requisition_id')->onDelete('cascade');
            $table->foreignId('supply_id')->nullable()->constrained('inventory_supplies', 'supply_id')->nullOnDelete();
            $table->string('custom_item_name', 150)->nullable();
            $table->integer('quantity_requested');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('requisition_items');
    }
};
