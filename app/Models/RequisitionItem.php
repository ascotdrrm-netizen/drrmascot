<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RequisitionItem extends Model
{
    protected $primaryKey = 'requisition_item_id';
    public $timestamps = false;

    protected $fillable = [
        'requisition_id',
        'supply_id',
        'custom_item_name',
        'quantity_requested',
    ];

    public function requisition()
    {
        return $this->belongsTo(Requisition::class, 'requisition_id', 'requisition_id');
    }

    public function supply()
    {
        return $this->belongsTo(InventorySupply::class, 'supply_id', 'supply_id');
    }

    public function itemDisplayName(): string
    {
        return $this->supply_id !== null
            ? ($this->supply->item_name ?? '—')
            : ($this->custom_item_name ?? '—');
    }

    public function isCustomItem(): bool
    {
        return $this->supply_id === null;
    }
}
