<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_supplies', function (Blueprint $table) {
            $table->id('supply_id');
            $table->string('item_name', 100);
            $table->enum('category', ['First Aid', 'TLS Kits', 'Food', 'Other']);
            $table->integer('quantity_available')->default(0);
            $table->integer('reorder_level')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_supplies');
    }
};
