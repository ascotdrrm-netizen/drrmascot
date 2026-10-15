<?php

namespace App\Models;

use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class InventorySupply extends Model
{
    use HasFactory, SoftDeletes, LogsActivity;

    protected $table = 'inventory_supplies';
    protected $primaryKey = 'supply_id';
    public $timestamps = false;

    protected $fillable = [
        'item_name',
        'category',
        'quantity_available',
        'reorder_level',
    ];

    public function requisitions()
    {
        return $this->hasMany(Requisition::class, 'supply_id', 'supply_id');
    }

    public function isBelowReorderLevel(): bool
    {
        return $this->quantity_available <= $this->reorder_level;
    }

    public function getActivityLogDescriptor(): string
    {
        return $this->item_name;
    }
}
