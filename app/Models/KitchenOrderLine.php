<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KitchenOrderLine extends Model
{
    protected $table = 'kitchen_order_lines';

    protected $fillable = [
        'kitchen_order_id',
        'line_type',
        'raw_material_id',
        'item_code',
        'name',
        'qty',
        'unit',
        'lot',
        'warehouse_id',
        'unit_cost',
        'cost_amount',
    ];

    protected $casts = [
        'qty'         => 'decimal:6',
        'unit_cost'   => 'decimal:6',
        'cost_amount' => 'decimal:6',
    ];

    public function order()
    {
        return $this->belongsTo(KitchenOrder::class, 'kitchen_order_id');
    }
}
