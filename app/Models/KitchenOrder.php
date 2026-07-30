<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KitchenOrder extends Model
{
    protected $table = 'kitchen_order';

    protected $fillable = [
        'posting_date',
        'document_no',
        'source_no',
        'invoice_line_id',
        'product_id',
        'item_code',
        'name',
        'variant',
        'category_name',
        'qty',
        'unit',
        'material_cost',
        'routing_cost',
        'fg_cost',
        'sell_price',
        'warehouse_id',
        'warehouse_name',
        'prepared_by',
        'created_by',
        'remark',
    ];

    protected $casts = [
        'posting_date'  => 'date',
        'qty'           => 'decimal:6',
        'material_cost' => 'decimal:6',
        'routing_cost'  => 'decimal:6',
        'fg_cost'       => 'decimal:6',
        'sell_price'    => 'decimal:6',
    ];

    public function lines()
    {
        return $this->hasMany(KitchenOrderLine::class, 'kitchen_order_id');
    }
}
