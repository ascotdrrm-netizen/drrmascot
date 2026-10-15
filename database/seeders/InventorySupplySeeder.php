<?php

namespace Database\Seeders;

use App\Models\InventorySupply;
use Illuminate\Database\Seeder;

class InventorySupplySeeder extends Seeder
{
    public function run(): void
    {
        $items = [
            // First Aid
            ['item_name' => 'First Aid Kits', 'category' => 'First Aid', 'quantity_available' => 40, 'reorder_level' => 10],
            ['item_name' => 'Bandages & Gauze', 'category' => 'First Aid', 'quantity_available' => 150, 'reorder_level' => 30],
            ['item_name' => 'Antiseptic Solution', 'category' => 'First Aid', 'quantity_available' => 60, 'reorder_level' => 15],

            // TLS Kits
            ['item_name' => 'Emergency Tents', 'category' => 'TLS Kits', 'quantity_available' => 20, 'reorder_level' => 5],
            ['item_name' => 'Sleeping Mats', 'category' => 'TLS Kits', 'quantity_available' => 100, 'reorder_level' => 20],
            ['item_name' => 'Blankets', 'category' => 'TLS Kits', 'quantity_available' => 120, 'reorder_level' => 25],

            // Food
            ['item_name' => 'Canned Goods', 'category' => 'Food', 'quantity_available' => 300, 'reorder_level' => 50],
            ['item_name' => 'Bottled Water', 'category' => 'Food', 'quantity_available' => 500, 'reorder_level' => 100],
            ['item_name' => 'Rice Packs', 'category' => 'Food', 'quantity_available' => 200, 'reorder_level' => 40],

            // Other
            ['item_name' => 'Flashlights', 'category' => 'Other', 'quantity_available' => 50, 'reorder_level' => 10],
            ['item_name' => 'Face Masks', 'category' => 'Other', 'quantity_available' => 400, 'reorder_level' => 80],
            ['item_name' => 'Emergency Whistles', 'category' => 'Other', 'quantity_available' => 60, 'reorder_level' => 15],
        ];

        foreach ($items as $item) {
            InventorySupply::firstOrCreate(
                ['item_name' => $item['item_name']],
                $item
            );
        }
    }
}
