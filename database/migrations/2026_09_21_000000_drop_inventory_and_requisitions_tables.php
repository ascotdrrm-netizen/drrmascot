<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Inventory and Requisitions were removed from the app entirely. Drop
     * child tables before parents: requisition_items references both
     * requisitions and inventory_supplies, so it must go first.
     */
    public function up(): void
    {
        Schema::dropIfExists('requisition_items');
        Schema::dropIfExists('requisitions');
        Schema::dropIfExists('inventory_supplies');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::create('inventory_supplies', function (Blueprint $table) {
            $table->id('supply_id');
            $table->string('item_name', 100);
            $table->enum('category', ['First Aid', 'TLS Kits', 'Food', 'Other']);
            $table->integer('quantity_available')->default(0);
            $table->integer('reorder_level')->default(0);
            $table->softDeletes();
        });

        Schema::create('requisitions', function (Blueprint $table) {
            $table->id('requisition_id');
            $table->foreignId('campus_id')->constrained('campuses', 'campus_id')->onDelete('cascade');
            $table->enum('status', ['Pending', 'Approved', 'Dispatched', 'Denied'])->default('Pending');
            $table->date('date_requested');
            $table->string('letter_path')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('personnel', 'personnel_id')->onDelete('set null');
            $table->softDeletes();
        });

        Schema::create('requisition_items', function (Blueprint $table) {
            $table->id('requisition_item_id');
            $table->foreignId('requisition_id')->constrained('requisitions', 'requisition_id')->onDelete('cascade');
            $table->foreignId('supply_id')->nullable()->constrained('inventory_supplies', 'supply_id')->onDelete('set null');
            $table->string('custom_item_name', 150)->nullable();
            $table->integer('quantity_requested');
        });
    }
};
