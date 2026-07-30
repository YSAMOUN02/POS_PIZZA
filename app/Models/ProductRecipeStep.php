<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductRecipeStep extends Model
{
    protected $table = 'product_recipe_steps';

    protected $fillable = [
        'product_id',
        'step_no',
        'instruction',
        'cost',          // labour/operation cost of this step, per unit produced
        'created_by',
    ];

    protected $casts = [
        'step_no' => 'integer',
        'cost' => 'decimal:4',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
