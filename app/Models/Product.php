<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{

    protected string $activitySection = 'product';

    protected $table = 'product';
    protected $appends = ['stock'];
    // Mass assignable fields
    protected $fillable = [
        'category_id',
        'warehouse_id',
        'type',
        'bar_code',
        'code',
        'name',
        'variant',
        'sort_order',
        'description',
        'min_stock',
        'max_stock',
        'track_stock',
        'sell_price',
        'cost',
        'routing_cost',
        'vat',
        'discount_percent',
        'last_purchase_price',
        'allow_discount',
        'allow_return',
        'image',
         'category_name',
        'unit',
        'base_unit_id',
        'Tax',
        'status',
        'created_by',
    ];

    // Cast types for proper handling
    protected $casts = [
        'track_stock' => 'boolean',
        'allow_discount' => 'boolean',
        'allow_return' => 'boolean',
        // 3-state: 1 Enable (on sale), 2 Disable, 3 Under development. Int, not
        // boolean — boolean would collapse 2/3 back to 1 on save.
        'status' => 'integer',
        'sell_price' => 'decimal:2',
        'cost' => 'decimal:6',
        'routing_cost' => 'decimal:4',
        'vat' => 'decimal:2',
        'discount_percent' => 'decimal:2',
        'last_purchase_price' => 'decimal:2',
    ];



    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    // Bill of materials: raw materials consumed per 1 unit sold — only meaningful
    // for cooking_product rows. Typed: component (always consumed) vs add_on
    // (optional extra defined on this variant).
    public function recipeLines()
    {
        return $this->hasMany(ProductRecipeLine::class, 'product_id');
    }

    public function componentLines()
    {
        return $this->recipeLines()->where('line_type', ProductRecipeLine::TYPE_COMPONENT);
    }

    public function addOnLines()
    {
        return $this->recipeLines()->where('line_type', ProductRecipeLine::TYPE_ADD_ON);
    }

    // Ordered cooking routine for this variant (separate from the BOM).
    public function recipeSteps()
    {
        return $this->hasMany(ProductRecipeStep::class, 'product_id')->orderBy('step_no');
    }

    // Weighted average cost of on-hand stock (Σ qty×cost / Σ qty over positive
    // lots), per BASE unit. Falls back to the manual cost when nothing is on
    // hand. Display/reference only — the chef's manual `cost` stays authoritative.
    public function averageCost(): float
    {
        $lots = \Illuminate\Support\Facades\DB::table('warehouse_product')
            ->where('product_id', $this->id)
            ->where('quantity', '>', 0)
            ->selectRaw('COALESCE(SUM(quantity * cost), 0) as total_value, COALESCE(SUM(quantity), 0) as total_qty')
            ->first();

        if ($lots && (float) $lots->total_qty > 0 && (float) $lots->total_value > 0) {
            return round((float) $lots->total_value / (float) $lots->total_qty, 6);
        }

        return (float) ($this->cost ?? 0);
    }

    // The unit stock/recipes are tracked in (e.g. "g" for Mozzarella). Separate from
    // the free-text `unit` column, which stays purely for display/back-compat.
    public function baseUnit()
    {
        return $this->belongsTo(UnitOfMeasure::class, 'base_unit_id');
    }

    // Alternate units this product is commonly bought/sold in (e.g. "kg", 1000 = 1kg in grams).
    public function unitConversions()
    {
        return $this->hasMany(ProductUnitConversion::class, 'product_id');
    }

    // Converts a quantity given in an alternate unit into this product's base unit
    // (e.g. 2 kg of Mozzarella → 2000 g). Returns null if no conversion is defined
    // for that unit on this product.
    public function convertToBaseUnit(float $quantity, int $unitId): ?float
    {
        if ($this->base_unit_id && $unitId === $this->base_unit_id) {
            return $quantity;
        }
        $factor = $this->unitConversions()->where('unit_id', $unitId)->value('factor');
        return $factor !== null ? round($quantity * (float) $factor, 6) : null;
    }


    public function warehouses()
    {
        return $this->belongsToMany(
            Warehouse::class,    // Related model
            'warehouse_product'  // Pivot table name (exact table name!)
        )
        ->withPivot('quantity')
        ->withTimestamps();
    }
    public function getStockAttribute()
{
    return $this->warehouses->sum(function ($warehouse) {
        return $warehouse->pivot->quantity ?? 0;
    });
}

}
