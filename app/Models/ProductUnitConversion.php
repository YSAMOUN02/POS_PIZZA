<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductUnitConversion extends Model
{
    protected $table = 'product_unit_conversions';

    protected $fillable = [
        'product_id',
        'unit_id',
        'factor',
        'created_by',
    ];

    protected $casts = [
        'factor' => 'decimal:6',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function unit()
    {
        return $this->belongsTo(UnitOfMeasure::class, 'unit_id');
    }
}
