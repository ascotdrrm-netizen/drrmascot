<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Data-copy step: every existing requisition had exactly one item/quantity
        // directly on it — turn that into its one corresponding requisition_items row
        // before the source columns are dropped below.
        DB::table('requisitions')->orderBy('requisition_id')->chunkById(100, function ($requisitions) {
            foreach ($requisitions as $requisition) {
                DB::table('requisition_items')->insert([
                    'requisition_id' => $requisition->requisition_id,
                    'supply_id' => $requisition->supply_id,
                    'custom_item_name' => $requisition->custom_item_name,
                    'quantity_requested' => $requisition->quantity_requested,
                ]);
            }
        }, 'requisition_id');

        Schema::table('requisitions', function (Blueprint $table) {
            $table->dropForeign(['supply_id']);
            $table->dropColumn(['supply_id', 'custom_item_name', 'quantity_requested']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * Note: this reversal is best-effort and lossy for any requisition that ended up
     * with more than one line item — only the first requisition_items row per
     * requisition is copied back onto the single-item columns being restored.
     */
    public function down(): void
    {
        Schema::table('requisitions', function (Blueprint $table) {
            $table->foreignId('supply_id')->nullable()->after('campus_id')
                ->constrained('inventory_supplies', 'supply_id')->nullOnDelete();
            $table->string('custom_item_name', 150)->nullable()->after('supply_id');
            $table->integer('quantity_requested')->nullable()->after('custom_item_name');
        });

        $firstItemPerRequisition = DB::table('requisition_items')
            ->orderBy('requisition_item_id')
            ->get()
            ->groupBy('requisition_id');

        foreach ($firstItemPerRequisition as $requisitionId => $items) {
            $first = $items->first();

            DB::table('requisitions')->where('requisition_id', $requisitionId)->update([
                'supply_id' => $first->supply_id,
                'custom_item_name' => $first->custom_item_name,
                'quantity_requested' => $first->quantity_requested,
            ]);
        }
    }
};
