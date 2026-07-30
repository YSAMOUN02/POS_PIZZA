<?php

namespace App\Models;

use Exception;
use Illuminate\Database\Eloquent\Model;

class ProductRecipeLine extends Model
{
    public const TYPE_COMPONENT = 'component';
    public const TYPE_ADD_ON = 'add_on';

    protected $table = 'product_recipe_lines';

    protected $fillable = [
        'product_id',
        'raw_material_id',
        'line_type',      // component | add_on
        'addon_name',     // display name for add_on lines ("Add Mushroom", "Extra Mushroom")
        'quantity',
        'unit',           // display text (legacy + snapshot of the chosen unit code)
        'unit_id',        // structured unit the quantity was entered in
        'extra_price',    // charged when the add-on is chosen at sale time
        'created_by',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'extra_price' => 'decimal:2',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function rawMaterial()
    {
        return $this->belongsTo(Product::class, 'raw_material_id');
    }

    public function unitOfMeasure()
    {
        return $this->belongsTo(UnitOfMeasure::class, 'unit_id');
    }

    /**
     * Factor converting THIS line's quantity into the raw material's base unit.
     * Line entered in kg on a gram-based material → 1000. Same/no unit → 1.
     * Falls back to matching the legacy free-text unit against the material's
     * alternate-unit codes so pre-UoM recipes keep deducting correctly.
     */

public function baseUnitFactor(?Product $rawMaterial = null): float
{
    $rm = $rawMaterial ?? $this->rawMaterial;

    if (!$rm) {
        throw new Exception('Recipe raw material not found.');
    }


    /*
    |--------------------------------------------------------------------------
    | 1. Structured UOM (unit_id)
    |--------------------------------------------------------------------------
    */
    if ($this->unit_id) {

        // Same as product base unit
        if (
            $rm->base_unit_id &&
            (int) $this->unit_id === (int) $rm->base_unit_id
        ) {
            return 1.0;
        }


        // Check alternate conversion
        $factor = $rm->unitConversions()
            ->where('unit_id', $this->unit_id)
            ->value('factor');


        if ($factor !== null) {
            return (float) $factor;
        }
    }



    /*
    |--------------------------------------------------------------------------
    | 2. Legacy text unit (unit column)
    |--------------------------------------------------------------------------
    */
    if (!empty($this->unit)) {

        $unitCode = strtolower(trim($this->unit));


        // Example:
        // Material Base Unit = KG
        // Recipe Unit = kg
        if (
            $rm->baseUnit &&
            strtolower($rm->baseUnit->code) === $unitCode
        ) {
            return 1.0;
        }



        // Example:
        // Material Base Unit = gram
        // Recipe Unit = kg
        // Conversion kg = 1000
        $factor = $rm->unitConversions()
            ->whereHas('unit', function ($q) use ($unitCode) {
                $q->whereRaw(
                    'LOWER(code) = ?',
                    [$unitCode]
                );
            })
            ->value('factor');


        if ($factor !== null) {
            return (float) $factor;
        }
    }



    /*
    |--------------------------------------------------------------------------
    | 3. No conversion found
    |--------------------------------------------------------------------------
    */
    throw new Exception(
        "No UOM conversion found for {$rm->name}, unit: {$this->unit}"
    );
}
}
